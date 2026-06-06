<?php
// ============================================
// borrow/issue.php - Issue a Book
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

$errors      = [];
$input       = [];
$unpaid_fine = 0;

// Pre-fill book_id from URL (from books/view.php "Issue Book" button)
$prefill_book = null;
if (!empty($_GET['book_id'])) {
    $bid = intval($_GET['book_id']);
    $bs  = $conn->prepare("SELECT id, title, author, available_copies FROM books WHERE id = ? AND status = 'available' LIMIT 1");
    $bs->bind_param("i", $bid);
    $bs->execute();
    $prefill_book = $bs->get_result()->fetch_assoc();
    $bs->close();
}

// ============================================
// Handle Form Submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['user_id']     = intval($_POST['user_id']    ?? 0);
    $input['book_id']     = intval($_POST['book_id']    ?? 0);
    $input['borrow_date'] = clean($_POST['borrow_date'] ?? date('Y-m-d'));
    $input['due_date']    = clean($_POST['due_date']    ?? '');
    $input['remarks']     = clean($_POST['remarks']     ?? '');

    // --- Validation ---
    if ($input['user_id'] <= 0)    $errors[] = "Please select a borrower.";
    if ($input['book_id'] <= 0)    $errors[] = "Please select a book.";
    if (empty($input['due_date'])) $errors[] = "Due date is required.";

    if (!empty($input['borrow_date']) && !empty($input['due_date'])) {
        if (strtotime($input['due_date']) <= strtotime($input['borrow_date'])) {
            $errors[] = "Due date must be after the borrow date.";
        }
    }

    // --- Check book availability ---
    $book = null;
    if ($input['book_id'] > 0) {
        $bs = $conn->prepare("SELECT id, title, available_copies, status FROM books WHERE id = ? LIMIT 1");
        $bs->bind_param("i", $input['book_id']);
        $bs->execute();
        $book = $bs->get_result()->fetch_assoc();
        $bs->close();

        if (!$book || $book['available_copies'] < 1 || $book['status'] !== 'available') {
            $errors[] = "This book is not available for borrowing.";
        }
    }

    // --- Check if user already has this book borrowed ---
    if ($input['user_id'] > 0 && $input['book_id'] > 0 && empty($errors)) {
        $chk = $conn->prepare(
            "SELECT id FROM borrow_records
             WHERE user_id = ? AND book_id = ? AND status IN ('borrowed','overdue') LIMIT 1"
        );
        $chk->bind_param("ii", $input['user_id'], $input['book_id']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $errors[] = "This borrower already has an active copy of this book.";
        }
        $chk->close();
    }

    // --- Check unpaid fines (warn only, does not block) ---
    if ($input['user_id'] > 0) {
        $fs = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM fines WHERE user_id = ? AND status = 'unpaid'");
        $fs->bind_param("i", $input['user_id']);
        $fs->execute();
        $unpaid_fine = $fs->get_result()->fetch_assoc()['total'];
        $fs->close();
    }

    // --- Save inside a transaction ---
    if (empty($errors)) {
        $issued_by = $user['id'];

        $conn->begin_transaction();
        try {
            // 1. Insert borrow record
            $stmt = $conn->prepare(
                "INSERT INTO borrow_records
                    (user_id, book_id, issued_by, borrow_date, due_date, status, remarks)
                 VALUES (?, ?, ?, ?, ?, 'borrowed', ?)"
            );
            $stmt->bind_param(
                "iiisss",
                $input['user_id'],
                $input['book_id'],
                $issued_by,
                $input['borrow_date'],
                $input['due_date'],
                $input['remarks']
            );
            $stmt->execute();
            $stmt->close();

            // 2. Decrement available_copies atomically (guard: only if still > 0)
            $upd = $conn->prepare(
                "UPDATE books
                 SET available_copies = available_copies - 1
                 WHERE id = ? AND available_copies > 0"
            );
            $upd->bind_param("i", $input['book_id']);
            $upd->execute();

            // If no row was updated, someone else just took the last copy
            if ($upd->affected_rows < 1) {
                $upd->close();
                throw new Exception("No available copies left. The book may have just been issued to someone else.");
            }
            $upd->close();

            $conn->commit();

            log_activity($conn, $user['id'], 'ISSUE_BOOK',
                "Issued book ID {$input['book_id']} ({$book['title']}) to user ID {$input['user_id']}");
            set_flash('success', "Book '{$book['title']}' issued successfully!");
            redirect(APP_URL . '/borrow/index.php');

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Could not issue book: " . $e->getMessage();
        }
    }
}

// Fetch active borrowers (students & teachers)
$borrowers = $conn->query(
    "SELECT id, school_id, full_name, role, grade_section, department
     FROM users WHERE role IN ('student','teacher') AND status = 'active'
     ORDER BY full_name ASC"
);

// Fetch available books
$books_list = $conn->query(
    "SELECT b.id, b.title, b.author, b.available_copies, c.name AS category
     FROM books b JOIN categories c ON c.id = b.category_id
     WHERE b.available_copies > 0 AND b.status = 'available'
     ORDER BY b.title ASC"
);

$default_due = date('Y-m-d', strtotime('+' . DEFAULT_BORROW_DAYS . ' days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Book — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control,.form-select { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; font-size:0.9rem; }
        .form-control:focus,.form-select:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .section-heading { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6; padding-bottom:8px; margin-bottom:16px; }
        .preview-card { background:#f8f9fa; border-radius:10px; padding:16px; border:1.5px dashed #d1d5db; }
        .preview-card.active { background:#eff6ff; border-color:#1a3c5e; border-style:solid; }
        .btn-save { background:#1a3c5e; color:white; border:none; border-radius:10px; padding:11px 28px; font-weight:600; }
        .btn-save:hover { background:#0f2540; color:white; }
        .required-star { color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Borrowing</a></li>
            <li class="breadcrumb-item active">Issue Book</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-arrow-right-circle me-2" style="color:#c8963e;"></i>Issue Book</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <strong><i class="bi bi-exclamation-circle me-2"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($unpaid_fine > 0): ?>
    <div class="alert alert-warning" style="border-radius:10px;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Note:</strong> This borrower has an unpaid fine of
        <strong>₱<?= number_format($unpaid_fine, 2) ?></strong>.
    </div>
    <?php endif; ?>

    <form method="POST" action="issue.php">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4">

                    <div class="section-heading">Borrower</div>
                    <div class="mb-4">
                        <label class="form-label">Select Borrower <span class="required-star">*</span></label>
                        <select name="user_id" id="borrowerSelect" class="form-select" required>
                            <option value="">— Search and select borrower —</option>
                            <?php while ($b = $borrowers->fetch_assoc()): ?>
                            <option value="<?= $b['id'] ?>"
                                data-role="<?= $b['role'] ?>"
                                data-info="<?= htmlspecialchars($b['grade_section'] ?? $b['department'] ?? '') ?>"
                                <?= ($input['user_id'] ?? 0) == $b['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['full_name']) ?> — <?= htmlspecialchars($b['school_id']) ?> (<?= ucfirst($b['role']) ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="borrowerPreview" class="preview-card mt-2" style="display:none;">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-person-circle" style="font-size:2rem; color:#1a3c5e;"></i>
                                <div>
                                    <div class="fw-semibold" id="borrowerName"></div>
                                    <small class="text-muted" id="borrowerInfo"></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section-heading">Book</div>
                    <div class="mb-4">
                        <label class="form-label">Select Book <span class="required-star">*</span></label>
                        <select name="book_id" id="bookSelect" class="form-select" required>
                            <option value="">— Search and select book —</option>
                            <?php
                            $books_list->data_seek(0);
                            while ($bk = $books_list->fetch_assoc()):
                            ?>
                            <option value="<?= $bk['id'] ?>"
                                data-copies="<?= $bk['available_copies'] ?>"
                                data-author="<?= htmlspecialchars($bk['author']) ?>"
                                data-cat="<?= htmlspecialchars($bk['category']) ?>"
                                <?= ($prefill_book && $prefill_book['id'] == $bk['id']) ? 'selected' : '' ?>
                                <?= ($input['book_id'] ?? 0) == $bk['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bk['title']) ?> — <?= htmlspecialchars($bk['author']) ?> (<?= $bk['available_copies'] ?> available)
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="bookPreview" class="preview-card mt-2" style="display:none;">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-book" style="font-size:2rem; color:#c8963e;"></i>
                                <div>
                                    <div class="fw-semibold" id="bookTitle"></div>
                                    <small class="text-muted" id="bookMeta"></small>
                                </div>
                                <span class="badge ms-auto" id="bookCopies" style="background:#dcfce7; color:#15803d;"></span>
                            </div>
                        </div>
                    </div>

                    <div class="section-heading">Borrow Period</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Borrow Date <span class="required-star">*</span></label>
                            <input type="date" name="borrow_date" id="borrowDate" class="form-control"
                                   value="<?= htmlspecialchars($input['borrow_date'] ?? date('Y-m-d')) ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Due Date <span class="required-star">*</span></label>
                            <input type="date" name="due_date" id="dueDate" class="form-control"
                                   value="<?= htmlspecialchars($input['due_date'] ?? $default_due) ?>" required>
                            <div class="form-text">Default: <?= DEFAULT_BORROW_DAYS ?> days from borrow date</div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Remarks / Notes</label>
                        <textarea name="remarks" class="form-control" rows="2"
                                  placeholder="Optional notes about this transaction..."><?= htmlspecialchars($input['remarks'] ?? '') ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="col-lg-4">
            <div class="card" style="position:sticky; top:80px;">
                <div class="card-body p-4">
                    <div class="section-heading">Transaction Summary</div>
                    <div class="mb-3">
                        <div class="text-muted mb-1" style="font-size:0.8rem;">ISSUED BY</div>
                        <div class="fw-semibold"><?= htmlspecialchars($user['name']) ?></div>
                        <small class="text-muted"><?= ucfirst($user['role']) ?></small>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted mb-1" style="font-size:0.8rem;">DATE ISSUED</div>
                        <div class="fw-semibold"><?= date('F j, Y') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted mb-1" style="font-size:0.8rem;">BORROW PERIOD</div>
                        <div class="fw-semibold" id="summaryPeriod"><?= DEFAULT_BORROW_DAYS ?> days</div>
                    </div>
                    <div class="mb-4">
                        <div class="text-muted mb-1" style="font-size:0.8rem;">FINE RATE</div>
                        <div class="fw-semibold">₱<?= number_format(FINE_RATE_PER_DAY, 2) ?>/day overdue</div>
                    </div>
                    <button type="submit" class="btn btn-save w-100">
                        <i class="bi bi-arrow-right-circle me-2"></i>Issue Book
                    </button>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('borrowerSelect').addEventListener('change', function () {
    const opt     = this.options[this.selectedIndex];
    const preview = document.getElementById('borrowerPreview');
    if (this.value) {
        document.getElementById('borrowerName').textContent = opt.text.split(' — ')[0];
        document.getElementById('borrowerInfo').textContent =
            opt.dataset.role.charAt(0).toUpperCase() + opt.dataset.role.slice(1) +
            (opt.dataset.info ? ' · ' + opt.dataset.info : '');
        preview.style.display = 'block';
        preview.className = 'preview-card mt-2 active';
    } else {
        preview.style.display = 'none';
    }
});

document.getElementById('bookSelect').addEventListener('change', function () {
    const opt     = this.options[this.selectedIndex];
    const preview = document.getElementById('bookPreview');
    if (this.value) {
        document.getElementById('bookTitle').textContent  = opt.text.split(' — ')[0];
        document.getElementById('bookMeta').textContent   = opt.dataset.author + ' · ' + opt.dataset.cat;
        document.getElementById('bookCopies').textContent = opt.dataset.copies + ' available';
        preview.style.display = 'block';
        preview.className = 'preview-card mt-2 active';
    } else {
        preview.style.display = 'none';
    }
});

function updatePeriod() {
    const borrow = document.getElementById('borrowDate').value;
    const due    = document.getElementById('dueDate').value;
    if (borrow && due) {
        const days = Math.round((new Date(due) - new Date(borrow)) / (1000 * 60 * 60 * 24));
        document.getElementById('summaryPeriod').textContent =
            days > 0 ? days + ' day' + (days !== 1 ? 's' : '') : '—';
    }
}
document.getElementById('borrowDate').addEventListener('change', updatePeriod);
document.getElementById('dueDate').addEventListener('change', updatePeriod);

document.getElementById('borrowDate').addEventListener('change', function () {
    const d = new Date(this.value);
    d.setDate(d.getDate() + <?= DEFAULT_BORROW_DAYS ?>);
    document.getElementById('dueDate').valueAsDate = d;
    updatePeriod();
});

document.getElementById('bookSelect').dispatchEvent(new Event('change'));
document.getElementById('borrowerSelect').dispatchEvent(new Event('change'));
</script>
</body>
</html>