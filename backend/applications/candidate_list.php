<?php
header("Content-Type: application/json");
include("../config/db.php");
require_once __DIR__ . "/ranking_engine.php";

$candidateId = intval($_GET["candidate_id"] ?? 0);
$jobId = intval($_GET["job_id"] ?? 0);
$jobDescriptionInput = trim((string)($_GET["job_description"] ?? ""));

// Recruiter-friendly ranking mode:
// /candidate_list.php?job_id=123&job_description=...
if ($jobId > 0) {
    $ranked = rankCandidatesForJob($conn, $jobId, $jobDescriptionInput);
    echo json_encode($ranked);
    exit;
}

if ($candidateId <= 0) {
    echo json_encode([]);
    exit;
}

$columnResult = $conn->query("SHOW COLUMNS FROM applications");
if (!$columnResult) {
    echo json_encode(["error" => "Failed to read applications schema"]);
    exit;
}

$columns = [];
while ($column = $columnResult->fetch_assoc()) {
    $columns[$column["Field"]] = true;
}

$jobColumnResult = $conn->query("SHOW COLUMNS FROM jobs");
$jobColumns = [];
if ($jobColumnResult) {
    while ($column = $jobColumnResult->fetch_assoc()) {
        $jobColumns[$column["Field"]] = true;
    }
}

$jobSkillsExpr = isset($jobColumns["skills_required"])
    ? "jobs.skills_required AS job_skills"
    : (isset($jobColumns["requirements"]) ? "jobs.requirements AS job_skills" : "NULL AS job_skills");

$hasRankingSchema = ensureCandidateRankingSchema($conn);

$selectParts = [];
$selectParts[] = "applications.id AS application_id";
$selectParts[] = "applications.job_id";
$selectParts[] = "applications.candidate_id";
$selectParts[] = "applications.status";
$selectParts[] = "applications.score";
$selectParts[] = "jobs.title";
$selectParts[] = $jobSkillsExpr;
$selectParts[] = $hasRankingSchema ? "candidate_scores.score AS ranking_score" : "NULL AS ranking_score";
$selectParts[] = $hasRankingSchema ? "candidate_scores.`rank` AS ranking_rank" : "NULL AS ranking_rank";
$selectParts[] = $hasRankingSchema ? "candidate_scores.matched_keywords AS matched_keywords" : "NULL AS matched_keywords";
$selectParts[] = $hasRankingSchema ? "candidate_scores.feedback AS match_feedback" : "NULL AS match_feedback";
$selectParts[] = isset($columns["ai_feedback"]) ? "applications.ai_feedback" : "NULL AS ai_feedback";
$selectParts[] = isset($columns["ai_model"]) ? "applications.ai_model" : "NULL AS ai_model";
$selectParts[] = isset($columns["ai_used"]) ? "applications.ai_used" : "0 AS ai_used";
$selectParts[] = isset($columns["interview_time"]) ? "applications.interview_time" : "NULL AS interview_time";
$selectParts[] = isset($columns["interview_timezone"]) ? "applications.interview_timezone" : "NULL AS interview_timezone";
$selectParts[] = isset($columns["interview_duration_minutes"])
    ? "applications.interview_duration_minutes"
    : "NULL AS interview_duration_minutes";
$selectParts[] = isset($columns["interview_meet_link"]) ? "applications.interview_meet_link" : "NULL AS interview_meet_link";
$selectParts[] = isset($columns["interview_calendar_link"])
    ? "applications.interview_calendar_link"
    : "NULL AS interview_calendar_link";
$selectParts[] = isset($columns["interview_note"]) ? "applications.interview_note" : "NULL AS interview_note";
$selectParts[] = isset($columns["notified_at"]) ? "applications.notified_at" : "NULL AS notified_at";

$joins = "
  FROM applications
  LEFT JOIN jobs ON jobs.id = applications.job_id
";

if ($hasRankingSchema) {
    $joins .= "
  LEFT JOIN candidate_scores
    ON candidate_scores.candidate_id = applications.candidate_id
   AND candidate_scores.job_id = applications.job_id
";
}

$orderBy = $hasRankingSchema
    ? "ORDER BY CASE WHEN candidate_scores.`rank` IS NULL THEN 1 ELSE 0 END, candidate_scores.`rank` ASC, applications.applied_at DESC"
    : "ORDER BY applications.applied_at DESC";

$stmt = $conn->prepare("
  SELECT
    " . implode(",\n    ", $selectParts) . "
  {$joins}
  WHERE applications.candidate_id = ?
  {$orderBy}
");

if (!$stmt) {
    echo json_encode(["error" => "Failed to prepare candidate application query"]);
    exit;
}

$stmt->bind_param("i", $candidateId);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $rank = isset($row["ranking_rank"]) ? intval($row["ranking_rank"]) : 0;
    $finalScore = isset($row["ranking_score"]) && $row["ranking_score"] !== null
        ? intval(round(floatval($row["ranking_score"])))
        : (is_numeric($row["score"] ?? null) ? intval(round(floatval($row["score"]))) : 0);
    $matchedKeywordsList = parseKeywordList((string)($row["matched_keywords"] ?? ""));
    $requiredSkillsList = parseKeywordList((string)($row["job_skills"] ?? ""));
    $missingSkills = [];

    if (!empty($requiredSkillsList)) {
        $matchedLookup = array_flip($matchedKeywordsList);
        foreach ($requiredSkillsList as $skill) {
            if (!isset($matchedLookup[$skill])) {
                $missingSkills[] = $skill;
            }
        }
    }

    $row["score"] = $finalScore;
    $row["rank"] = $rank > 0 ? $rank : null;
    $row["is_top_3"] = ($rank > 0 && $rank <= 3) ? 1 : 0;
    $row["match_feedback"] = trim((string)($row["match_feedback"] ?? "")) !== ""
        ? (string)$row["match_feedback"]
        : rankingFeedbackLabel($finalScore);
    $row["matched_keywords"] = implode(", ", $matchedKeywordsList);
    $row["missing_skills"] = implode(", ", $missingSkills);

    $data[] = $row;
}

echo json_encode($data);

function parseKeywordList($text) {
    $text = trim((string)$text);
    if ($text === "") {
        return [];
    }

    $parts = preg_split('/[,;|\/\n\r]+/', strtolower($text));
    $keywords = [];

    foreach ($parts as $part) {
        $clean = trim(preg_replace('/\s+/', ' ', $part));
        if ($clean === "" || strlen($clean) < 2) {
            continue;
        }
        $keywords[$clean] = true;
    }

    return array_keys($keywords);
}
