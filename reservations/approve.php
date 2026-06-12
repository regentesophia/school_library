<?php
// ============================================
// reservations/approve.php
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_role(['admin', 'librarian']);
$user = current_user();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger','Invalid reservation.'); redirect(APP_URL.'/reservations/index.php'); }

$stmt = $conn->prepare("SELECT r.*, b.title, u.full_name FROM reservations r JOIN books b ON b.id=r.book_id JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res || $res['status'] !== 'pending') {
    set_flash('danger', 'Reservation not found or already processed.');
    redirect(APP_URL . '/reservations/index.php');
}

$stmt = $conn->prepare("UPDATE reservations SET status='approved' WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

log_activity($conn, $user['id'], 'APPROVE_RESERVATION', "Approved reservation ID $id for {$res['full_name']} — {$res['title']}");
set_flash('success', "Reservation for '{$res['title']}' approved. Notify {$res['full_name']} to claim it.");
redirect(APP_URL . '/reservations/index.php');
?>