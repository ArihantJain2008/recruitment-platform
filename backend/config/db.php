<?php
ini_set('display_errors', 0);
error_reporting(0);

$conn = new mysqli("localhost", "root", "", "recrutiment_db"); //the database name is recrutiment_db in sql so dont change it again !!!!!

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit;
}
