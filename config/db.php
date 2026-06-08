<?php
// ============================================
// config/db.php - Database Configuration
// ============================================

date_default_timezone_set('Asia/Manila');
ini_set('session.gc_maxlifetime', 7200); // 2 hours
session_set_cookie_params(7200);
require_once __DIR__ . '/LibraryHelper.php';

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'school_library');
define('DB_CHARSET', 'utf8mb4');

// Fine rate per day (in PHP peso)
define('FINE_RATE_PER_DAY', 5.00);

// Default borrow duration (days)
define('DEFAULT_BORROW_DAYS', 7);

// App settings
define('APP_NAME', 'School Library System');
define('APP_URL', 'http://localhost/school_library');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// ============================================
// Create MySQLi connection
// ============================================
function get_db_connection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }

    $conn->set_charset(DB_CHARSET);
    return $conn;
}

// Global connection instance
$conn = get_db_connection();
?>