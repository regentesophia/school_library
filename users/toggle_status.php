<?php
// ============================================
// users/toggle_status.php
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_role(['admin']);
$current = current_user();

$uid = intval($_GET['id'] ?? 0);
if ($uid <= 0 || $uid === $current['id']) {
    set_flash('danger', 'Invalid operation.');
    redirect(APP_URL . '/users/index.php');
}

$stmt = $conn->prepare("SELECT full_name, status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) { set_flash('danger', 'User not found.'); redirect(APP_URL . '/users/index.php'); }

$new_status = $u['status'] === 'active' ? 'inactive' : 'active';
$stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
$stmt->bind_param("si", $new_status, $uid);
$stmt->execute();
$stmt->close();

log_activity($conn, $current['id'], 'TOGGLE_STATUS', "Set user ID $uid ({$u['full_name']}) to $new_status");
set_flash('success', "User '{$u['full_name']}' has been " . ($new_status === 'active' ? 'activated' : 'deactivated') . ".");
redirect(APP_URL . '/users/index.php');
?>