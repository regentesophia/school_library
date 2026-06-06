<?php
// ============================================
// borrow/return.php - Return a Book + Fine Calculation
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

$record_id = intval($_GET['id'] ?? 0);
if ($record_id <= 0) {
    set_flash('danger', 'Invalid borrow record.');
    redirect(APP_URL . '/borrow/index.php');
}

// Fetch borrow record with book and user details
$stmt = $conn->prepare(
    "SELECT br.*, u.full_name, u.school_id, u.role,
            b.title, b.author, b.cover_image, b.id AS book_id,
            b.total_copies, b.available_copies
     FROM borrow_records br
     JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     WHERE br.id = ? LIMIT 1"
);
$stmt->bind_param("i", $record_id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    set_flash('danger', 'Borrow record not found.');
    redirect(APP_URL . '/borrow/index.php');
}

if ($record['status'] === 'returned') {
    set_flash('info', 'This book has already been returned.');
    redirect(APP_URL . '/borrow/index.php');
}

// Calculate fine preview (based on today)
$today        = new DateTime();
$due_date     = new DateTime($record['due_date']);
$days_overdue = 0;
$fine_amount  = 0.00;

if ($today > $due_date) {
    $days_overdue = (int) $today->diff($due_date)->days;
    $fine_amount  = $days_overdue * FINE_RATE_PER_DAY;
}

// ============================================
// Handle Return Submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return_date = clean($_POST['return_date'] ?? date('Y-m-d'));
    $remarks     = clean($_POST['remarks'] ?? '');

    // Recalculate fine based on actual return date chosen
    $actual_return  = new DateTime($return_date);
    $actual_fine    = 0.00;
    $actual_overdue = 0;

    if ($actual_return > $due_date) {
        $actual_overdue = (int) $actual_return->diff($due_date)->days;
        $actual_fine    = $actual_overdue * FINE_RATE_PER_DAY;
    }

    $conn->begin_transaction();
    try {
        // 1. Mark borrow record as returned
        $stmt = $conn->prepare(
            "UPDATE borrow_records SET status='returned', return_date=?, remarks=? WHERE id=?"
        );
        $stmt->bind_param("ssi", $return_date, $remarks, $record_id);
        $stmt->execute();
        $stmt->close();

        // 2. Increment available_copies — but never exceed total_copies
        $upd = $conn->prepare(
            "UPDATE books
             SET available_copies = LEAST(available_copies + 1, total_copies)
             WHERE id = ?"
        );
        $upd->bind_param("i", $record['book_id']);
        $upd->execute();
        $upd->close();

        // 3. Create fine record if overdue
        if ($actual_fine > 0) {
            $fstmt = $conn->prepare(
                "INSERT INTO fines (borrow_id, user_id, amount, days_overdue, status)
                 VALUES (?, ?, ?, ?, 'unpaid')"
            );
            $fstmt->bind_param("iidi",
                $record_id, $record['user_id'], $actual_fine, $actual_overdue
            );
            $fstmt->execute();
            $fstmt->close();
        }

        $conn->commit();

        if ($actual_fine > 0) {
            log_activity($conn, $user['id'], 'RETURN_BOOK',
                "Returned book ID {$record['book_id']}. Fine: ₱" . number_format($actual_fine, 2));
            set_flash('warning',
                "Book returned. A fine of <strong>₱" . number_format($actual_fine, 2) .
                "</strong> ({$actual_overdue} day(s) overdue) has been recorded for {$record['full_name']}.");
        } else {
            log_activity($conn, $user['id'], 'RETURN_BOOK',
                "Returned book ID {$record['book_id']}. No fine.");
            set_flash('success', "Book '{$record['title']}' returned successfully. No fine incurred.");
        }

        redirect(APP_URL . '/borrow/index.php');

    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Return failed: ' . $e->getMessage());
        redirect(APP_URL . '/borrow/index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Book — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; font-size:0.9rem; }
        .form-control:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .info-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:0.875rem; }
        .info-row:last-child { border-bottom:none; }
        .fine-display { background:#fff8f0; border:1.5px solid #f59e0b; border-radius:12px; padding:20px; text-align:center; }
        .fine-amount { font-size:2.5rem; font-weight:700; color:#d97706; font-family:'Playfair Display',serif; }
        .btn-return { background:#1e6b3c; color:white; border:none; border-radius:10px; padding:11px 28px; font-weight:600; }
        .btn-return:hover { background:#155230; color:white; }
        .book-cover { width:80px; height:110px; object-fit:cover; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Borrowing</a></li>
            <li class="breadcrumb-item active">Return Book</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-arrow-left-circle me-2" style="color:#c8963e;"></i>Return Book</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-uppercase" style="font-size:0.75rem; letter-spacing:0.08em; color:#c8963e; margin-bottom:16px;">Borrow Record</h6>
                    <div class="d-flex gap-3 mb-4">
                        <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($record['cover_image']) ?>"
                             onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                             class="book-cover" alt="Cover">
                        <div>
                            <h5 class="fw-bold mb-1" style="color:#1a3c5e;"><?= htmlspecialchars($record['title']) ?></h5>
                            <p class="text-muted mb-2" style="font-size:0.9rem;"><?= htmlspecialchars($record['author']) ?></p>
                            <span class="badge <?= $record['status']==='overdue' ? 'bg-danger' : 'bg-primary' ?>">
                                <?= ucfirst($record['status']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-row"><span class="text-muted">Borrower</span><span class="fw-semibold"><?= htmlspecialchars($record['full_name']) ?></span></div>
                    <div class="info-row"><span class="text-muted">School ID</span><span><?= htmlspecialchars($record['school_id']) ?></span></div>
                    <div class="info-row"><span class="text-muted">Role</span><span><?= ucfirst($record['role']) ?></span></div>
                    <div class="info-row"><span class="text-muted">Borrow Date</span><span><?= date('F j, Y', strtotime($record['borrow_date'])) ?></span></div>
                    <div class="info-row">
                        <span class="text-muted">Due Date</span>
                        <span class="<?= $days_overdue > 0 ? 'text-danger fw-bold' : '' ?>">
                            <?= date('F j, Y', strtotime($record['due_date'])) ?>
                        </span>
                    </div>
                    <?php if ($days_overdue > 0): ?>
                    <div class="info-row">
                        <span class="text-muted">Days Overdue</span>
                        <span class="text-danger fw-bold"><?= $days_overdue ?> day(s)</span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="text-muted">Current Availability</span>
                        <span><?= $record['available_copies'] ?> / <?= $record['total_copies'] ?> copies</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-uppercase" style="font-size:0.75rem; letter-spacing:0.08em; color:#c8963e; margin-bottom:16px;">Process Return</h6>
                    <form method="POST" action="return.php?id=<?= $record_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" id="returnDate" class="form-control"
                                   value="<?= date('Y-m-d') ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"
                                      placeholder="Book condition, notes, etc."></textarea>
                        </div>
                        <button type="submit" class="btn btn-return w-100">
                            <i class="bi bi-check-circle me-2"></i>Confirm Return
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card" style="position:sticky; top:80px;">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-uppercase" style="font-size:0.75rem; letter-spacing:0.08em; color:#c8963e; margin-bottom:16px;">Fine Calculator</h6>

                    <?php if ($days_overdue > 0): ?>
                    <div class="fine-display mb-3">
                        <div style="font-size:0.85rem; color:#92400e; margin-bottom:4px;">CURRENT FINE</div>
                        <div class="fine-amount">₱<span id="fineDisplay"><?= number_format($fine_amount, 2) ?></span></div>
                        <div style="font-size:0.8rem; color:#92400e; margin-top:4px;">
                            <span id="overdueDisplay"><?= $days_overdue ?></span> day(s) × ₱<?= number_format(FINE_RATE_PER_DAY, 2) ?>/day
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-3 mb-3" style="background:#f0fdf4; border-radius:12px; border:1.5px solid #86efac;">
                        <i class="bi bi-check-circle-fill text-success" style="font-size:2rem;"></i>
                        <div class="fw-semibold text-success mt-2">No Fine!</div>
                        <div style="font-size:0.82rem; color:#15803d;">Returned on time.</div>
                    </div>
                    <?php endif; ?>

                    <div class="info-row"><span class="text-muted">Fine Rate</span><span>₱<?= number_format(FINE_RATE_PER_DAY, 2) ?>/day</span></div>
                    <div class="info-row"><span class="text-muted">Due Date</span><span><?= date('M j, Y', strtotime($record['due_date'])) ?></span></div>
                    <div class="info-row">
                        <span class="text-muted">Calculated Fine</span>
                        <span class="fw-bold text-danger" id="calcFine">₱<?= number_format($fine_amount, 2) ?></span>
                    </div>

                    <?php if ($days_overdue > 0): ?>
                    <div class="alert alert-warning mt-3 mb-0" style="border-radius:10px; font-size:0.82rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Fine will be recorded as <strong>unpaid</strong>. The borrower must settle it at the library counter.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const dueDate  = new Date('<?= $record['due_date'] ?>');
const fineRate = <?= FINE_RATE_PER_DAY ?>;

document.getElementById('returnDate').addEventListener('change', function () {
    const returnDate = new Date(this.value);
    const diffDays   = Math.ceil((returnDate - dueDate) / (1000 * 60 * 60 * 24));

    const fineDisplay = document.getElementById('fineDisplay');
    const calcFine    = document.getElementById('calcFine');
    const overdue     = document.getElementById('overdueDisplay');

    if (diffDays > 0) {
        const fine = (diffDays * fineRate).toFixed(2);
        if (fineDisplay) { fineDisplay.textContent = parseFloat(fine).toLocaleString('en-PH', {minimumFractionDigits:2}); }
        if (overdue)     { overdue.textContent = diffDays; }
        if (calcFine)    { calcFine.textContent = '₱' + parseFloat(fine).toLocaleString('en-PH', {minimumFractionDigits:2}); }
    } else {
        if (fineDisplay) { fineDisplay.textContent = '0.00'; }
        if (overdue)     { overdue.textContent = '0'; }
        if (calcFine)    { calcFine.textContent = '₱0.00'; }
    }
});
</script>
</body>
</html>