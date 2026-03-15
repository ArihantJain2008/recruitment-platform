<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../config/db.php";

$jobId = isset($_GET["job_id"]) ? intval($_GET["job_id"]) : 0;

if ($jobId <= 0) {
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

$selectParts = [];
$selectParts[] = "applications.id AS application_id";
$selectParts[] = "applications.candidate_id";
$selectParts[] = "users.name";
$selectParts[] = "users.email";
$selectParts[] = "applications.score";
$selectParts[] = "applications.status";
$selectParts[] = "applications.resume_path";
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
    $applicants[] = $row;
}

echo json_encode($applicants);
