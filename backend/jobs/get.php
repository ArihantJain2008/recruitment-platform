<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../config/db.php");

if (!isset($_GET["id"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing job id"]);
    exit;
}

$job_id = intval($_GET["id"]);

$stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ?");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Job not found"]);
    exit;
}

$job = $result->fetch_assoc();
echo json_encode($job);

