<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../config/db.php";

$columnResult = $conn->query("SHOW COLUMNS FROM jobs");
if (!$columnResult) {
  http_response_code(500);
  echo json_encode(["error" => "Failed to read jobs schema: " . $conn->error]);
  exit;
}

$columns = [];
while ($column = $columnResult->fetch_assoc()) {
  $columns[$column["Field"]] = true;
}

$selectParts = [];
$selectParts[] = "id";
$selectParts[] = isset($columns["title"]) ? "title" : "'' AS title";
$selectParts[] = isset($columns["description"]) ? "description" : "'' AS description";
$selectParts[] = isset($columns["skills_required"])
  ? "skills_required"
  : (isset($columns["requirements"]) ? "requirements AS skills_required" : "'' AS skills_required");
$selectParts[] = isset($columns["experience_required"])
  ? "experience_required"
  : "NULL AS experience_required";
$selectParts[] = isset($columns["status"]) ? "status" : "NULL AS status";

$query = "SELECT " . implode(", ", $selectParts) . " FROM jobs ORDER BY id DESC";
$result = $conn->query($query);
if (!$result) {
  http_response_code(500);
  echo json_encode(["error" => "Failed to fetch jobs: " . $conn->error]);
  exit;
}

$jobs = [];
while ($row = $result->fetch_assoc()) {
  $jobs[] = $row;
}

echo json_encode($jobs);
