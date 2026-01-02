<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

if(!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid Input"]);
    exit;
}

$job_id = intval($data['id']);
$title = isset($data['title']) ? trim($data['title']) : '';
$description = isset($data['description']) ? trim($data['description']) : '';
$skills = isset($data['skills_required']) ? trim($data['skills_required']) : '';
$experience = isset($data['experience_required']) ? trim($data['experience_required']) : '';

// Check if job exists
$checkStmt = $conn->prepare("SELECT id FROM jobs WHERE id = ?");
$checkStmt->bind_param("i", $job_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Job not found"]);
    exit;
}

$stmt = $conn->prepare(
    "UPDATE jobs SET title = ?, description = ?, skills_required = ?, experience_required = ? WHERE id = ?"
);
$stmt->bind_param("ssssi", $title, $description, $skills, $experience, $job_id);

if($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Job Updated"
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Job Update Failed: " . $stmt->error]);
}

