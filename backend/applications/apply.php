<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["job_id"]) || !isset($data["candidate_id"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing job_id or candidate_id"]);
    exit;
}

$job_id = intval($data["job_id"]);
$candidate_id = intval($data["candidate_id"]);

// Check if already applied
$checkStmt = $conn->prepare("SELECT id FROM applications WHERE job_id = ? AND candidate_id = ?");
$checkStmt->bind_param("ii", $job_id, $candidate_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "You have already applied to this job"]);
    exit;
}

$stmt = $conn->prepare(
  "INSERT INTO applications (job_id, candidate_id, status)
   VALUES (?, ?, 'applied')"
);

$stmt->bind_param("ii", $job_id, $candidate_id);

if ($stmt->execute()) {
    echo json_encode([
        "message" => "Applied",
        "success" => true,
        "candidate_id" => $candidate_id,
        "job_id" => $job_id,
        "application_id" => $stmt->insert_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to submit application"]);
}
