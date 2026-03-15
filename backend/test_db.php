<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "recruitment_db");

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => $conn->connect_error
    ]);
} else {
    // Check if users table exists
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result->num_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Database connected and users table exists"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Users table does not exist"
        ]);
    }
}
?>
