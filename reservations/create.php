<?php
// ============================================
// reservations/create.php - Reserve a Book
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['student', 'teacher']);
$user = current_user();

$errors = [];
$input  = [];

// Pre-fill book from URL
$prefill_book = null;
if (!empty($_GET['book_id'])) {
    $bid = intval($_GET['book_id']);
    $s = $conn->prepare("SELECT id, title, author, available_copies, cover_image FROM books WHERE id=? LIMIT 1");
    $s->bind_param("i", $bid);
    $s->execute();
    $prefill_book = $s->get_result()->fetch_assoc();
    $s->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['book_id']      = intval($_POST['book_id'] ?? 0);
    $input['expiry_date']  = clean($_POST['expiry_date'] ?? '');

    if ($input['book_id'] <= 0)   $errors[] = "Please select a book.";
    if (empty($input['expiry_date'])) $errors[] = "Expiry date is required.";
    if (!empty($input['expiry_date']) && strtotime($input['expiry_date']) <= time()) {
        $errors[] = "Expiry date must be in the future.";
    }

    // Check for existing active reservation
    if ($input['book_id'] > 0) {
        $chk = $conn->prepare(
            "SELECT id FROM reservations WHERE user_id=? AND book_id=? AND status IN ('pending','approved') LIMIT 1"
        );
        $chk->bind_param("ii", $user['id'], $input['book_id']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $errors[] = "You already have an active reservation for this book.";
        }
        $chk->close();
    }

    // Check if user already has this book borrowed
    if ($input['book_id'] > 0 && empty($errors)) {
        $chk2 = $conn->prepare(
            "SELECT id FROM borrow_records WHERE user_id=? AND book_id=? AND status IN ('borrowed','overdue') LIMIT 1"
        );
        $chk2->bind_param("ii", $user['id'], $input['book_id']);
        $chk2->execute();
        if ($chk2->get_result()->num_rows > 0) {
            $errors[] = "You already have this book borrowed.";
        }
        $chk2->close();
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "INSERT INTO reservations (user_id, book_id, expiry_date, status) VALUES (?, ?, ?, 'pending')"
        );
        $stmt->bind_param("iis", $user['id'], $input['book_id'], $input['expiry_date']);
        if ($stmt->execute()) {
            $stmt->close();
            log_activity($conn, $user['id'], 'RESERVE_BOOK', "Reserved book ID {$input['book_id']}");
            set_flash('success', "Reservation submitted! The librarian will review it shortly.");
            redirect(APP_URL . '/reservations/index.php');
        } else {
            $errors[] = "Database error: " . $conn->error;
            $stmt->close();
        }
    }
}

// Available books for dropdown
$books_list = $conn->query(
    "SELECT b.id, b.title, b.author, b.available_copies, c.name AS category
     FROM books b JOIN categories c ON c.id=b.category_id
     WHERE b.status='available'
     ORDER BY b.title ASC"
);

$default_expiry = date('Y-m-d', strtotime('+3 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve a Book — <?= APP_NAME ?></title>
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
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Reservations</a></li>
            <li class="breadcrumb-item active">Reserve a Book</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-bookmark-plus me-2" style="color:#c8963e;"></i>Reserve a Book</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <strong><i class="bi bi-exclamation-circle me-2"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="alert alert-info" style="border-radius:10px; font-size:0.875rem;">
        <i class="bi bi-info-circle me-2"></i>
        Reservations are reviewed by the librarian. You will be notified once approved. Reservations expire on the date you choose.
    </div>

    <form method="POST" action="create.php">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4">
                    <div class="section-heading">Select Book</div>
                    <div class="mb-4">
                        <label class="form-label">Book *</label>
                        <select name="book_id" id="bookSelect" class="form-select" required>
                            <option value="">— Search and select a book —</option>
                            <?php while ($bk = $books_list->fetch_assoc()): ?>
                            <option value="<?= $bk['id'] ?>"
                                data-author="<?= htmlspecialchars($bk['author']) ?>"
                                data-cat="<?= htmlspecialchars($bk['category']) ?>"
                                data-copies="<?= $bk['available_copies'] ?>"
                                <?= ($prefill_book && $prefill_book['id']==$bk['id']) ? 'selected':'' ?>
                                <?= ($input['book_id'] ?? 0)==$bk['id'] ? 'selected':'' ?>>
                                <?= htmlspecialchars($bk['title']) ?> — <?= htmlspecialchars($bk['author']) ?>
                                (<?= $bk['available_copies'] > 0 ? $bk['available_copies'].' available' : 'Currently unavailable' ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="bookPreview" class="preview-card mt-2" style="display:none;">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-book" style="font-size:2rem;color:#c8963e;"></i>
                                <div>
                                    <div class="fw-semibold" id="bookTitle"></div>
                                    <small class="text-muted" id="bookMeta"></small>
                                </div>
                                <span class="badge ms-auto" id="bookCopies"></span>
                            </div>
                        </div>
                    </div>

                    <div class="section-heading">Reservation Details</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Reservation Expiry Date *</label>
                            <input type="date" name="expiry_date" class="form-control"
                                   value="<?= htmlspecialchars($input['expiry_date'] ?? $default_expiry) ?>"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                   max="<?= date('Y-m-d', strtotime('+14 days')) ?>"
                                   required>
                            <div class="form-text">Max 14 days from today. Reservation auto-cancels after expiry.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Card -->
        <div class="col-lg-4">
            <div class="card" style="position:sticky;top:80px;">
                <div class="card-body p-4">
                    <div class="section-heading">How It Works</div>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ([
                            ['bi-bookmark-plus','Submit','Fill in the form and submit your reservation.'],
                            ['bi-hourglass-split','Pending Review','The librarian reviews and approves your request.'],
                            ['bi-check-circle','Approved','Once approved, visit the library to claim your book.'],
                            ['bi-arrow-right-circle','Issued','The librarian issues the book to you on the spot.'],
                        ] as [$icon, $title, $desc]): ?>
                        <div class="d-flex gap-3">
                            <div style="width:36px;height:36px;border-radius:50%;background:#e8f0fe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi <?= $icon ?>" style="color:#1a3c5e;"></i>
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size:0.875rem;"><?= $title ?></div>
                                <div class="text-muted" style="font-size:0.8rem;"><?= $desc ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <button type="submit" class="btn btn-save w-100">
                        <i class="bi bi-bookmark-plus me-2"></i>Submit Reservation
                    </button>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('bookSelect').addEventListener('change', function () {
    const opt     = this.options[this.selectedIndex];
    const preview = document.getElementById('bookPreview');
    if (this.value) {
        document.getElementById('bookTitle').textContent = opt.text.split(' — ')[0];
        document.getElementById('bookMeta').textContent  = opt.dataset.author + ' · ' + opt.dataset.cat;
        const copies = parseInt(opt.dataset.copies);
        const badge  = document.getElementById('bookCopies');
        badge.textContent  = copies > 0 ? copies + ' available' : 'Unavailable';
        badge.style.background = copies > 0 ? '#dcfce7' : '#fee2e2';
        badge.style.color      = copies > 0 ? '#15803d' : '#dc2626';
        preview.style.display = 'block';
        preview.className = 'preview-card mt-2 active';
    } else {
        preview.style.display = 'none';
    }
});
document.getElementById('bookSelect').dispatchEvent(new Event('change'));
</script>
</body>
</html>