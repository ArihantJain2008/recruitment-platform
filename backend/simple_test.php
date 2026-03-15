<?php
// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers first
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Disable all error reporting
error_reporting(0);
ini_set('display_errors', 0);

try {
    $conn = new mysqli("localhost", "root", "", "recruitment_db");
    
    if ($conn->connect_error) {
        echo json_encode(["error" => "Database connection failed"]);
        exit;
    }
    
    echo json_encode(["status" => "success", "message" => "Database connected"]);
    
} catch (Exception $e) {
    echo json_encode(["error" => "Exception: " . $e->getMessage()]);
}
?>
