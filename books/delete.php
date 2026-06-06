<?php
// ============================================
// books/delete.php - Delete Book Handler
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin']); // Only admin can delete books
$user = current_user();

$book_id = intval($_GET['id'] ?? 0);
if ($book_id <= 0) {
    set_flash('danger', 'Invalid book ID.');
    redirect(APP_URL . '/books/index.php');
}

// Fetch book
$stmt = $conn->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$book) {
    set_flash('danger', 'Book not found.');
    redirect(APP_URL . '/books/index.php');
}

// Prevent delete if there are active borrows
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM borrow_records WHERE book_id = ? AND status = 'borrowed'"
);
$stmt->bind_param("i", $book_id);
$stmt->execute();
$active_borrows = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

if ($active_borrows > 0) {
    set_flash('danger', "Cannot delete '{$book['title']}' — it has $active_borrows active borrow(s). Return all copies first.");
    redirect(APP_URL . '/books/view.php?id=' . $book_id);
}

// Delete the book
$stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
$stmt->bind_param("i", $book_id);

if ($stmt->execute()) {
    $stmt->close();

    // Delete cover image file (if not default)
    if ($book['cover_image'] !== 'default_book.png') {
        $cover_path = UPLOAD_PATH . 'books/' . $book['cover_image'];
        if (file_exists($cover_path)) {
            unlink($cover_path);
        }
    }

    log_activity($conn, $user['id'], 'DELETE_BOOK', "Deleted book: {$book['title']} (ISBN: {$book['isbn']})");
    set_flash('success', "Book '{$book['title']}' has been deleted.");
} else {
    $stmt->close();
    set_flash('danger', 'Failed to delete book. It may have related records.');
}

redirect(APP_URL . '/books/index.php');
?>