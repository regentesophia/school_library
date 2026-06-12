<?php
// ============================================
// reservations/fulfill.php - Convert reservation to borrow
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_role(['admin', 'librarian']);
$user = current_user();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger','Invalid reservation.'); redirect(APP_URL.'/reservations/index.php'); }

$stmt = $conn->prepare(
    "SELECT r.*, b.title, b.available_copies, u.full_name, u.id AS uid
     FROM reservations r
     JOIN books b ON b.id=r.book_id
     JOIN users u ON u.id=r.user_id
     WHERE r.id=? LIMIT 1"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res || $res['status'] !== 'approved') {
    set_flash('danger','Reservation must be approved before fulfilling.');
    redirect(APP_URL . '/reservations/index.php');
}

if ($res['available_copies'] < 1) {
    set_flash('danger',"Cannot fulfill — '{$res['title']}' has no available copies right now.");
    redirect(APP_URL . '/reservations/index.php');
}

// Create borrow record
$borrow_date = date('Y-m-d');
$due_date    = date('Y-m-d', strtotime('+' . DEFAULT_BORROW_DAYS . ' days'));
$issued_by   = $user['id'];

$bstmt = $conn->prepare(
    "INSERT INTO borrow_records (user_id, book_id, issued_by, borrow_date, due_date, status)
     VALUES (?, ?, ?, ?, ?, 'borrowed')"
);
$bstmt->bind_param("iiiss", $res['user_id'], $res['book_id'], $issued_by, $borrow_date, $due_date);
$bstmt->execute();
$bstmt->close();

// Decrement available copies
$upd = $conn->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id=?");
$upd->bind_param("i", $res['book_id']);
$upd->execute();
$upd->close();

// Mark reservation as fulfilled
$fstmt = $conn->prepare("UPDATE reservations SET status='fulfilled' WHERE id=?");
$fstmt->bind_param("i", $id);
$fstmt->execute();
$fstmt->close();

log_activity($conn, $user['id'], 'FULFILL_RESERVATION',
    "Fulfilled reservation ID $id for {$res['full_name']} — {$res['title']}. Due: $due_date");
set_flash('success', "Book '{$res['title']}' issued to {$res['full_name']}. Due: " . date('M j, Y', strtotime($due_date)));
redirect(APP_URL . '/reservations/index.php');
?>