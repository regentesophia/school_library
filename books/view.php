<?php
// ============================================
// books/view.php - Book Detail Page
// ============================================
require_once '../config/db.php';
require_once '../config/session.php';

require_login();
$user = current_user();

$book_id = intval($_GET['id'] ?? 0);
if ($book_id <= 0) {
    set_flash('danger', 'Invalid book ID.');
    redirect(APP_URL . '/books/index.php');
}

// Fetch book with category and added_by user
$stmt = $conn->prepare(
    "SELECT b.*, c.name AS category, u.full_name AS added_by_name
     FROM books b
     JOIN categories c ON c.id = b.category_id
     JOIN users u ON u.id = b.added_by
     WHERE b.id = ? LIMIT 1"
);
$stmt->bind_param("i", $book_id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$book) {
    set_flash('danger', 'Book not found.');
    redirect(APP_URL . '/books/index.php');
}

// Fetch recent borrow history for this book (admin/librarian only)
$borrow_history = null;
if (has_role(['admin', 'librarian'])) {
    $stmt = $conn->prepare(
        "SELECT br.id, u.full_name, u.school_id, u.role,
                br.borrow_date, br.due_date, br.return_date, br.status
         FROM borrow_records br
         JOIN users u ON u.id = br.user_id
         WHERE br.book_id = ?
         ORDER BY br.borrow_date DESC
         LIMIT 10"
    );
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $borrow_history = $stmt->get_result();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($book['title']) ?> — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .book-cover-large {
            width:160px; height:220px; object-fit:cover;
            border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.2);
        }
        .book-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.8rem; line-height:1.3; }
        .detail-label { font-size:0.75rem; text-transform:uppercase;
                        letter-spacing:0.06em; color:#6b7280; font-weight:600; }
        .detail-value { font-size:0.9rem; color:#1e1e1e; margin-top:2px; }
        .availability-bar { height:8px; border-radius:4px; background:#e5e7eb; margin-top:6px; }
        .availability-fill { height:100%; border-radius:4px; }
        .section-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.1rem; }
        .badge-borrowed { background:#dbeafe; color:#1d4ed8; }
        .badge-returned { background:#dcfce7; color:#15803d; }
        .badge-overdue  { background:#fee2e2; color:#dc2626; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
        .table td { vertical-align:middle; font-size:0.875rem; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Books</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($book['title']) ?></li>
        </ol>
    </nav>

    <?php show_flash(); ?>

    <!-- Book Header Card -->
    <div class="card mb-4">
        <div class="card-body p-4">
            <div class="d-flex gap-4 flex-wrap">

                <!-- Cover -->
                <div class="flex-shrink-0">
                    <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($book['cover_image']) ?>"
                         onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                         class="book-cover-large" alt="Book Cover">
                </div>

                <!-- Info -->
                <div class="flex-grow-1">
                    <div class="mb-2">
                        <span class="badge rounded-pill" style="background:#e8f0fe; color:#1a3c5e; font-size:0.75rem;">
                            <?= htmlspecialchars($book['category']) ?>
                        </span>
                    </div>
                    <h1 class="book-title mb-1"><?= htmlspecialchars($book['title']) ?></h1>
                    <p class="text-muted mb-3" style="font-size:1rem;">by <?= htmlspecialchars($book['author']) ?></p>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="detail-label">ISBN</div>
                            <div class="detail-value font-monospace"><?= htmlspecialchars($book['isbn']) ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-label">Publisher</div>
                            <div class="detail-value"><?= htmlspecialchars($book['publisher'] ?: '—') ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-label">Year</div>
                            <div class="detail-value"><?= htmlspecialchars($book['publication_year'] ?: '—') ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-label">Location</div>
                            <div class="detail-value"><?= htmlspecialchars($book['location'] ?: '—') ?></div>
                        </div>
                    </div>

                    <!-- Availability -->
                    <div style="max-width:280px;">
                        <div class="d-flex justify-content-between">
                            <span class="detail-label">Availability</span>
                            <span style="font-size:0.85rem; font-weight:600; color:#1a3c5e;">
                                <?= $book['available_copies'] ?> / <?= $book['total_copies'] ?> copies
                            </span>
                        </div>
                        <div class="availability-bar">
                            <?php
                            $pct = $book['total_copies'] > 0
                                ? ($book['available_copies'] / $book['total_copies'] * 100) : 0;
                            $bar_color = $pct > 50 ? '#1a3c5e' : ($pct > 0 ? '#c8963e' : '#dc2626');
                            ?>
                            <div class="availability-fill" style="width:<?= $pct ?>%; background:<?= $bar_color ?>;"></div>
                        </div>
                    </div>

                    <!-- Status Badge -->
                    <div class="mt-3">
                        <span class="badge fs-6 px-3 py-2"
                              style="background:<?= $book['status'] === 'available' ? '#dcfce7' : '#fee2e2' ?>;
                                     color:<?= $book['status'] === 'available' ? '#15803d' : '#dc2626' ?>;">
                            <i class="bi bi-<?= $book['status'] === 'available' ? 'check-circle' : 'x-circle' ?> me-1"></i>
                            <?= ucfirst($book['status']) ?>
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex flex-column gap-2 ms-auto">
                    <?php if (has_role(['admin', 'librarian'])): ?>
                    <a href="edit.php?id=<?= $book_id ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i>Edit Book
                    </a>
                    <a href="<?= APP_URL ?>/borrow/issue.php?book_id=<?= $book_id ?>" class="btn btn-sm"
                       style="background:#1a3c5e; color:white; border-radius:8px;">
                        <i class="bi bi-arrow-right-circle me-1"></i>Issue Book
                    </a>
                    <?php if (has_role(['admin'])): ?>
                    <button class="btn btn-outline-danger btn-sm"
                            onclick="confirmDelete(<?= $book_id ?>, '<?= htmlspecialchars(addslashes($book['title'])) ?>')">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (has_role(['student', 'teacher'])): ?>
                    <?php if ($book['available_copies'] > 0): ?>
                    <a href="<?= APP_URL ?>/reservations/cart.php?add=<?= $book_id ?>" class="btn btn-sm"
                       style="background:#1a3c5e; color:white; border-radius:8px;">
                        <i class="bi bi-cart-plus me-1"></i>Add to Cart
                    </a>
                    <a href="<?= APP_URL ?>/reservations/create.php?book_id=<?= $book_id ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-bookmark-plus me-1"></i>Reserve
                    </a>
                    <?php else: ?>
                    <span class="badge bg-danger p-2" style="font-size:0.8rem;">
                        <i class="bi bi-x-circle me-1"></i>Not Available
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Description -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h5 class="section-title mb-3"><i class="bi bi-file-text me-2" style="color:#c8963e;"></i>Description</h5>
                    <?php if ($book['description']): ?>
                    <p style="line-height:1.8; color:#374151;"><?= nl2br(htmlspecialchars(html_entity_decode($book['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?></p>
                    <?php else: ?>
                    <p class="text-muted fst-italic">No description available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Meta -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h5 class="section-title mb-3"><i class="bi bi-info-circle me-2" style="color:#c8963e;"></i>Details</h5>
                    <table class="table table-borderless mb-0" style="font-size:0.875rem;">
                        <tr><td class="text-muted ps-0" style="width:45%;">Added by</td>
                            <td><?= htmlspecialchars($book['added_by_name']) ?></td></tr>
                        <tr><td class="text-muted ps-0">Date Added</td>
                            <td><?= date('M j, Y', strtotime($book['created_at'])) ?></td></tr>
                        <tr><td class="text-muted ps-0">Last Updated</td>
                            <td><?= date('M j, Y', strtotime($book['updated_at'])) ?></td></tr>
                        <tr><td class="text-muted ps-0">Total Copies</td>
                            <td><?= $book['total_copies'] ?></td></tr>
                        <tr><td class="text-muted ps-0">Available</td>
                            <td><?= $book['available_copies'] ?></td></tr>
                        <tr><td class="text-muted ps-0">Borrowed</td>
                            <td><?= $book['total_copies'] - $book['available_copies'] ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Borrow History (Admin/Librarian only) -->
    <?php if (has_role(['admin', 'librarian']) && $borrow_history): ?>
    <div class="card mt-4">
        <div class="card-body p-4">
            <h5 class="section-title mb-3"><i class="bi bi-clock-history me-2" style="color:#c8963e;"></i>Borrow History</h5>
            <?php if ($borrow_history->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Borrower</th>
                            <th>Role</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $borrow_history->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($row['full_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($row['school_id']) ?></small>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= ucfirst($row['role']) ?></span></td>
                        <td><?= date('M j, Y', strtotime($row['borrow_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($row['due_date'])) ?></td>
                        <td><?= $row['return_date'] ? date('M j, Y', strtotime($row['return_date'])) : '—' ?></td>
                        <td>
                            <?php
                            $s = $row['status'];
                            if ($s === 'borrowed' && $row['due_date'] < date('Y-m-d')) $s = 'overdue';
                            ?>
                            <span class="badge badge-<?= $s ?>"><?= ucfirst($s) ?></span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted fst-italic">This book has never been borrowed.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Delete Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Are you sure you want to delete <strong id="deleteBookTitle"></strong>?</div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Yes, Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(id, title) {
    document.getElementById('deleteBookTitle').textContent = title;
    document.getElementById('deleteConfirmBtn').href = 'delete.php?id=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>