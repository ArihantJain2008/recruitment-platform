<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include("../config/db.php");
require_once __DIR__ . "/../config/ai.php";
require_once __DIR__ . "/ranking_engine.php";

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || !isset($data["application_id"]) || !isset($data["resume_text"])) {
    echo json_encode(["error" => "Invalid input"]);
    exit;
}

$applicationId = intval($data["application_id"]);
$resumeTextRaw = trim((string)$data["resume_text"]);
$resumeText = strtolower($resumeTextRaw);

if ($applicationId <= 0 || $resumeText === "") {
    echo json_encode(["error" => "Application id and resume text are required"]);
    exit;
}

if (strlen($resumeText) < 10) {
    echo json_encode(["error" => "Please provide more details in your text"]);
    exit;
}

$columnResult = $conn->query("SHOW COLUMNS FROM jobs");
if (!$columnResult) {
    echo json_encode(["error" => "Could not read jobs schema"]);
    exit;
}

$jobColumns = [];
while ($column = $columnResult->fetch_assoc()) {
    $jobColumns[$column["Field"]] = true;
}

$skillsColumn = isset($jobColumns["skills_required"])
    ? "jobs.skills_required"
    : (isset($jobColumns["requirements"]) ? "jobs.requirements" : "''");

$experienceColumn = isset($jobColumns["experience_required"])
    ? "jobs.experience_required"
    : "NULL";

$query = "
    SELECT
      applications.id AS application_id,
      {$skillsColumn} AS required_skills,
      {$experienceColumn} AS required_experience
    FROM applications
    JOIN jobs ON jobs.id = applications.job_id
    WHERE applications.id = ?
";

$jobStmt = $conn->prepare($query);
if (!$jobStmt) {
    echo json_encode(["error" => "Database prepare failed: " . $conn->error]);
    exit;
}

$jobStmt->bind_param("i", $applicationId);
$jobStmt->execute();
$jobResult = $jobStmt->get_result();

if ($jobResult->num_rows === 0) {
    echo json_encode(["error" => "Application not found"]);
    exit;
}

$jobData = $jobResult->fetch_assoc();
$requiredSkillsText = strtolower(trim((string)($jobData["required_skills"] ?? "")));
$requiredExperience = $jobData["required_experience"];

$requiredSkills = parseSkills($requiredSkillsText);
$aiResult = aiEvaluateCandidateText($requiredSkills, $requiredExperience, $resumeText);
$aiDebug = aiGetLastError();

$matchedSkills = [];
$score = 0;
$aiUsed = 0;
$aiModel = null;
$aiFeedback = null;

if (is_array($aiResult) && isset($aiResult["score"])) {
    $aiUsed = 1;
    $aiModel = (string)($aiResult["model"] ?? "");
    $score = max(0, min(100, intval($aiResult["score"])));

    $aiMatched = aiNormalizeSkills($aiResult["matched_skills"] ?? []);
    if (!empty($requiredSkills)) {
        $requiredLookup = array_flip($requiredSkills);
        foreach ($aiMatched as $skill) {
            if (isset($requiredLookup[$skill])) {
                $matchedSkills[] = $skill;
            }
        }
    } else {
        $matchedSkills = $aiMatched;
    }

    $summary = trim((string)($aiResult["summary"] ?? ""));
    $missing = aiNormalizeSkills($aiResult["missing_skills"] ?? []);
    if (!empty($missing)) {
        $summary .= ($summary !== "" ? " " : "") . "Missing skills: " . implode(", ", $missing) . ".";
    }
    $aiFeedback = $summary !== "" ? $summary : null;
} else {
    foreach ($requiredSkills as $skill) {
        if (textContainsSkill($resumeText, $skill)) {
            $matchedSkills[] = $skill;
        }
    }

    $requiredCount = count($requiredSkills);
    $matchedCount = count($matchedSkills);

    if ($requiredCount > 0) {
        $score += (int)round(($matchedCount / $requiredCount) * 85);
    } else {
        $genericKeywords = ["html", "css", "javascript", "react", "sql", "python"];
        $hits = 0;

        foreach ($genericKeywords as $word) {
            if (textContainsSkill($resumeText, $word)) {
                $hits++;
            }
        }

        $score += min($hits * 12, 70);
    }

    $textLength = strlen($resumeText);
    if ($textLength >= 80) {
        $score += 10;
    } elseif ($textLength >= 30) {
        $score += 5;
    }

    $requiredYears = is_numeric($requiredExperience) ? intval($requiredExperience) : 0;
    $candidateYears = extractYearsFromText($resumeText);

    if ($requiredYears > 0) {
        if ($candidateYears >= $requiredYears) {
            $score += 5;
        } elseif ($candidateYears > 0 && ($requiredYears - $candidateYears) <= 1) {
            $score += 3;
        }
    } elseif ($candidateYears > 0) {
        $score += 5;
    }

    if ($score > 100) {
        $score = 100;
    }

    if ($score < 0) {
        $score = 0;
    }
}

$requiredCount = count($requiredSkills);
$matchedCount = count($matchedSkills);

$hasAiColumns = ensureAiColumns($conn);
$hasResumeSummaryColumn = ensureApplicationsResumeSummaryColumn($conn);
$resumeSummary = buildResumeSummary($resumeTextRaw, $aiResult, $aiFeedback);

if ($hasAiColumns && $hasResumeSummaryColumn) {
    $stmt = $conn->prepare("UPDATE applications SET score = ?, ai_feedback = ?, ai_model = ?, ai_used = ?, resume_summary = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["error" => "Failed to prepare score update"]);
        exit;
    }

    $stmt->bind_param("issisi", $score, $aiFeedback, $aiModel, $aiUsed, $resumeSummary, $applicationId);
} elseif ($hasAiColumns) {
    $stmt = $conn->prepare("UPDATE applications SET score = ?, ai_feedback = ?, ai_model = ?, ai_used = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["error" => "Failed to prepare score update"]);
        exit;
    }

    $stmt->bind_param("issii", $score, $aiFeedback, $aiModel, $aiUsed, $applicationId);
} elseif ($hasResumeSummaryColumn) {
    $stmt = $conn->prepare("UPDATE applications SET score = ?, resume_summary = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["error" => "Failed to prepare score update"]);
        exit;
    }

    $stmt->bind_param("isi", $score, $resumeSummary, $applicationId);
} else {
    $stmt = $conn->prepare("UPDATE applications SET score = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["error" => "Failed to prepare score update"]);
        exit;
    }

    $stmt->bind_param("ii", $score, $applicationId);
}

if (!$stmt->execute()) {
    echo json_encode(["error" => "Failed to update score"]);
    exit;
}

echo json_encode([
    "success" => true,
    "application_id" => $applicationId,
    "score" => $score,
    "required_skills" => $requiredSkills,
    "matched_skills" => $matchedSkills,
    "match_percent" => $requiredCount > 0 ? (int)round(($matchedCount / $requiredCount) * 100) : null,
    "ai_used" => $aiUsed === 1,
    "ai_feedback" => $aiFeedback,
    "ai_model" => $aiModel,
    "resume_summary" => $resumeSummary,
    "ai_provider" => aiGetEnv("AI_PROVIDER", ""),
    "ai_debug" => $aiUsed === 1 ? null : $aiDebug
]);

function parseSkills($skillsText) {
    if ($skillsText === "") {
        return [];
    }

    $parts = preg_split('/[,;|\/\n\r]+/', $skillsText);
    $skills = [];

    foreach ($parts as $part) {
        $skill = normalizeSkill($part);
        if ($skill === "") {
            continue;
        }

        if (strlen($skill) < 2) {
            continue;
        }

        $skills[$skill] = true;
    }

    return array_keys($skills);
}

function normalizeSkill($text) {
    $value = strtolower(trim((string)$text));
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function textContainsSkill($resumeText, $skill) {
    $safeSkill = trim((string)$skill);
    if ($safeSkill === "") {
        return false;
    }

    $pattern = '/\b' . preg_quote($safeSkill, '/') . '\b/i';
    if (@preg_match($pattern, $resumeText)) {
        if (preg_match($pattern, $resumeText) === 1) {
            return true;
        }
    }

    return strpos($resumeText, $safeSkill) !== false;
}

function extractYearsFromText($resumeText) {
    if (preg_match_all('/(\d+)\s*\+?\s*(year|years|yr|yrs)/i', $resumeText, $matches)) {
        $years = array_map('intval', $matches[1]);
        if (!empty($years)) {
            return max($years);
        }
    }

    return 0;
}

function ensureAiColumns($conn) {
    $result = $conn->query("SHOW COLUMNS FROM applications");
    if (!$result) {
        return false;
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row["Field"]] = true;
    }

    $changes = [];
    if (!isset($columns["ai_feedback"])) {
        $changes[] = "ADD COLUMN ai_feedback TEXT NULL";
    }
    if (!isset($columns["ai_model"])) {
        $changes[] = "ADD COLUMN ai_model VARCHAR(100) NULL";
    }
    if (!isset($columns["ai_used"])) {
        $changes[] = "ADD COLUMN ai_used TINYINT(1) NOT NULL DEFAULT 0";
    }

    if (empty($changes)) {
        return true;
    }

    $sql = "ALTER TABLE applications " . implode(", ", $changes);
    return (bool)$conn->query($sql);
}

function buildResumeSummary($resumeTextRaw, $aiResult, $aiFeedback) {
    $modelSummary = is_array($aiResult) ? trim((string)($aiResult["summary"] ?? "")) : "";
    if ($modelSummary !== "") {
        return $modelSummary;
    }

    $feedbackSummary = trim((string)$aiFeedback);
    if ($feedbackSummary !== "") {
        return $feedbackSummary;
    }

    $cleanText = preg_replace('/\s+/', ' ', trim((string)$resumeTextRaw));
    if ($cleanText === "") {
        return "";
    }

    if (strlen($cleanText) > 900) {
        return trim(substr($cleanText, 0, 900)) . "...";
    }

    return $cleanText;
}
