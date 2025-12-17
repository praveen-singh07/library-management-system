<?php
// config.php
session_start();

$host = "sql113.infinityfree.com";
$user = "if0_40674768";      // apna MySQL username
$pass = "SauravA1B2";          // apna MySQL password
$db   = "if0_40674768_LibraryDatabase";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// fine per day (late return)
define('FINE_PER_DAY', 5); // 5 rupees/day (change if you want)

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function calculateFine($dueDate, $returnDate) {
    $due = new DateTime($dueDate);
    $ret = new DateTime($returnDate);
    if ($ret <= $due) return 0;
    $diff = $due->diff($ret)->days;
    return $diff * FINE_PER_DAY;
}
?>