<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../config/db.php");

$jobs = [];

try {
    $result = $conn->query("SELECT * FROM jobs ORDER BY created_at DESC");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching jobs: " . $e->getMessage());
}

echo json_encode($jobs);