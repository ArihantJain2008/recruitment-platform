<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

if(!$data || !isset($data['recruiter_id']) || !isset($data['title'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid Input"]);
    exit;
}

$recruiter_id = intval($data['recruiter_id']);
$title = trim($data['title']);
$description = isset($data['description']) ? trim($data['description']) : '';
$skills = isset($data['skills_required']) ? trim($data['skills_required']) : '';
$experience = isset($data['experience_required']) ? trim($data['experience_required']) : '';

$stmt = $conn->prepare(
    "INSERT INTO jobs (recruiter_id, title, description, skills_required, experience_required) VALUES (?,?,?,?,?)"
);
$stmt->bind_param("issss", $recruiter_id, $title, $description, $skills, $experience);

if($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Job Created",
        "job_id" => $stmt->insert_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Job Creation Failed: " . $stmt->error]);
}
