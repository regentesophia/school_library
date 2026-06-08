<?php
// ============================================
// config/session.php - Session Management
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header("Location: " . APP_URL . "/index.php?msg=Please login to continue.");
        exit();
    }
}

/**
 * @param string|array $roles
 */
function require_role(array|string $roles): void {
    require_login();
    if (is_string($roles)) $roles = [$roles];
    if (!in_array($_SESSION['role'], $roles)) {
        header("Location: " . APP_URL . "/dashboard.php?error=Access denied.");
        exit();
    }
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'name'      => $_SESSION['full_name'],
        'role'      => $_SESSION['role'],
        'school_id' => $_SESSION['school_id'],
        'email'     => $_SESSION['email'],
        'pic'       => $_SESSION['profile_pic'] ?? 'default.png',
    ];
}

/**
 * @param string|array $roles
 */
function has_role(array|string $roles): bool {
    if (!is_logged_in()) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($_SESSION['role'], $roles);
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render Bootstrap alert from flash message.
 * Message is always set by our own server-side code — never raw user input.
 */
function show_flash(): void {
    $flash = get_flash();
    if ($flash) {
        $type = htmlspecialchars($flash['type']);
        $msg  = $flash['message'];
        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$msg}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
    }
}

function log_activity(mysqli $conn, int $user_id, string $action, string $details = ''): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare(
        "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isss", $user_id, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

function clean(string $data): string {
    return htmlspecialchars(stripslashes(trim($data)));
}

function redirect(string $url): void {
    header("Location: " . $url);
    exit();
}
?>