<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_name"])) {
    header("Location: /recruitment-platform/frontend/login.html");
    exit();
}

$sessionUserName = trim((string)($_SESSION["user_name"] ?? ""));
$portalHeading = $sessionUserName !== ""
    ? htmlspecialchars($sessionUserName, ENT_QUOTES, "UTF-8") . "'s Portal"
    : "Candidate Portal";

$sessionUser = [
    "id" => isset($_SESSION["user_id"]) ? intval($_SESSION["user_id"]) : 0,
    "name" => (string)($_SESSION["user_name"] ?? ""),
    "email" => (string)($_SESSION["user_email"] ?? ""),
    "role" => (string)($_SESSION["user_role"] ?? "candidate"),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Candidate Portal</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>
<body class="candidate-page">
  <header>
    <strong>&#128100; <?php echo $portalHeading; ?></strong>
    <div class="header-actions">
      <button
        id="theme-toggle-candidate"
        type="button"
        class="theme-toggle"
        aria-label="Toggle dark mode"
        aria-pressed="false"
      >
        🌙
      </button>
      <button
        id="notification-toggle"
        type="button"
        class="notification-toggle"
        aria-label="Toggle notifications"
        aria-controls="notification-widget"
        aria-expanded="false"
      >
        &#128276;
      </button>
      <button onclick="logout()">Logout</button>

      <aside id="notification-widget" class="notification-widget" aria-live="polite">
        <div class="notification-widget-header">
          <span class="notification-icon" aria-hidden="true">&#128276;</span>
          <h2>Notifications</h2>
        </div>
        <div id="notifications"></div>
      </aside>
    </div>
  </header>

  <div class="candidate-hero">
    <h1>My Applications</h1>
    <p>Track your application status and resume evaluation</p>
  </div>

  <div class="container">
    <h1>Available Jobs</h1>
    <div id="available-jobs"></div>
  </div>

  <div class="container">
    <h1>My Applications</h1>
    <div id="applications"></div>
  </div>

<script>
window.SERVER_SESSION_USER = <?php echo json_encode($sessionUser, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="theme.js"></script>
<script src="auth.js"></script>
<script src="candidate.js"></script>
</body>
</html>
