<?php
// ============================================
// logout.php - Logout Handler
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_logged_in()) {
    log_activity($conn, $_SESSION['user_id'], 'LOGOUT', 'User logged out.');
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to login
header("Location: " . APP_URL . "/index.php?msg=You have been logged out successfully.");
exit();
?>