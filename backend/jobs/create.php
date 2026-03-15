<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

if(!$data || !isset($data['title'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid Input"]);
    exit;
}

$title = trim($data['title']);
$recruiter_id = intval($data['recruiter_id'] ?? 0);
$description = isset($data['description']) ? trim($data['description']) : '';
$skills = isset($data['skills_required']) ? trim($data['skills_required']) : '';

$experience = null;
if (isset($data['experience_required']) && $data['experience_required'] !== null && $data['experience_required'] !== '') {
    $rawExperience = (string)$data['experience_required'];
    if (is_numeric($rawExperience)) {
        $experience = (string)max(0, intval($rawExperience));
    } elseif (preg_match('/\d+/', $rawExperience, $matches)) {
        $experience = (string)intval($matches[0]);
    }
}

if ($recruiter_id <= 0 || $title === '') {
    http_response_code(400);
    echo json_encode(["error" => "Recruiter ID and title are required"]);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO jobs (recruiter_id, title, description, skills_required, experience_required) VALUES (?,?,?,?,?)"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Database prepare failed: " . $conn->error]);
    exit;
}
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
