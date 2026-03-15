<?php
header("Content-Type: application/json");
include("../config/db.php");

$candidateId = intval($_GET["candidate_id"] ?? 0);

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
$selectParts[] = "applications.job_id";
$selectParts[] = "applications.candidate_id";
$selectParts[] = "applications.status";
$selectParts[] = "applications.score";
$selectParts[] = "jobs.title";
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

$stmt = $conn->prepare("
  SELECT
    " . implode(",\n    ", $selectParts) . "
  FROM applications
  LEFT JOIN jobs ON jobs.id = applications.job_id
  WHERE applications.candidate_id = ?
  ORDER BY applications.applied_at DESC
");

$stmt->bind_param("i", $candidateId);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
