<?php
// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers first
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Disable all error reporting
error_reporting(0);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/../config/db.php";

// Check database connection
if (isset($conn) && $conn->connect_error) {
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

if (!isset($conn)) {
    echo json_encode(["error" => "Database connection failed: Connection object not created"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["error" => "Invalid request body"]);
    exit();
}

$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = strtolower(trim($data['role'] ?? ''));

if ($name === '' || $email === '' || $password === '' || $role === '') {
    echo json_encode(["error" => "Missing fields"]);
    exit();
}

// Check if email already exists
$check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
if (!$check_stmt) {
    echo json_encode(["error" => "Database prepare failed"]);
    exit();
}

$check_stmt->bind_param("s", $email);
$check_stmt->execute();
$check_stmt->store_result();

if ($check_stmt->num_rows > 0) {
    echo json_encode(["error" => "Email already registered"]);
    exit();
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)"
);
if (!$stmt) {
    echo json_encode(["error" => "Database prepare failed: " . $conn->error]);
    exit();
}
$stmt->bind_param("ssss", $name, $email, $hashed, $role);

if ($stmt->execute()) {
    echo json_encode(["message" => "Registered successfully"]);
} else {
    echo json_encode(["error" => "Registration failed: " . $conn->error]);
}
