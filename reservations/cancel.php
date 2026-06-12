<?php
// ============================================
// reservations/cancel.php
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_login();
$user = current_user();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger','Invalid reservation.'); redirect(APP_URL.'/reservations/index.php'); }

$stmt = $conn->prepare("SELECT r.*, b.title, u.full_name FROM reservations r JOIN books b ON b.id=r.book_id JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) { set_flash('danger','Reservation not found.'); redirect(APP_URL.'/reservations/index.php'); }

// Students/teachers can only cancel their own
if (has_role(['student','teacher']) && $res['user_id'] != $user['id']) {
    set_flash('danger','Access denied.'); redirect(APP_URL.'/reservations/index.php');
}

if (!in_array($res['status'], ['pending','approved'])) {
    set_flash('info','This reservation cannot be cancelled.'); redirect(APP_URL.'/reservations/index.php');
}

$stmt = $conn->prepare("UPDATE reservations SET status='cancelled' WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

log_activity($conn, $user['id'], 'CANCEL_RESERVATION', "Cancelled reservation ID $id — {$res['title']}");
set_flash('success', "Reservation for '{$res['title']}' has been cancelled.");
redirect(APP_URL . '/reservations/index.php');
?>