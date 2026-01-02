<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

$applicationId = isset($data["application_id"]) ? intval($data["application_id"]) : 0;
$status = isset($data["status"]) ? strtolower(trim($data["status"])) : "";

$allowed = ["shortlisted", "rejected", "interviewed"];

if (!$applicationId || !in_array($status, $allowed)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid status or ID"]);
    exit;
}

// Check if the application exists
$checkStmt = $conn->prepare("SELECT id FROM applications WHERE id = ?");
$checkStmt->bind_param("i", $applicationId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Application not found"]);
    exit;
}

// Perform the update
$stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $applicationId);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Database update failed"]);
    exit;
}

echo json_encode([
    "success" => true,
    "application_id" => $applicationId,
    "status" => $status
]);
