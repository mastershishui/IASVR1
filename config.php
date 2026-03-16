<?php
// BCP University Management System - Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'bcp_ums');
define('SITE_NAME', 'BCP University Management System');
define('SITE_SHORT', 'BCP-UMS');
define('ACADEMIC_YEAR', '2024-2025');
define('CURRENT_SEMESTER', 1);

// Database Connection
function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            die(json_encode(['error' => 'Database connection failed: ' . $db->connect_error]));
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth helpers
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        header('Location: dashboard.php');
        exit;
    }
}

function currentUser() {
    return $_SESSION ?? [];
}

function logActivity($action, $module, $details = '') {
    if (!isLoggedIn()) return;
    $db = getDB();
    $userId = $_SESSION['user_id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $userId, $action, $module, $details, $ip);
    $stmt->execute();
}

function escape($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function getUnreadNotifications($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
}
?>
