<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include("../config/db.php");

$data = json_decode(file_get_contents("php://input"), true);

$applicationId = isset($data["application_id"]) ? intval($data["application_id"]) : 0;
$status = isset($data["status"]) ? strtolower(trim((string)$data["status"])) : "";

$allowed = ["shortlisted", "rejected", "interviewed"];

if ($applicationId <= 0 || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid status or application ID"]);
    exit;
}

$context = getApplicationContext($conn, $applicationId);
if (!$context) {
    http_response_code(404);
    echo json_encode(["error" => "Application not found"]);
    exit;
}

if (!ensureInterviewColumns($conn)) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to prepare interview scheduling columns"]);
    exit;
}

if ($status === "interviewed") {
    $interviewTimeRaw = trim((string)($data["interview_time"] ?? ""));
    $timezone = trim((string)($data["timezone"] ?? "UTC"));
    $durationMinutes = isset($data["duration_minutes"]) ? intval($data["duration_minutes"]) : 30;

    if ($interviewTimeRaw === "") {
        http_response_code(400);
        echo json_encode(["error" => "Interview date and time are required"]);
        exit;
    }

    if ($durationMinutes < 15 || $durationMinutes > 240) {
        http_response_code(400);
        echo json_encode(["error" => "Interview duration must be between 15 and 240 minutes"]);
        exit;
    }

    $interviewAt = parseInterviewTime($interviewTimeRaw, $timezone);
    if (!$interviewAt) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid interview date/time format"]);
        exit;
    }

    $meetLinkInput = trim((string)($data["meet_link"] ?? ""));
    $meetLink = sanitizeUrl($meetLinkInput);
    if ($meetLink === "") {
        // Fallback when Google API credentials are not configured.
        $meetLink = "https://meet.google.com/new";
    }

    $calendarLink = buildGoogleCalendarLink(
        (string)$context["job_title"],
        (string)$context["candidate_name"],
        $interviewAt,
        $durationMinutes,
        $timezone,
        $meetLink
    );

    $interviewTimeDb = $interviewAt->format("Y-m-d H:i:s");
    $humanTime = $interviewAt->format("D, d M Y h:i A");
    $note = "Interview scheduled for {$humanTime} ({$timezone}).";

    $stmt = $conn->prepare(
        "UPDATE applications
         SET status = ?,
             interview_time = ?,
             interview_timezone = ?,
             interview_duration_minutes = ?,
             interview_meet_link = ?,
             interview_calendar_link = ?,
             interview_note = ?,
             notified_at = NOW()
         WHERE id = ?"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Database error: " . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        "sssisssi",
        $status,
        $interviewTimeDb,
        $timezone,
        $durationMinutes,
        $meetLink,
        $calendarLink,
        $note,
        $applicationId
    );

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to schedule interview"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "application_id" => $applicationId,
        "candidate_id" => intval($context["candidate_id"]),
        "status" => $status,
        "interview_time" => $interviewTimeDb,
        "interview_timezone" => $timezone,
        "interview_duration_minutes" => $durationMinutes,
        "meet_link" => $meetLink,
        "calendar_link" => $calendarLink,
        "notification" => "Interview scheduled. Candidate will see this notification in their dashboard."
    ]);
    exit;
}

$stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $conn->error]);
    exit;
}

$stmt->bind_param("si", $status, $applicationId);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Database update failed"]);
    exit;
}

echo json_encode([
    "success" => true,
    "application_id" => $applicationId,
    "candidate_id" => intval($context["candidate_id"]),
    "status" => $status
]);

function getApplicationContext($conn, $applicationId) {
    $stmt = $conn->prepare(
        "SELECT
            applications.id,
            applications.candidate_id,
            users.name AS candidate_name,
            jobs.title AS job_title
         FROM applications
         LEFT JOIN users ON users.id = applications.candidate_id
         LEFT JOIN jobs ON jobs.id = applications.job_id
         WHERE applications.id = ?"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $applicationId);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function ensureInterviewColumns($conn) {
    $result = $conn->query("SHOW COLUMNS FROM applications");
    if (!$result) {
        return false;
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row["Field"]] = true;
    }

    $changes = [];

    if (!isset($columns["interview_time"])) {
        $changes[] = "ADD COLUMN interview_time DATETIME NULL";
    }
    if (!isset($columns["interview_timezone"])) {
        $changes[] = "ADD COLUMN interview_timezone VARCHAR(64) NULL";
    }
    if (!isset($columns["interview_duration_minutes"])) {
        $changes[] = "ADD COLUMN interview_duration_minutes INT NULL";
    }
    if (!isset($columns["interview_meet_link"])) {
        $changes[] = "ADD COLUMN interview_meet_link VARCHAR(512) NULL";
    }
    if (!isset($columns["interview_calendar_link"])) {
        $changes[] = "ADD COLUMN interview_calendar_link TEXT NULL";
    }
    if (!isset($columns["interview_note"])) {
        $changes[] = "ADD COLUMN interview_note TEXT NULL";
    }
    if (!isset($columns["notified_at"])) {
        $changes[] = "ADD COLUMN notified_at DATETIME NULL";
    }

    if (empty($changes)) {
        return true;
    }

    $sql = "ALTER TABLE applications " . implode(", ", $changes);
    return (bool)$conn->query($sql);
}

function parseInterviewTime($raw, $timezone) {
    $tz = safeTimeZone($timezone);

    $formats = ["Y-m-d\\TH:i", "Y-m-d H:i:s", "Y-m-d H:i"];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $raw, $tz);
        if ($date instanceof DateTime) {
            return $date;
        }
    }

    try {
        return new DateTime($raw, $tz);
    } catch (Exception $e) {
        return null;
    }
}

function safeTimeZone($timezone) {
    try {
        return new DateTimeZone($timezone);
    } catch (Exception $e) {
        return new DateTimeZone("UTC");
    }
}

function sanitizeUrl($url) {
    $url = trim((string)$url);
    if ($url === "") {
        return "";
    }

    $sanitized = filter_var($url, FILTER_SANITIZE_URL);
    if (!$sanitized) {
        return "";
    }

    if (!preg_match('/^https?:\/\//i', $sanitized)) {
        return "";
    }

    return $sanitized;
}

function buildGoogleCalendarLink($jobTitle, $candidateName, DateTime $startLocal, $durationMinutes, $timezone, $meetLink) {
    $startUtc = clone $startLocal;
    $startUtc->setTimezone(new DateTimeZone("UTC"));

    $endUtc = clone $startUtc;
    $endUtc->modify("+" . intval($durationMinutes) . " minutes");

    $eventTitle = trim("Interview - " . ($jobTitle ?: "Job Role"));
    $details = "Interview with " . ($candidateName ?: "Candidate") . ". Timezone: " . $timezone . ". Join using Google Meet: " . $meetLink;

    $params = [
        "action" => "TEMPLATE",
        "text" => $eventTitle,
        "dates" => $startUtc->format("Ymd\\THis\\Z") . "/" . $endUtc->format("Ymd\\THis\\Z"),
        "details" => $details,
        "location" => "Google Meet"
    ];

    return "https://calendar.google.com/calendar/render?" . http_build_query($params);
}
