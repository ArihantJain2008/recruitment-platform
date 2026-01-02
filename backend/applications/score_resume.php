<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include("../config/db.php");

// Read input safely
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || !isset($data["application_id"]) || !isset($data["resume_text"])) {
    echo json_encode(["error" => "Invalid input"]);
    exit;
}

$applicationId = intval($data["application_id"]);
$resumeText = strtolower(trim($data["resume_text"]));

// ---------- SCORING ----------
$score = 0;

// baseline: resume text exists
if (strlen($resumeText) > 10) {
    $score += 20;
}

// keyword scoring (main differentiator)
$keywords = ['html', 'css', 'javascript', 'react', 'sql', 'python'];
$hits = 0;

foreach ($keywords as $word) {
    if (strpos($resumeText, $word) !== false) {
        $hits++;
    }
}

$score += $hits * 15;

// experience bonus
if (preg_match('/\d+\s*(year|years)/', $resumeText)) {
    $score += 20;
}

if ($score > 100) {
    $score = 100;
}

// ---------- DB UPDATE ----------
$stmt = $conn->prepare(
    "UPDATE applications SET score = ? WHERE id = ?"
);
$stmt->bind_param("ii", $score, $applicationId);
$stmt->execute();

// ---------- RESPONSE ----------
echo json_encode([
    "success" => true,
    "application_id" => $applicationId,
    "score" => $score
]);
