<?php
// ============================================
// reservations/index.php - My Reservations (Student/Teacher)
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_login();
$user = current_user();

// Auto-expire reservations past expiry date
$conn->query("UPDATE reservations SET status='cancelled' WHERE status='pending' AND expiry_date < CURDATE()");

$status_f = clean($_GET['status'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

// For admin/librarian: show all; for student/teacher: show only theirs
$where  = "WHERE 1=1";
$params = [];
$types  = "";

if (has_role(['student', 'teacher'])) {
    $where   .= " AND r.user_id = ?";
    $params[] = $user['id'];
    $types   .= "i";
}
if ($status_f !== '') {
    $where   .= " AND r.status = ?";
    $params[] = $status_f;
    $types   .= "s";
}

// Count
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM reservations r $where");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_rows  = $cnt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$cnt->close();

// Fetch
$sql = "SELECT r.id, r.reserved_at, r.expiry_date, r.status,
               u.full_name, u.school_id, u.role,
               b.id AS book_id, b.title, b.author, b.cover_image, b.available_copies
        FROM reservations r
        JOIN users u ON u.id = r.user_id
        JOIN books b ON b.id = r.book_id
        $where
        ORDER BY r.reserved_at DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$reservations = $stmt->get_result();
$stmt->close();

// Summary counts for current user (or all for admin/librarian)
$sum_where  = has_role(['student','teacher']) ? "WHERE user_id = {$user['id']}" : "";
$stats = $conn->query(
    "SELECT status, COUNT(*) AS c FROM reservations $sum_where GROUP BY status"
)->fetch_all(MYSQLI_ASSOC);
$stat_map = array_column($stats, 'c', 'status');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations — <?= APP_NAME ?></title>
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
        .book-thumb { width:44px; height:60px; object-fit:cover; border-radius:5px; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .badge-pending   { background:#dbeafe; color:#1d4ed8; }
        .badge-approved  { background:#dcfce7; color:#15803d; }
        .badge-fulfilled { background:#ede9fe; color:#6d28d9; }
        .badge-cancelled { background:#f3f4f6; color:#6b7280; }
        .filter-tab { border:none; background:none; padding:8px 18px; font-weight:600; font-size:0.85rem; color:#6b7280; border-bottom:3px solid transparent; cursor:pointer; }
        .filter-tab.active { color:#1a3c5e; border-bottom-color:#1a3c5e; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-bookmark-star me-2" style="color:#c8963e;"></i>
                <?= has_role(['admin','librarian']) ? 'All Reservations' : 'My Reservations' ?>
            </h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;"><?= number_format($total_rows) ?> record<?= $total_rows != 1 ? 's':'' ?></p>
        </div>
        <?php if (has_role(['student','teacher'])): ?>
        <a href="create.php" class="btn" style="background:#1a3c5e;color:white;border-radius:8px;padding:8px 18px;font-size:0.875rem;font-weight:600;">
            <i class="bi bi-plus-lg me-1"></i>Reserve a Book
        </a>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#1d4ed8,#1e40af);">
                <div><div class="num"><?= $stat_map['pending'] ?? 0 ?></div><div class="lbl">Pending</div></div>
                <i class="bi bi-hourglass ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#15803d,#166534);">
                <div><div class="num"><?= $stat_map['approved'] ?? 0 ?></div><div class="lbl">Approved</div></div>
                <i class="bi bi-check-circle ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#6d28d9,#4c1d95);">
                <div><div class="num"><?= $stat_map['fulfilled'] ?? 0 ?></div><div class="lbl">Fulfilled</div></div>
                <i class="bi bi-bookmark-check ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:linear-gradient(135deg,#6b7280,#374151);">
                <div><div class="num"><?= $stat_map['cancelled'] ?? 0 ?></div><div class="lbl">Cancelled</div></div>
                <i class="bi bi-x-circle ms-auto" style="font-size:1.8rem;opacity:0.4;"></i>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="d-flex border-bottom mb-4">
        <?php foreach ([''=>'All', 'pending'=>'Pending', 'approved'=>'Approved', 'fulfilled'=>'Fulfilled', 'cancelled'=>'Cancelled'] as $val => $lbl): ?>
        <a href="?status=<?= $val ?>" class="filter-tab <?= $status_f===$val ? 'active':'' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Book</th>
                            <?php if (has_role(['admin','librarian'])): ?>
                            <th>Reserved By</th>
                            <?php endif; ?>
                            <th>Reserved On</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($reservations->num_rows > 0): ?>
                        <?php while ($r = $reservations->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($r['cover_image']) ?>"
                                         onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                                         class="book-thumb" alt="cover">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($r['title']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($r['author']) ?></small>
                                        <div>
                                            <span class="badge" style="background:#f3f4f6; color:#374151; font-size:0.7rem;">
                                                <?= $r['available_copies'] ?> available
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <?php if (has_role(['admin','librarian'])): ?>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['school_id']) ?> · <?= ucfirst($r['role']) ?></small>
                            </td>
                            <?php endif; ?>
                            <td style="font-size:0.82rem;"><?= date('M j, Y g:i A', strtotime($r['reserved_at'])) ?></td>
                            <td style="font-size:0.82rem;" class="<?= strtotime($r['expiry_date']) < time() && $r['status']==='pending' ? 'text-danger fw-semibold':'' ?>">
                                <?= date('M j, Y', strtotime($r['expiry_date'])) ?>
                            </td>
                            <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td class="text-center">
                                <?php if (has_role(['admin','librarian']) && $r['status'] === 'pending'): ?>
                                    <a href="approve.php?id=<?= $r['id'] ?>"
                                       class="btn btn-sm btn-success me-1" style="border-radius:6px;font-size:0.78rem;"
                                       onclick="return confirm('Approve this reservation?')">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </a>
                                    <a href="cancel.php?id=<?= $r['id'] ?>"
                                       class="btn btn-sm btn-outline-danger" style="border-radius:6px;font-size:0.78rem;"
                                       onclick="return confirm('Cancel this reservation?')">
                                        <i class="bi bi-x-lg"></i>
                                    </a>
                                <?php elseif (has_role(['admin','librarian']) && $r['status'] === 'approved'): ?>
                                    <a href="fulfill.php?id=<?= $r['id'] ?>"
                                       class="btn btn-sm btn-primary me-1" style="border-radius:6px;font-size:0.78rem;background:#1a3c5e;border:none;"
                                       onclick="return confirm('Mark as fulfilled and issue the book?')">
                                        <i class="bi bi-arrow-right-circle"></i> Issue
                                    </a>
                                <?php elseif (has_role(['student','teacher']) && $r['status'] === 'pending'): ?>
                                    <a href="cancel.php?id=<?= $r['id'] ?>"
                                       class="btn btn-sm btn-outline-danger" style="border-radius:6px;font-size:0.78rem;"
                                       onclick="return confirm('Cancel this reservation?')">
                                        <i class="bi bi-x-lg me-1"></i>Cancel
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-bookmark-star" style="font-size:2.5rem;display:block;margin-bottom:8px;color:#d1d5db;"></i>
                            No reservations found.
                            <?php if (has_role(['student','teacher'])): ?>
                            <br><a href="create.php" class="btn btn-outline-primary btn-sm mt-2">Reserve a Book</a>
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3">
            <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></small>
            <nav><ul class="pagination pagination-sm mb-0">
                <?php for ($i=1;$i<=$total_pages;$i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_f) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>