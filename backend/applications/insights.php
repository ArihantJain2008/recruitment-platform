<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/ranking_engine.php";

$jobId = isset($_GET["job_id"]) ? intval($_GET["job_id"]) : 0;

// Ensure schema exists so this endpoint can read ranking data safely.
ensureCandidateRankingSchema($conn);

$insights = loadInsightsFromCandidateScores($conn, $jobId);

if ($insights["total_candidates"] === 0) {
    $insights = loadInsightsFromApplications($conn, $jobId);
}

echo json_encode($insights);

function loadInsightsFromCandidateScores($conn, $jobId) {
    if (!tableExists($conn, "candidate_scores")) {
        return emptyInsights();
    }

    $sql = "
        SELECT
            candidate_scores.score,
            candidate_scores.matched_keywords,
            users.name AS candidate_name
        FROM candidate_scores
        LEFT JOIN users ON users.id = candidate_scores.candidate_id
    ";

    $params = [];
    $types = "";
    if ($jobId > 0) {
        $sql .= " WHERE candidate_scores.job_id = ?";
        $types = "i";
        $params[] = $jobId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return emptyInsights();
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    return buildInsightsFromRows($result, "matched_keywords");
}

function loadInsightsFromApplications($conn, $jobId) {
    $jobColumns = tableColumns($conn, "jobs");
    $skillsExpr = "'' AS job_skills";
    if (isset($jobColumns["skills_required"])) {
        $skillsExpr = "jobs.skills_required AS job_skills";
    } elseif (isset($jobColumns["requirements"])) {
        $skillsExpr = "jobs.requirements AS job_skills";
    }

    $sql = "
        SELECT
            applications.score,
            users.name AS candidate_name,
            {$skillsExpr}
        FROM applications
        LEFT JOIN users ON users.id = applications.candidate_id
        LEFT JOIN jobs ON jobs.id = applications.job_id
    ";

    $params = [];
    $types = "";
    if ($jobId > 0) {
        $sql .= " WHERE applications.job_id = ?";
        $types = "i";
        $params[] = $jobId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return emptyInsights();
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    return buildInsightsFromRows($result, "job_skills");
}

function buildInsightsFromRows($result, $skillField) {
    if (!$result) {
        return emptyInsights();
    }

    $totalCandidates = 0;
    $scoreTotal = 0.0;
    $topScore = -1;
    $topCandidateName = "";
    $skillsCount = [];

    while ($row = $result->fetch_assoc()) {
        $score = is_numeric($row["score"] ?? null) ? floatval($row["score"]) : 0.0;
        $candidateName = trim((string)($row["candidate_name"] ?? ""));
        $skills = parseSkillsList((string)($row[$skillField] ?? ""));

        foreach ($skills as $skill) {
            if (!isset($skillsCount[$skill])) {
                $skillsCount[$skill] = 0;
            }
            $skillsCount[$skill]++;
        }

        $totalCandidates++;
        $scoreTotal += $score;

        if ($score > $topScore) {
            $topScore = $score;
            $topCandidateName = $candidateName;
        }
    }

    if ($totalCandidates === 0) {
        return emptyInsights();
    }

    arsort($skillsCount);
    $mostCommonSkill = !empty($skillsCount) ? (string)array_key_first($skillsCount) : "No skill data";

    return [
        "average_score" => round($scoreTotal / $totalCandidates, 2),
        "most_common_skill" => $mostCommonSkill,
        "top_candidate_name" => $topCandidateName !== "" ? $topCandidateName : "No candidate data",
        "top_candidate_score" => $topScore >= 0 ? round($topScore, 2) : 0,
        "total_candidates" => $totalCandidates
    ];
}

function parseSkillsList($raw) {
    if (trim($raw) === "") {
        return [];
    }

    $parts = preg_split('/[,;|\/\n\r]+/', strtolower($raw));
    $unique = [];

    foreach ($parts as $part) {
        $clean = trim(preg_replace('/\s+/', ' ', $part));
        if ($clean === "" || strlen($clean) < 2) {
            continue;
        }
        $unique[$clean] = true;
    }

    return array_keys($unique);
}

function tableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    if ($safe === "") {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function tableColumns($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    if ($safe === "") {
        return [];
    }

    $result = $conn->query("SHOW COLUMNS FROM {$safe}");
    if (!$result) {
        return [];
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row["Field"]] = true;
    }

    return $columns;
}

function emptyInsights() {
    return [
        "average_score" => 0,
        "most_common_skill" => "No skill data",
        "top_candidate_name" => "No candidate data",
        "top_candidate_score" => 0,
        "total_candidates" => 0
    ];
}
