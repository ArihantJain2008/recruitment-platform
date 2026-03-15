<?php
// Start output buffering to catch any errors
ob_start();

// Set headers first
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Enable error reporting temporarily for debugging, but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . "/../config/db.php";
    
    // Check database connection
    if (!isset($conn)) {
        throw new Exception("Database connection object not created");
    }
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

if ($email === "" || $password === "") {
    echo json_encode(["error" => "Email and password required"]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, name, email, password, role FROM users WHERE email = ?"
    );

    if (!$stmt) {
        throw new Exception("DB prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }

    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        ob_clean();
        echo json_encode(["error" => "Invalid credentials"]);
        exit;
    }

    $stmt->bind_result($id, $name, $email_db, $password_hash, $role);
    $stmt->fetch();

    if (!password_verify($password, $password_hash)) {
        ob_clean();
        echo json_encode(["error" => "Invalid credentials"]);
        exit;
    }

    ob_clean();
    echo json_encode([
        "id" => $id,
        "name" => $name,
        "email" => $email_db,
        "role" => $role
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(["error" => "Login failed: " . $e->getMessage()]);
    exit;
}
