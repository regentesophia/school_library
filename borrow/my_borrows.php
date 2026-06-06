<?php
// ============================================
// borrow/my_borrows.php - Personal Borrow History
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_login();
$user = current_user();

// Auto-mark overdue
$uid = $user['id'];
$conn->query("UPDATE borrow_records SET status='overdue' WHERE status='borrowed' AND due_date < CURDATE()");

// Filters
$status_f = clean($_GET['status'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where  = "WHERE br.user_id = ?";
$params = [$uid];
$types  = "i";

if ($status_f !== '') {
    $where   .= " AND br.status = ?";
    $params[] = $status_f;
    $types   .= "s";
}

// Count
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM borrow_records br $where");
$cnt->bind_param($types, ...$params);
$cnt->execute();
$total_rows  = $cnt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$cnt->close();

// Fetch records
$sql = "SELECT br.id, br.borrow_date, br.due_date, br.return_date, br.status,
               b.title, b.author, b.cover_image, b.isbn,
               c.name AS category,
               f.amount AS fine_amount, f.status AS fine_status
        FROM borrow_records br
        JOIN books b ON b.id = br.book_id
        JOIN categories c ON c.id = b.category_id
        LEFT JOIN fines f ON f.borrow_id = br.id
        $where
        ORDER BY br.borrow_date DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// Summary stats
$stats_stmt = $conn->prepare(
    "SELECT
        SUM(CASE WHEN status='borrowed' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='returned' THEN 1 ELSE 0 END) AS returned,
        SUM(CASE WHEN status='overdue'  THEN 1 ELSE 0 END) AS overdue
     FROM borrow_records WHERE user_id = ?"
);
$stats_stmt->bind_param("i", $uid);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Unpaid fines
$fine_stmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) AS total FROM fines WHERE user_id = ? AND status = 'unpaid'"
);
$fine_stmt->bind_param("i", $uid);
$fine_stmt->execute();
$total_unpaid = $fine_stmt->get_result()->fetch_assoc()['total'];
$fine_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Books — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-pill { border-radius:12px; padding:14px 20px; display:flex; align-items:center; gap:12px; }
        .stat-pill .num { font-size:1.5rem; font-weight:700; line-height:1; }
        .stat-pill .lbl { font-size:0.78rem; opacity:0.85; }
        .borrow-card { border:1.5px solid #e5e7eb; border-radius:12px; background:white; padding:16px; margin-bottom:12px; transition:box-shadow 0.2s; }
        .borrow-card:hover { box-shadow:0 4px 16px rgba(0,0,0,0.1); }
        .borrow-card.overdue-card { border-color:#fca5a5; background:#fff8f8; }
        .book-thumb { width:52px; height:70px; object-fit:cover; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.12); }
        .badge-borrowed { background:#dbeafe; color:#1d4ed8; }
        .badge-returned { background:#dcfce7; color:#15803d; }
        .badge-overdue  { background:#fee2e2; color:#dc2626; }
        .badge-unpaid   { background:#fef3c7; color:#d97706; }
        .badge-paid     { background:#dcfce7; color:#15803d; }
        .filter-btn { border-radius:20px; padding:6px 16px; font-size:0.82rem; }
        .filter-btn.active { background:#1a3c5e; color:white; border-color:#1a3c5e; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <div class="mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-bookmark-check me-2" style="color:#c8963e;"></i>My Borrowed Books</h1>
        <p class="text-muted mb-0" style="font-size:0.85rem;">
            <?= htmlspecialchars($user['name']) ?> · <?= ucfirst($user['role']) ?>
        </p>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#1d4ed8,#1e40af); color:white;">
                <div><div class="num"><?= $stats['active'] ?? 0 ?></div><div class="lbl">Active</div></div>
                <i class="bi bi-book ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#15803d,#166534); color:white;">
                <div><div class="num"><?= $stats['returned'] ?? 0 ?></div><div class="lbl">Returned</div></div>
                <i class="bi bi-check-circle ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#dc2626,#991b1b); color:white;">
                <div><div class="num"><?= $stats['overdue'] ?? 0 ?></div><div class="lbl">Overdue</div></div>
                <i class="bi bi-clock-history ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#d97706,#b45309); color:white;">
                <div><div class="num">₱<?= number_format($total_unpaid, 2) ?></div><div class="lbl">Unpaid Fines</div></div>
                <i class="bi bi-cash-coin ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
    </div>

    <?php if (($stats['overdue'] ?? 0) > 0): ?>
    <div class="alert alert-danger mb-4" style="border-radius:12px;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        You have <strong><?= $stats['overdue'] ?></strong> overdue book(s). Please return them to the library as soon as possible to avoid additional fines.
    </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
        <a href="my_borrows.php" class="btn btn-outline-secondary filter-btn <?= $status_f==='' ? 'active':'' ?>">All</a>
        <a href="?status=borrowed" class="btn btn-outline-secondary filter-btn <?= $status_f==='borrowed' ? 'active':'' ?>">Active</a>
        <a href="?status=overdue"  class="btn btn-outline-secondary filter-btn <?= $status_f==='overdue'  ? 'active':'' ?>">Overdue</a>
        <a href="?status=returned" class="btn btn-outline-secondary filter-btn <?= $status_f==='returned' ? 'active':'' ?>">Returned</a>
    </div>

    <!-- Borrow Cards -->
    <?php if ($records->num_rows > 0): ?>
        <?php while ($r = $records->fetch_assoc()): ?>
        <div class="borrow-card <?= $r['status']==='overdue' ? 'overdue-card':'' ?>">
            <div class="d-flex gap-3 align-items-start">
                <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($r['cover_image']) ?>"
                     onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                     class="book-thumb flex-shrink-0" alt="Cover">
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h6 class="fw-bold mb-0" style="color:#1a3c5e;"><?= htmlspecialchars($r['title']) ?></h6>
                            <small class="text-muted"><?= htmlspecialchars($r['author']) ?> · <?= htmlspecialchars($r['category']) ?></small>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
                            <?php if ($r['fine_amount'] > 0): ?>
                            <span class="badge badge-<?= $r['fine_status'] ?>">
                                Fine: ₱<?= number_format($r['fine_amount'], 2) ?> (<?= ucfirst($r['fine_status']) ?>)
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-2 mt-2" style="font-size:0.82rem;">
                        <div class="col-sm-4">
                            <span class="text-muted">Borrowed:</span>
                            <strong><?= date('M j, Y', strtotime($r['borrow_date'])) ?></strong>
                        </div>
                        <div class="col-sm-4">
                            <span class="text-muted">Due:</span>
                            <strong class="<?= $r['status']==='overdue' ? 'text-danger':'' ?>">
                                <?= date('M j, Y', strtotime($r['due_date'])) ?>
                            </strong>
                        </div>
                        <div class="col-sm-4">
                            <span class="text-muted">Returned:</span>
                            <strong><?= $r['return_date'] ? date('M j, Y', strtotime($r['return_date'])) : '—' ?></strong>
                        </div>
                    </div>
                    <?php if ($r['status'] === 'overdue'): ?>
                    <div class="mt-2">
                        <?php
                        $days = (new DateTime())->diff(new DateTime($r['due_date']))->days;
                        $est  = $days * FINE_RATE_PER_DAY;
                        ?>
                        <small class="text-danger">
                            <i class="bi bi-clock-history me-1"></i>
                            <?= $days ?> day(s) overdue — estimated fine: <strong>₱<?= number_format($est, 2) ?></strong>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endwhile; ?>

        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i===$page ? 'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_f) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    <?php else: ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-bookmark" style="font-size:3rem; display:block; margin-bottom:12px; color:#d1d5db;"></i>
        <p>No borrow records found.</p>
        <a href="<?= APP_URL ?>/books/index.php" class="btn btn-outline-primary btn-sm">Browse Books</a>
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>