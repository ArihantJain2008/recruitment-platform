<?php

$host = $_ENV["MYSQLHOST"] ?? "localhost";
$user = $_ENV["MYSQLUSER"] ?? "root";
$pass = $_ENV["MYSQLPASSWORD"] ?? "";
$db   = $_ENV["MYSQLDATABASE"] ?? "recruitment_db";
$port = $_ENV["MYSQLPORT"] ?? 3306;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}
