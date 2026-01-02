<?php
header("Content-Type: application/json");
include("../config/db.php");

$candidateId = intval($_GET["candidate_id"] ?? 0);

$stmt = $conn->prepare("
  SELECT
    applications.id AS application_id,
    applications.job_id,
    applications.candidate_id,
    applications.status,
    applications.score,
    jobs.title
  FROM applications
  LEFT JOIN jobs ON jobs.id = applications.job_id
  WHERE applications.candidate_id = ?
");

$stmt->bind_param("i", $candidateId);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
