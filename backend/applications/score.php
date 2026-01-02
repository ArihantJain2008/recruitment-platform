<?php
header("Content-Type: application/json");
include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["application_id"], $data["resume_text"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing data"]);
    exit;
}

$applicationId = intval($data["application_id"]);
$resumeText = strtolower($data["resume_text"]);

$score = 0;

// 1. Baseline
$score += 20;

// 2. Length (NOW RELIABLE)
$textLength = strlen($resumeText);

if ($textLength > 500)  $score += 15;
if ($textLength > 1500) $score += 15;

// 3. Keyword density
$keywords = ['html', 'css', 'javascript', 'react', 'sql', 'python'];
$hits = 0;

foreach ($keywords as $word) {
    if (strpos($resumeText, $word) !== false) {
        $hits++;
    }
}

$score += min($hits * 10, 40);

// 4. Experience
if (preg_match('/\d+\s*(year|years)/', $resumeText)) {
    $score += 20;
}

if ($score > 100) $score = 100;

// Save
$stmt = $conn->prepare(
  "UPDATE applications SET score=? WHERE id=?"
);
$stmt->bind_param("ii", $score, $applicationId);
$stmt->execute();

echo json_encode([
  "message" => "Score calculated",
  "score" => $score
]);
