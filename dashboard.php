<?php
// ============================================
// dashboard.php - Main Dashboard
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

require_login();
$user = current_user();

// ============================================
// Fetch Dashboard Stats
// ============================================

// Total books
$total_books = $conn->query("SELECT COUNT(*) AS c FROM books")->fetch_assoc()['c'];

// Total users (excluding admins from count display)
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role != 'admin'")->fetch_assoc()['c'];

// Active borrows
$active_borrows = $conn->query("SELECT COUNT(*) AS c FROM borrow_records WHERE status = 'borrowed'")->fetch_assoc()['c'];

// Overdue books
$overdue = $conn->query(
    "SELECT COUNT(*) AS c FROM borrow_records WHERE status = 'borrowed' AND due_date < CURDATE()"
)->fetch_assoc()['c'];

// Unpaid fines
$unpaid_fines = $conn->query(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM fines WHERE status = 'unpaid'"
)->fetch_assoc()['total'];

// Recent borrows (last 5)
$recent_borrows = $conn->query(
    "SELECT br.id, u.full_name, u.school_id, b.title, br.borrow_date, br.due_date, br.status
     FROM borrow_records br
     JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     ORDER BY br.created_at DESC
     LIMIT 5"
);

// For students/teachers: their own borrows
$my_borrows = null;
if (has_role(['student', 'teacher'])) {
    $uid = $user['id'];
    $stmt = $conn->prepare(
        "SELECT br.id, b.title, b.author, br.borrow_date, br.due_date, br.status
         FROM borrow_records br
         JOIN books b ON b.id = br.book_id
         WHERE br.user_id = ?
         ORDER BY br.borrow_date DESC
         LIMIT 5"
    );
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $my_borrows = $stmt->get_result();
    $stmt->close();
}

// Low availability books (available_copies <= 1)
$low_books = $conn->query(
    "SELECT title, author, available_copies, total_copies
     FROM books WHERE available_copies <= 1 AND status = 'available'
     LIMIT 5"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #f4f1eb; color: #1e1e1e; }
        .stat-card {
            border: none; border-radius: 14px;
            padding: 24px; color: white;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card .icon { font-size: 2.2rem; opacity: 0.85; }
        .stat-card .value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-card .label { font-size: 0.8rem; opacity: 0.85; margin-top: 4px; }
        .card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .card-header {
            background: white; border-bottom: 1px solid #f0ede6;
            border-radius: 14px 14px 0 0 !important;
            font-weight: 600; color: #1a3c5e; padding: 16px 20px;
        }
        .section-title {
            font-family: 'Playfair Display', serif;
            color: #1a3c5e; font-size: 1.2rem;
        }
        .badge-status-borrowed  { background: #dbeafe; color: #1d4ed8; }
        .badge-status-returned  { background: #dcfce7; color: #15803d; }
        .badge-status-overdue   { background: #fee2e2; color: #dc2626; }
        .table th { font-size: 0.78rem; text-transform: uppercase;
                    letter-spacing: 0.05em; color: #6b7280; }
        .page-header { padding: 28px 0 12px; }
        .greeting { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: #1a3c5e; }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Page Header -->
    <div class="page-header mb-4">
        <h1 class="greeting">
            Good <?= (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) ?>,
            <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?> 👋
        </h1>
        <p class="text-muted" style="font-size:0.875rem;">
            <?= date('l, F j, Y') ?> &mdash; <?= ucfirst($user['role']) ?> Portal
        </p>
    </div>

    <?php if (has_role(['admin', 'librarian'])): ?>
    <!-- ======================== STATS CARDS ======================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #1a3c5e, #0f2540);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="value"><?= number_format($total_books) ?></div>
                        <div class="label">Total Books</div>
                    </div>
                    <div class="icon"><i class="bi bi-journals"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #c8963e, #a07030);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="value"><?= number_format($total_users) ?></div>
                        <div class="label">Registered Users</div>
                    </div>
                    <div class="icon"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #1e6b3c, #154d2b);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="value"><?= number_format($active_borrows) ?></div>
                        <div class="label">Active Borrows</div>
                    </div>
                    <div class="icon"><i class="bi bi-arrow-left-right"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #991b1b, #7f1d1d);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="value"><?= number_format($overdue) ?></div>
                        <div class="label">Overdue Books</div>
                    </div>
                    <div class="icon"><i class="bi bi-clock-history"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== RECENT BORROWS TABLE ======================== -->
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-arrow-left-right me-2"></i>Recent Borrowing Activity</span>
                    <a href="<?= APP_URL ?>/borrow/index.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Borrower</th>
                                    <th>Book</th>
                                    <th>Borrow Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($recent_borrows->num_rows > 0): ?>
                                <?php while ($row = $recent_borrows->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-600"><?= htmlspecialchars($row['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['school_id']) ?></small>
                                    </td>
                                    <td style="max-width:180px;">
                                        <div class="text-truncate"><?= htmlspecialchars($row['title']) ?></div>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($row['borrow_date'])) ?></td>
                                    <td><?= date('M j, Y', strtotime($row['due_date'])) ?></td>
                                    <td>
                                        <?php
                                        $s = $row['status'];
                                        if ($s === 'borrowed' && $row['due_date'] < date('Y-m-d')) $s = 'overdue';
                                        ?>
                                        <span class="badge badge-status-<?= $s ?>">
                                            <?= ucfirst($s) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No borrowing records yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Availability Alert -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <span class="section-title"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Low Availability</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($low_books->num_rows > 0): ?>
                    <ul class="list-group list-group-flush">
                        <?php while ($b = $low_books->fetch_assoc()): ?>
                        <li class="list-group-item px-4">
                            <div class="fw-semibold" style="font-size:0.875rem;"><?= htmlspecialchars($b['title']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($b['author']) ?></small>
                            <div class="mt-1">
                                <span class="badge bg-warning text-dark">
                                    <?= $b['available_copies'] ?> / <?= $b['total_copies'] ?> available
                                </span>
                            </div>
                        </li>
                        <?php endwhile; ?>
                    </ul>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">All books have sufficient copies.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; // end admin/librarian ?>

    <!-- ======================== STUDENT/TEACHER VIEW ======================== -->
    <?php if (has_role(['student', 'teacher']) && $my_borrows): ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-bookmark-check me-2"></i>My Borrowed Books</span>
                    <a href="<?= APP_URL ?>/borrow/my_borrows.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Book Title</th>
                                    <th>Author</th>
                                    <th>Borrow Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($my_borrows->num_rows > 0): ?>
                                <?php while ($row = $my_borrows->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold"><?= htmlspecialchars($row['title']) ?></td>
                                    <td><?= htmlspecialchars($row['author']) ?></td>
                                    <td><?= date('M j, Y', strtotime($row['borrow_date'])) ?></td>
                                    <td><?= date('M j, Y', strtotime($row['due_date'])) ?></td>
                                    <td>
                                        <?php
                                        $s = $row['status'];
                                        if ($s === 'borrowed' && $row['due_date'] < date('Y-m-d')) $s = 'overdue';
                                        ?>
                                        <span class="badge badge-status-<?= $s ?>"><?= ucfirst($s) ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">You have no borrowed books.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>