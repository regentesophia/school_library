<?php
// ============================================
// reports/overdue.php - Overdue Books Report
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

// Auto-mark overdue
$conn->query("UPDATE borrow_records SET status='overdue' WHERE status='borrowed' AND due_date < CURDATE()");

// Filters
$search   = clean($_GET['search'] ?? '');
$role_f   = clean($_GET['role'] ?? '');
$export   = isset($_GET['export']) && $_GET['export'] === 'csv';

$where  = "WHERE br.status = 'overdue'";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (u.full_name LIKE ? OR u.school_id LIKE ? OR b.title LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($role_f !== '') {
    $where   .= " AND u.role = ?";
    $params[] = $role_f;
    $types   .= "s";
}

$sql = "SELECT br.id, br.borrow_date, br.due_date,
               DATEDIFF(CURDATE(), br.due_date) AS days_overdue,
               DATEDIFF(CURDATE(), br.due_date) * ? AS estimated_fine,
               u.full_name, u.school_id, u.role, u.email,
               u.grade_section, u.department,
               b.title, b.author, b.isbn,
               f.amount AS fine_amount, f.status AS fine_status
        FROM borrow_records br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        LEFT JOIN fines f ON f.borrow_id = br.id
        $where
        ORDER BY days_overdue DESC";

$all_params = array_merge([FINE_RATE_PER_DAY], $params);
$all_types  = "d" . $types;

$stmt = $conn->prepare($sql);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// ---- CSV Export ----
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="overdue_books_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Borrower', 'School ID', 'Role', 'Email', 'Book Title', 'Author', 'ISBN', 'Borrow Date', 'Due Date', 'Days Overdue', 'Estimated Fine']);
    $i = 1;
    while ($r = $records->fetch_assoc()) {
        fputcsv($out, [
            $i++, $r['full_name'], $r['school_id'], ucfirst($r['role']), $r['email'],
            $r['title'], $r['author'], $r['isbn'],
            $r['borrow_date'], $r['due_date'],
            $r['days_overdue'], '₱' . number_format($r['estimated_fine'], 2)
        ]);
    }
    fclose($out);
    exit();
}

// Summary stats
$total_overdue    = $records->num_rows;
$total_fine_est   = 0;
$rows             = [];
while ($r = $records->fetch_assoc()) {
    $total_fine_est += $r['estimated_fine'];
    $rows[]          = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overdue Report — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-card { border-radius:14px; padding:20px; color:white; }
        .stat-card .val { font-size:2rem; font-weight:700; }
        .stat-card .lbl { font-size:0.8rem; opacity:0.85; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .overdue-badge { background:#fee2e2; color:#dc2626; border-radius:6px; padding:2px 8px; font-size:0.78rem; font-weight:600; }
        .days-critical { color:#dc2626; font-weight:700; }
        .days-warning  { color:#d97706; font-weight:700; }
        .days-mild     { color:#1d4ed8; font-weight:600; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        @media print {
            .no-print { display:none !important; }
            body { background:white; }
            .card { box-shadow:none; border:1px solid #e5e7eb !important; }
        }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-clock-history me-2" style="color:#c8963e;"></i>Overdue Books Report</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;">Generated: <?= date('F j, Y g:i A') ?></p>
        </div>
        <div class="d-flex gap-2 no-print flex-wrap">
            <a href="?search=<?= urlencode($search) ?>&role=<?= urlencode($role_f) ?>&export=csv"
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
        <div class="col-md-4">
            <div class="stat-card" style="background:linear-gradient(135deg,#dc2626,#991b1b);">
                <div class="val"><?= $total_overdue ?></div>
                <div class="lbl">Total Overdue Books</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card" style="background:linear-gradient(135deg,#d97706,#b45309);">
                <div class="val">₱<?= number_format($total_fine_est, 2) ?></div>
                <div class="lbl">Total Estimated Fines</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card" style="background:linear-gradient(135deg,#1a3c5e,#0f2540);">
                <div class="val"><?= $total_overdue > 0 ? round($total_fine_est / $total_overdue, 2) : '0.00' ?></div>
                <div class="lbl">Avg. Fine per Record</div>
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
                    <label class="form-label small fw-semibold text-muted mb-1">Role</label>
                    <select name="role" class="form-select search-box">
                        <option value="">All Roles</option>
                        <option value="student" <?= $role_f==='student' ? 'selected':'' ?>>Student</option>
                        <option value="teacher" <?= $role_f==='teacher' ? 'selected':'' ?>>Teacher</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn w-100" style="background:#1a3c5e;color:white;border-radius:10px;">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="overdue.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="overdueTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Borrower</th>
                            <th>Book</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Days Overdue</th>
                            <th>Est. Fine</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $i => $r): ?>
                        <?php
                            $d = $r['days_overdue'];
                            $cls = $d >= 30 ? 'days-critical' : ($d >= 14 ? 'days-warning' : 'days-mild');
                        ?>
                        <tr>
                            <td class="ps-4 text-muted"><?= $i + 1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['school_id']) ?> · <?= ucfirst($r['role']) ?></small>
                                <?php if ($r['grade_section'] || $r['department']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($r['grade_section'] ?? $r['department']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['title']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['author']) ?></small>
                            </td>
                            <td style="font-size:0.82rem;"><?= date('M j, Y', strtotime($r['borrow_date'])) ?></td>
                            <td style="font-size:0.82rem;" class="text-danger fw-semibold"><?= date('M j, Y', strtotime($r['due_date'])) ?></td>
                            <td>
                                <span class="<?= $cls ?>">
                                    <i class="bi bi-clock me-1"></i><?= $d ?> day<?= $d != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td class="fw-bold text-danger">₱<?= number_format($r['estimated_fine'], 2) ?></td>
                            <td class="no-print">
                                <a href="<?= APP_URL ?>/borrow/return.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-success" style="border-radius:6px; font-size:0.8rem;">
                                    <i class="bi bi-check-lg me-1"></i>Return
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem; display:block; margin-bottom:8px;"></i>
                            No overdue books. Great job!
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($rows)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6" class="ps-4 fw-bold text-end pe-3">Total Estimated Fines:</td>
                            <td class="fw-bold text-danger">₱<?= number_format($total_fine_est, 2) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>