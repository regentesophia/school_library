<?php
// ============================================
// reports/fines.php - Fines Management
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

// ---- Handle Pay / Waive Fine Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fine_id = intval($_POST['fine_id'] ?? 0);
    $action  = clean($_POST['action'] ?? '');

    if ($fine_id > 0 && in_array($action, ['pay', 'waive'])) {
        $new_status = $action === 'pay' ? 'paid' : 'paid'; // Both mark as paid; distinguish via remarks if needed
        $stmt = $conn->prepare("UPDATE fines SET status='paid', paid_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $fine_id);
        $stmt->execute();
        $stmt->close();

        $label = $action === 'pay' ? 'paid' : 'waived';
        log_activity($conn, $user['id'], 'FINE_' . strtoupper($action), "Fine ID $fine_id marked as $label.");
        set_flash('success', "Fine has been marked as $label successfully.");
    }
    redirect(APP_URL . '/reports/fines.php');
}

// Filters
$search   = clean($_GET['search'] ?? '');
$status_f = clean($_GET['status'] ?? '');
$export   = isset($_GET['export']) && $_GET['export'] === 'csv';

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (u.full_name LIKE ? OR u.school_id LIKE ? OR b.title LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($status_f !== '') {
    $where   .= " AND f.status = ?";
    $params[] = $status_f;
    $types   .= "s";
}

$sql = "SELECT f.id AS fine_id, f.amount, f.days_overdue, f.status AS fine_status, f.paid_at, f.created_at,
               u.full_name, u.school_id, u.role, u.email,
               b.title AS book_title, b.author,
               br.borrow_date, br.due_date, br.return_date
        FROM fines f
        JOIN borrow_records br ON br.id = f.borrow_id
        JOIN users u  ON u.id  = br.user_id
        JOIN books b  ON b.id  = br.book_id
        $where
        ORDER BY f.created_at DESC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$fines = $stmt->get_result();
$stmt->close();

// CSV Export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fines_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fine ID','Borrower','School ID','Role','Book','Borrow Date','Due Date','Return Date','Days Overdue','Amount','Status','Paid At']);
    while ($r = $fines->fetch_assoc()) {
        fputcsv($out, [
            $r['fine_id'], $r['full_name'], $r['school_id'], ucfirst($r['role']),
            $r['book_title'], $r['borrow_date'], $r['due_date'], $r['return_date'] ?? '',
            $r['days_overdue'], $r['amount'], ucfirst($r['fine_status']),
            $r['paid_at'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

// Re-fetch for display after potential CSV
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$fines = $stmt->get_result();
$stmt->close();

// Summary stats
$summary = $conn->query(
    "SELECT
        COUNT(*) AS total_fines,
        SUM(CASE WHEN status='unpaid' THEN amount ELSE 0 END) AS total_unpaid,
        SUM(CASE WHEN status='paid'   THEN amount ELSE 0 END) AS total_collected,
        SUM(amount) AS grand_total
     FROM fines"
)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fines Report — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-card { border-radius:14px; padding:20px; color:white; }
        .stat-card .val { font-size:1.8rem; font-weight:700; }
        .stat-card .lbl { font-size:0.8rem; opacity:0.85; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .badge-unpaid { background:#fef3c7; color:#d97706; }
        .badge-paid   { background:#dcfce7; color:#15803d; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        @media print { .no-print { display:none !important; } body { background:white; } }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-cash-coin me-2" style="color:#c8963e;"></i>Fines Management</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;">Generated: <?= date('F j, Y g:i A') ?></p>
        </div>
        <div class="d-flex gap-2 no-print flex-wrap">
            <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&export=csv"
               class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#1a3c5e,#0f2540);">
                <div class="val"><?= $summary['total_fines'] ?></div>
                <div class="lbl">Total Fine Records</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#dc2626,#991b1b);">
                <div class="val">₱<?= number_format($summary['total_unpaid'], 2) ?></div>
                <div class="lbl">Total Unpaid</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#15803d,#166534);">
                <div class="val">₱<?= number_format($summary['total_collected'], 2) ?></div>
                <div class="lbl">Total Collected</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#c8963e,#a07030);">
                <div class="val">₱<?= number_format($summary['grand_total'], 2) ?></div>
                <div class="lbl">Grand Total</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                    <input type="text" name="search" class="form-control search-box"
                           placeholder="Name, School ID, or book title..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select search-box">
                        <option value="">All</option>
                        <option value="unpaid" <?= $status_f==='unpaid' ? 'selected':'' ?>>Unpaid</option>
                        <option value="paid"   <?= $status_f==='paid'   ? 'selected':'' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn w-100" style="background:#1a3c5e;color:white;border-radius:10px;">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="fines.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Fines Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Borrower</th>
                            <th>Book</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Days Overdue</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date Created</th>
                            <th class="text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($fines->num_rows > 0): ?>
                        <?php while ($r = $fines->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['school_id']) ?> · <?= ucfirst($r['role']) ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold" style="max-width:160px;" title="<?= htmlspecialchars($r['book_title']) ?>">
                                    <?= htmlspecialchars(strlen($r['book_title']) > 28 ? substr($r['book_title'],0,28).'…' : $r['book_title']) ?>
                                </div>
                                <small class="text-muted"><?= htmlspecialchars($r['author']) ?></small>
                            </td>
                            <td class="text-danger fw-semibold" style="font-size:0.82rem;"><?= date('M j, Y', strtotime($r['due_date'])) ?></td>
                            <td style="font-size:0.82rem;"><?= $r['return_date'] ? date('M j, Y', strtotime($r['return_date'])) : '—' ?></td>
                            <td>
                                <span style="font-weight:600; color:#d97706;"><?= $r['days_overdue'] ?> day<?= $r['days_overdue'] != 1 ? 's' : '' ?></span>
                            </td>
                            <td class="fw-bold" style="color:#1a3c5e;">₱<?= number_format($r['amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= $r['fine_status'] ?>"><?= ucfirst($r['fine_status']) ?></span>
                                <?php if ($r['paid_at']): ?>
                                <br><small class="text-muted"><?= date('M j, Y', strtotime($r['paid_at'])) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.82rem; color:#6b7280;"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                            <td class="text-center no-print">
                                <?php if ($r['fine_status'] === 'unpaid'): ?>
                                <form method="POST" action="fines.php" class="d-inline">
                                    <input type="hidden" name="fine_id" value="<?= $r['fine_id'] ?>">
                                    <input type="hidden" name="action" value="pay">
                                    <button type="submit" class="btn btn-sm btn-success me-1"
                                            style="border-radius:6px; font-size:0.78rem;"
                                            onclick="return confirm('Mark this fine as paid?')">
                                        <i class="bi bi-check-lg"></i> Paid
                                    </button>
                                </form>
                                <?php if (has_role(['admin'])): ?>
                                <form method="POST" action="fines.php" class="d-inline">
                                    <input type="hidden" name="fine_id" value="<?= $r['fine_id'] ?>">
                                    <input type="hidden" name="action" value="waive">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                                            style="border-radius:6px; font-size:0.78rem;"
                                            onclick="return confirm('Waive this fine? This cannot be undone.')">
                                        <i class="bi bi-x-lg"></i> Waive
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-cash-coin" style="font-size:2.5rem; display:block; margin-bottom:8px; color:#d1d5db;"></i>
                            No fine records found.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>