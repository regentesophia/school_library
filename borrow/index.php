<?php
// ============================================
// borrow/index.php - Borrow Management
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

// Auto-mark overdue records
$conn->query(
    "UPDATE borrow_records SET status = 'overdue'
     WHERE status = 'borrowed' AND due_date < CURDATE()"
);

// Search & filter
$search     = clean($_GET['search'] ?? '');
$status_f   = clean($_GET['status'] ?? '');
$page       = max(1, intval($_GET['page'] ?? 1));
$per_page   = 12;
$offset     = ($page - 1) * $per_page;

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
    $where   .= " AND br.status = ?";
    $params[] = $status_f;
    $types   .= "s";
}

// Count
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM borrow_records br JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id $where");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_rows  = $cnt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$cnt->close();

// Fetch records
$sql = "SELECT br.id, br.borrow_date, br.due_date, br.return_date, br.status, br.remarks,
               u.id AS user_id, u.full_name, u.school_id, u.role,
               b.id AS book_id, b.title, b.author, b.cover_image,
               iss.full_name AS issued_by_name
        FROM borrow_records br
        JOIN users u   ON u.id   = br.user_id
        JOIN books b   ON b.id   = br.book_id
        JOIN users iss ON iss.id = br.issued_by
        $where
        ORDER BY br.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// Summary counts
$summary = [];
$sr = $conn->query("SELECT status, COUNT(*) AS c FROM borrow_records GROUP BY status");
while ($row = $sr->fetch_assoc()) $summary[$row['status']] = $row['c'];
$summary['borrowed'] = $summary['borrowed'] ?? 0;
$summary['returned'] = $summary['returned'] ?? 0;
$summary['overdue']  = $summary['overdue']  ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowing — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-pill { border-radius:12px; padding:14px 20px; color:white; display:flex; align-items:center; gap:12px; }
        .stat-pill .num { font-size:1.5rem; font-weight:700; line-height:1; }
        .stat-pill .lbl { font-size:0.78rem; opacity:0.85; }
        .book-thumb { width:36px; height:48px; object-fit:cover; border-radius:4px; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .badge-borrowed { background:#dbeafe; color:#1d4ed8; }
        .badge-returned { background:#dcfce7; color:#15803d; }
        .badge-overdue  { background:#fee2e2; color:#dc2626; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        .btn-primary-custom { background:#1a3c5e; color:white; border:none; border-radius:8px; padding:8px 18px; font-size:0.875rem; font-weight:600; }
        .btn-primary-custom:hover { background:#0f2540; color:white; }
        .action-btn { padding:4px 10px; border-radius:6px; font-size:0.8rem; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
        .overdue-row { background:#fff8f8 !important; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-arrow-left-right me-2" style="color:#c8963e;"></i>Borrowing Management</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;"><?= number_format($total_rows) ?> record<?= $total_rows != 1 ? 's' : '' ?> found</p>
        </div>
        <a href="issue.php" class="btn btn-primary-custom">
            <i class="bi bi-plus-lg me-1"></i>Issue Book
        </a>
    </div>

    <!-- Summary Pills -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="stat-pill" style="background:linear-gradient(135deg,#1d4ed8,#1e40af);">
                <div><div class="num"><?= $summary['borrowed'] ?></div><div class="lbl">Active Borrows</div></div>
                <i class="bi bi-book ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-pill" style="background:linear-gradient(135deg,#15803d,#166534);">
                <div><div class="num"><?= $summary['returned'] ?></div><div class="lbl">Returned</div></div>
                <i class="bi bi-check-circle ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-pill" style="background:linear-gradient(135deg,#dc2626,#991b1b);">
                <div><div class="num"><?= $summary['overdue'] ?></div><div class="lbl">Overdue</div></div>
                <i class="bi bi-clock-history ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
    </div>

    <!-- Search & Filter -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0" style="border:1.5px solid #e5e7eb; border-radius:10px 0 0 10px;">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" name="search" class="form-control search-box"
                               style="border-left:none; border-radius:0 10px 10px 0;"
                               placeholder="Borrower name, School ID, or book title..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select search-box">
                        <option value="">All Status</option>
                        <option value="borrowed" <?= $status_f==='borrowed' ? 'selected':'' ?>>Borrowed</option>
                        <option value="returned" <?= $status_f==='returned' ? 'selected':'' ?>>Returned</option>
                        <option value="overdue"  <?= $status_f==='overdue'  ? 'selected':'' ?>>Overdue</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="index.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Records Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Book</th>
                            <th>Borrower</th>
                            <th>Issued By</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records->num_rows > 0): ?>
                        <?php while ($r = $records->fetch_assoc()): ?>
                        <tr class="<?= $r['status'] === 'overdue' ? 'overdue-row' : '' ?>">
                            <td class="ps-4 text-muted" style="font-size:0.8rem;"><?= $r['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($r['cover_image']) ?>"
                                         onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                                         class="book-thumb" alt="cover">
                                    <div>
                                        <div class="fw-semibold" style="max-width:160px;" title="<?= htmlspecialchars($r['title']) ?>">
                                            <?= htmlspecialchars(strlen($r['title']) > 28 ? substr($r['title'],0,28).'…' : $r['title']) ?>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($r['author']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['school_id']) ?> · <?= ucfirst($r['role']) ?></small>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['issued_by_name']) ?></small></td>
                            <td style="font-size:0.82rem;"><?= date('M j, Y', strtotime($r['borrow_date'])) ?></td>
                            <td style="font-size:0.82rem;" class="<?= $r['status']==='overdue' ? 'text-danger fw-semibold' : '' ?>">
                                <?= date('M j, Y', strtotime($r['due_date'])) ?>
                                <?php if ($r['status']==='overdue'): ?>
                                <div style="font-size:0.75rem;"><?= (new DateTime())->diff(new DateTime($r['due_date']))->days ?> day(s) ago</div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.82rem;"><?= $r['return_date'] ? date('M j, Y', strtotime($r['return_date'])) : '—' ?></td>
                            <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td class="text-center">
                                <?php if ($r['status'] !== 'returned'): ?>
                                <a href="return.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-success action-btn" title="Mark Returned">
                                    <i class="bi bi-check-lg me-1"></i>Return
                                </a>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-arrow-left-right" style="font-size:2rem; display:block; margin-bottom:8px;"></i>
                            No borrow records found.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3">
            <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i===$page ? 'active':'' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>