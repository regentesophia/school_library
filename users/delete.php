<?php
// ============================================
// users/delete.php
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_role(['admin']);
$current = current_user();

$uid = intval($_GET['id'] ?? 0);
if ($uid <= 0 || $uid === $current['id']) {
    set_flash('danger', 'You cannot delete your own account.');
    redirect(APP_URL . '/users/index.php');
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) { set_flash('danger', 'User not found.'); redirect(APP_URL . '/users/index.php'); }

// Block delete if user has active borrows
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM borrow_records WHERE user_id = ? AND status = 'borrowed'");
$stmt->bind_param("i", $uid);
$stmt->execute();
$active = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

if ($active > 0) {
    set_flash('danger', "Cannot delete '{$u['full_name']}' — they have $active active borrow(s).");
    redirect(APP_URL . '/users/index.php');
}

$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $uid);
if ($stmt->execute()) {
    $stmt->close();
    // Remove profile picture if not default
    if ($u['profile_pic'] !== 'default.png') {
        $pic_path = UPLOAD_PATH . 'profiles/' . $u['profile_pic'];
        if (file_exists($pic_path)) unlink($pic_path);
    }
    log_activity($conn, $current['id'], 'DELETE_USER', "Deleted user: {$u['full_name']} ({$u['role']})");
    set_flash('success', "User '{$u['full_name']}' has been deleted.");
} else {
    $stmt->close();
    set_flash('danger', 'Failed to delete user.');
}
redirect(APP_URL . '/users/index.php');
?>