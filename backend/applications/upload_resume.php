<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../config/db.php");

// ---------- VALIDATION ----------
if (!isset($_POST["application_id"]) || !isset($_FILES["resume"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing application_id or resume"]);
    exit;
}

$applicationId = intval($_POST["application_id"]);

// ---------- UPLOAD DIRECTORY ----------
$uploadDir = "../uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// ---------- FILE HANDLING ----------
$originalName = basename($_FILES["resume"]["name"]);
$extension = pathinfo($originalName, PATHINFO_EXTENSION);

// optional safety check
if (strtolower($extension) !== "pdf") {
    http_response_code(400);
    echo json_encode(["error" => "Only PDF files allowed"]);
    exit;
}

$fileName = time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);
$filePath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES["resume"]["tmp_name"], $filePath)) {
    http_response_code(500);
    echo json_encode(["error" => "File upload failed"]);
    exit;
}

// ---------- DB UPDATE (ONLY PATH) ----------
$stmt = $conn->prepare(
    "UPDATE applications SET resume_path = ? WHERE id = ?"
);
$stmt->bind_param("si", $fileName, $applicationId);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    http_response_code(500);
    echo json_encode([
        "error" => "Resume path not updated",
        "application_id" => $applicationId
    ]);
    exit;
}

// ---------- SUCCESS ----------
echo json_encode([
    "message" => "Resume uploaded successfully",
    "resume_path" => $fileName
]);
