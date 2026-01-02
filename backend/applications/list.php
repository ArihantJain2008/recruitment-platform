<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include("../config/db.php");

$jobId = intval($_GET["job_id"] ?? 0);

if (!$jobId) {
  echo json_encode([]);
  exit;
}

$stmt = $conn->prepare(
  "SELECT
     applications.id AS application_id,
     users.name,
     users.email,
     applications.score,
     applications.status
   FROM applications
   JOIN users ON users.id = applications.candidate_id
   WHERE applications.job_id = ?"
);

$stmt->bind_param("i", $jobId);
$stmt->execute();

$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

echo json_encode($data);
