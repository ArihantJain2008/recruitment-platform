<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/ranking_engine.php";

$jobId = isset($_GET["job_id"]) ? intval($_GET["job_id"]) : 0;
$jobDescriptionInput = trim((string)($_GET["job_description"] ?? ""));

if ($jobId <= 0) {
    echo json_encode([]);
    exit;
}

$rankedCandidates = rankCandidatesForJob($conn, $jobId, $jobDescriptionInput);
$rankingByCandidate = [];
foreach ($rankedCandidates as $rankedRow) {
    $candidateKey = intval($rankedRow["candidate_id"] ?? 0);
    if ($candidateKey > 0) {
        $rankingByCandidate[$candidateKey] = $rankedRow;
    }
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

$selectParts = [];
$selectParts[] = "applications.id AS application_id";
$selectParts[] = "applications.candidate_id";
$selectParts[] = "users.name";
$selectParts[] = "users.email";
$selectParts[] = "applications.score";
$selectParts[] = "applications.status";
$selectParts[] = "applications.resume_path";
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

$sql = "
  SELECT
    " . implode(",\n    ", $selectParts) . "
  FROM applications
  JOIN users ON users.id = applications.candidate_id
  WHERE applications.job_id = ?
  ORDER BY applications.applied_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["error" => "Query prepare failed"]);
    exit;
}

$stmt->bind_param("i", $jobId);
$stmt->execute();

$result = $stmt->get_result();

$applicants = [];
while ($row = $result->fetch_assoc()) {
    $candidateId = intval($row["candidate_id"] ?? 0);
    $rankData = $rankingByCandidate[$candidateId] ?? null;

    if (is_array($rankData)) {
        $row["score"] = intval($rankData["score"]);
        $row["rank"] = intval($rankData["rank"]);
        $row["matched_keywords"] = (string)($rankData["matched_keywords_text"] ?? "");
        $row["match_feedback"] = (string)($rankData["match_feedback"] ?? rankingFeedbackLabel($row["score"]));
        $row["keyword_overlap"] = intval($rankData["keyword_overlap"] ?? 0);
        $row["total_keywords"] = intval($rankData["total_keywords"] ?? 0);
        $row["is_top_3"] = intval($rankData["is_top_3"] ?? 0);
    } else {
        $score = is_numeric($row["score"]) ? intval(round(floatval($row["score"]))) : 0;
        $row["score"] = $score;
        $row["rank"] = null;
        $row["matched_keywords"] = "";
        $row["match_feedback"] = rankingFeedbackLabel($score);
        $row["keyword_overlap"] = 0;
        $row["total_keywords"] = 0;
        $row["is_top_3"] = 0;
    }

    $applicants[] = $row;
}

usort($applicants, function ($left, $right) {
    $leftRank = isset($left["rank"]) ? intval($left["rank"]) : 0;
    $rightRank = isset($right["rank"]) ? intval($right["rank"]) : 0;

    if ($leftRank > 0 && $rightRank > 0 && $leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }

    if ($leftRank > 0 && $rightRank <= 0) {
        return -1;
    }

    if ($rightRank > 0 && $leftRank <= 0) {
        return 1;
    }

    $leftScore = is_numeric($left["score"] ?? null) ? floatval($left["score"]) : 0.0;
    $rightScore = is_numeric($right["score"] ?? null) ? floatval($right["score"]) : 0.0;
    $scoreCompare = $rightScore <=> $leftScore;
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    return strcmp((string)($left["name"] ?? ""), (string)($right["name"] ?? ""));
});

echo json_encode($applicants);
