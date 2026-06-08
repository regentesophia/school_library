<?php
// ============================================
// reports/activity.php - Activity Logs
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin']);
$user = current_user();

// Filters
$search   = clean($_GET['search'] ?? '');
$action_f = clean($_GET['action'] ?? '');
$date_from= clean($_GET['date_from'] ?? '');
$date_to  = clean($_GET['date_to']   ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;
$export   = isset($_GET['export']) && $_GET['export'] === 'csv';

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (u.full_name LIKE ? OR u.school_id LIKE ? OR al.details LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($action_f !== '') {
    $where   .= " AND al.action = ?";
    $params[] = $action_f;
    $types   .= "s";
}
if ($date_from !== '') {
    $where   .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types   .= "s";
}
if ($date_to !== '') {
    $where   .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types   .= "s";
}

// Count
$cnt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM activity_logs al JOIN users u ON u.id = al.user_id $where"
);
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_rows  = $cnt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$cnt->close();

$sql = "SELECT al.id, al.action, al.details, al.ip_address, al.created_at,
               u.full_name, u.school_id, u.role
        FROM activity_logs al
        JOIN users u ON u.id = al.user_id
        $where
        ORDER BY al.created_at DESC";

// CSV Export
if ($export) {
    $exp_stmt = $conn->prepare($sql);
    if ($types) $exp_stmt->bind_param($types, ...$params);
    $exp_stmt->execute();
    $exp_data = $exp_stmt->get_result();
    $exp_stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User','School ID','Role','Action','Details','IP Address','Date & Time']);
    while ($r = $exp_data->fetch_assoc()) {
        fputcsv($out, [
            $r['id'], $r['full_name'], $r['school_id'], ucfirst($r['role']),
            $r['action'], $r['details'], $r['ip_address'],
            $r['created_at']
        ]);
    }
    fclose($out);
    exit();
}

$sql .= " LIMIT ? OFFSET ?";
$page_params = array_merge($params, [$per_page, $offset]);
$page_types  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($page_types, ...$page_params);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

// Get distinct actions for filter dropdown
$actions_res = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");

// Action color map
$action_styles = [
    'LOGIN'         => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'icon' => 'box-arrow-in-right'],
    'LOGOUT'        => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'icon' => 'box-arrow-right'],
    'ADD_BOOK'      => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'plus-circle'],
    'EDIT_BOOK'     => ['bg' => '#fef9c3', 'color' => '#a16207', 'icon' => 'pencil'],
    'DELETE_BOOK'   => ['bg' => '#fee2e2', 'color' => '#dc2626', 'icon' => 'trash'],
    'ADD_USER'      => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'person-plus'],
    'EDIT_USER'     => ['bg' => '#fef9c3', 'color' => '#a16207', 'icon' => 'person-gear'],
    'DELETE_USER'   => ['bg' => '#fee2e2', 'color' => '#dc2626', 'icon' => 'person-x'],
    'TOGGLE_STATUS' => ['bg' => '#ede9fe', 'color' => '#6d28d9', 'icon' => 'toggle-on'],
    'ISSUE_BOOK'    => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'icon' => 'arrow-right-circle'],
    'RETURN_BOOK'   => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'arrow-left-circle'],
    'FINE_PAY'      => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'cash-coin'],
    'FINE_WAIVE'    => ['bg' => '#fef3c7', 'color' => '#d97706', 'icon' => 'x-circle'],
];
$default_style = ['bg' => '#f3f4f6', 'color' => '#374151', 'icon' => 'activity'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .action-badge { display:inline-flex; align-items:center; gap:5px; border-radius:6px; padding:3px 10px; font-size:0.75rem; font-weight:600; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
        @media print { .no-print { display:none !important; } body { background:white; } }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-activity me-2" style="color:#c8963e;"></i>Activity Logs</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;"><?= number_format($total_rows) ?> total records</p>
        </div>
        <div class="d-flex gap-2 no-print flex-wrap">
            <a href="?search=<?= urlencode($search) ?>&action=<?= urlencode($action_f) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&export=csv"
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

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                    <input type="text" name="search" class="form-control search-box"
                           placeholder="Name, School ID, or details..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Action</label>
                    <select name="action" class="form-select search-box">
                        <option value="">All Actions</option>
                        <?php while ($a = $actions_res->fetch_assoc()): ?>
                        <option value="<?= $a['action'] ?>" <?= $action_f === $a['action'] ? 'selected':'' ?>>
                            <?= htmlspecialchars($a['action']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">From Date</label>
                    <input type="date" name="date_from" class="form-control search-box" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">To Date</label>
                    <input type="date" name="date_to" class="form-control search-box" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn w-100" style="background:#1a3c5e;color:white;border-radius:10px;">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="activity.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Date & Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                        <?php $style = $action_styles[$log['action']] ?? $default_style; ?>
                        <tr>
                            <td class="ps-4">
                                <div style="font-size:0.85rem; font-weight:600;"><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
                                <small class="text-muted"><?= date('g:i:s A', strtotime($log['created_at'])) ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($log['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($log['school_id']) ?> · <?= ucfirst($log['role']) ?></small>
                            </td>
                            <td>
                                <span class="action-badge"
                                      style="background:<?= $style['bg'] ?>; color:<?= $style['color'] ?>;">
                                    <i class="bi bi-<?= $style['icon'] ?>"></i>
                                    <?= htmlspecialchars($log['action']) ?>
                                </span>
                            </td>
                            <td style="max-width:300px; font-size:0.82rem; color:#374151;">
                                <?= htmlspecialchars($log['details'] ?? '—') ?>
                            </td>
                            <td>
                                <span class="font-monospace text-muted" style="font-size:0.78rem;">
                                    <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-activity" style="font-size:2.5rem; display:block; margin-bottom:8px; color:#d1d5db;"></i>
                            No activity logs found.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3 no-print">
            <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $base = "?search=" . urlencode($search) . "&action=" . urlencode($action_f) .
                            "&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to);
                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);
                    if ($start > 1): ?><li class="page-item"><a class="page-link" href="<?= $base ?>&page=1">1</a></li><?php endif;
                    if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                    for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= $i===$page ? 'active':'' ?>">
                        <a class="page-link" href="<?= $base ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor;
                    if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                    if ($end < $total_pages): ?><li class="page-item"><a class="page-link" href="<?= $base ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a></li><?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>