<?php
// ============================================
// reports/borrows.php - Borrow History Report + CSV Export
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_role(['admin', 'librarian']);
$user = current_user();

// Auto-mark overdue
$conn->query("UPDATE borrow_records SET status='overdue' WHERE status='borrowed' AND due_date < CURDATE()");

// Filters
$search    = clean($_GET['search']     ?? '');
$status_f  = clean($_GET['status']     ?? '');
$role_f    = clean($_GET['role']       ?? '');
$date_from = clean($_GET['date_from']  ?? '');
$date_to   = clean($_GET['date_to']    ?? '');
$export    = isset($_GET['export']) && $_GET['export'] === 'csv';
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 15;
$offset    = ($page - 1) * $per_page;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (u.full_name LIKE ? OR u.school_id LIKE ? OR b.title LIKE ? OR b.isbn LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "ssss";
}
if ($status_f !== '') {
    $where   .= " AND br.status = ?";
    $params[] = $status_f;
    $types   .= "s";
}
if ($role_f !== '') {
    $where   .= " AND u.role = ?";
    $params[] = $role_f;
    $types   .= "s";
}
if ($date_from !== '') {
    $where   .= " AND br.borrow_date >= ?";
    $params[] = $date_from;
    $types   .= "s";
}
if ($date_to !== '') {
    $where   .= " AND br.borrow_date <= ?";
    $params[] = $date_to;
    $types   .= "s";
}

$base_sql = "FROM borrow_records br
             JOIN users u   ON u.id   = br.user_id
             JOIN books b   ON b.id   = br.book_id
             JOIN categories c ON c.id = b.category_id
             JOIN users iss ON iss.id = br.issued_by
             LEFT JOIN fines f ON f.borrow_id = br.id
             $where";

$select_sql = "SELECT br.id, br.borrow_date, br.due_date, br.return_date, br.status, br.remarks,
                      u.full_name, u.school_id, u.role AS borrower_role,
                      u.grade_section, u.department,
                      b.title, b.author, b.isbn, c.name AS category,
                      iss.full_name AS issued_by_name,
                      CASE WHEN br.return_date IS NOT NULL
                           THEN DATEDIFF(br.return_date, br.borrow_date)
                           ELSE DATEDIFF(CURDATE(), br.borrow_date)
                      END AS days_held,
                      f.amount AS fine_amount, f.status AS fine_status";

// CSV Export
if ($export) {
    $exp_sql  = "$select_sql $base_sql ORDER BY br.borrow_date DESC";
    $exp_stmt = $conn->prepare($exp_sql);
    if ($types) $exp_stmt->bind_param($types, ...$params);
    $exp_stmt->execute();
    $exp_data = $exp_stmt->get_result();
    $exp_stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="borrow_history_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID','Borrower','School ID','Role','Grade/Dept',
        'Book Title','Author','ISBN','Category',
        'Borrow Date','Due Date','Return Date','Days Held',
        'Status','Fine Amount','Fine Status','Issued By','Remarks'
    ]);
    while ($r = $exp_data->fetch_assoc()) {
        fputcsv($out, [
            $r['id'], $r['full_name'], $r['school_id'], ucfirst($r['borrower_role']),
            $r['grade_section'] ?? $r['department'] ?? '',
            $r['title'], $r['author'], $r['isbn'], $r['category'],
            $r['borrow_date'], $r['due_date'], $r['return_date'] ?? '',
            $r['days_held'], ucfirst($r['status']),
            $r['fine_amount'] ? '₱'.number_format($r['fine_amount'],2) : '',
            $r['fine_status'] ? ucfirst($r['fine_status']) : '',
            $r['issued_by_name'], $r['remarks'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

// Count
$cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c $base_sql");
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_rows  = $cnt_stmt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$cnt_stmt->close();

// Fetch page
$page_sql = "$select_sql $base_sql ORDER BY br.borrow_date DESC LIMIT ? OFFSET ?";
$page_params = array_merge($params, [$per_page, $offset]);
$page_types  = $types . "ii";

$stmt = $conn->prepare($page_sql);
$stmt->bind_param($page_types, ...$page_params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// Summary stats
$summary = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='borrowed' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='returned' THEN 1 ELSE 0 END) AS returned,
        SUM(CASE WHEN status='overdue'  THEN 1 ELSE 0 END) AS overdue
     FROM borrow_records"
)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow History Report — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-card { border-radius:14px; padding:18px 20px; color:white; }
        .stat-card .val { font-size:1.8rem; font-weight:700; }
        .stat-card .lbl { font-size:0.8rem; opacity:0.85; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.82rem; }
        .badge-borrowed { background:#dbeafe; color:#1d4ed8; }
        .badge-returned { background:#dcfce7; color:#15803d; }
        .badge-overdue  { background:#fee2e2; color:#dc2626; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
        @media print { .no-print { display:none !important; } body { background:white; } .card { box-shadow:none; } }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-journal-text me-2" style="color:#c8963e;"></i>Borrow History Report</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;">
                Generated: <?= date('F j, Y g:i A') ?> · <?= number_format($total_rows) ?> records
            </p>
        </div>
        <div class="d-flex gap-2 no-print flex-wrap">
            <?php
            $q = http_build_query([
                'search'=>$search,'status'=>$status_f,'role'=>$role_f,
                'date_from'=>$date_from,'date_to'=>$date_to,'export'=>'csv'
            ]);
            ?>
            <a href="?<?= $q ?>" class="btn btn-outline-success btn-sm">
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

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#1a3c5e,#0f2540);">
                <div class="val"><?= number_format($summary['total']) ?></div>
                <div class="lbl">Total Records</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#1d4ed8,#1e40af);">
                <div class="val"><?= $summary['active'] ?></div>
                <div class="lbl">Currently Borrowed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#15803d,#166534);">
                <div class="val"><?= $summary['returned'] ?></div>
                <div class="lbl">Returned</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background:linear-gradient(135deg,#dc2626,#991b1b);">
                <div class="val"><?= $summary['overdue'] ?></div>
                <div class="lbl">Overdue</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                    <input type="text" name="search" class="form-control search-box"
                           placeholder="Name, ID, title, ISBN..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select search-box">
                        <option value="">All</option>
                        <option value="borrowed" <?= $status_f==='borrowed'?'selected':'' ?>>Borrowed</option>
                        <option value="returned" <?= $status_f==='returned'?'selected':'' ?>>Returned</option>
                        <option value="overdue"  <?= $status_f==='overdue' ?'selected':'' ?>>Overdue</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Role</label>
                    <select name="role" class="form-select search-box">
                        <option value="">All Roles</option>
                        <option value="student" <?= $role_f==='student'?'selected':'' ?>>Student</option>
                        <option value="teacher" <?= $role_f==='teacher'?'selected':'' ?>>Teacher</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">From</label>
                    <input type="date" name="date_from" class="form-control search-box" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-semibold text-muted mb-1">To</label>
                    <input type="date" name="date_to" class="form-control search-box" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn w-100" style="background:#1a3c5e;color:white;border-radius:10px;font-size:0.82rem;">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="borrows.php" class="btn btn-outline-secondary w-100" style="font-size:0.82rem;">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Borrower</th>
                            <th>Book</th>
                            <th>Category</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Days</th>
                            <th>Fine</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records->num_rows > 0): ?>
                        <?php while ($r = $records->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 text-muted"><?= $r['id'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['school_id']) ?> · <?= ucfirst($r['borrower_role']) ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars(strlen($r['title'])>30?substr($r['title'],0,30).'…':$r['title']) ?></div>
                                <small class="text-muted font-monospace"><?= htmlspecialchars($r['isbn']) ?></small>
                            </td>
                            <td><span class="badge" style="background:#e8f0fe;color:#1a3c5e;font-size:0.7rem;"><?= htmlspecialchars($r['category']) ?></span></td>
                            <td><?= date('M j, Y', strtotime($r['borrow_date'])) ?></td>
                            <td class="<?= $r['status']==='overdue'?'text-danger fw-semibold':'' ?>"><?= date('M j, Y', strtotime($r['due_date'])) ?></td>
                            <td><?= $r['return_date'] ? date('M j, Y', strtotime($r['return_date'])) : '—' ?></td>
                            <td class="text-muted"><?= $r['days_held'] ?></td>
                            <td class="<?= $r['fine_amount']>0?'text-danger fw-semibold':'' ?>">
                                <?= $r['fine_amount']>0 ? '₱'.number_format($r['fine_amount'],2) : '—' ?>
                            </td>
                            <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">No records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3 no-print">
            <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$per_page, $total_rows) ?> of <?= $total_rows ?></small>
            <nav><ul class="pagination pagination-sm mb-0">
                <?php
                $bq = "search=".urlencode($search)."&status=".urlencode($status_f)."&role=".urlencode($role_f)."&date_from=".urlencode($date_from)."&date_to=".urlencode($date_to);
                $s = max(1,$page-2); $e = min($total_pages,$page+2);
                if($s>1): ?><li class="page-item"><a class="page-link" href="?<?=$bq?>&page=1">1</a></li><?php endif;
                if($s>2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                for($i=$s;$i<=$e;$i++): ?>
                <li class="page-item <?=$i===$page?'active':''?>"><a class="page-link" href="?<?=$bq?>&page=<?=$i?>"><?=$i?></a></li>
                <?php endfor;
                if($e<$total_pages-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                if($e<$total_pages): ?><li class="page-item"><a class="page-link" href="?<?=$bq?>&page=<?=$total_pages?>"><?=$total_pages?></a></li><?php endif; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>