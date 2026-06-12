<?php
// ============================================
// users/index.php - User Management List
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin']);
$user = current_user();

// ============================================
// Search & Filter
// ============================================
$search      = clean($_GET['search'] ?? '');
$role_filter = clean($_GET['role'] ?? '');
$status_filter = clean($_GET['status'] ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = 12;
$offset      = ($page - 1) * $per_page;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (full_name LIKE ? OR school_id LIKE ? OR email LIKE ?)";
    $like     = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($role_filter !== '') {
    $where   .= " AND role = ?";
    $params[] = $role_filter;
    $types   .= "s";
}
if ($status_filter !== '') {
    $where   .= " AND status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users $where");
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows  = $count_stmt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$count_stmt->close();

// Fetch users
$sql = "SELECT id, school_id, full_name, email, role, grade_section, department,
               profile_pic, status, created_at
        FROM users $where ORDER BY role ASC, full_name ASC LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset;
$types   .= "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result();
$stmt->close();

// Role counts for summary badges
$role_counts = [];
$rc = $conn->query("SELECT role, COUNT(*) AS c FROM users GROUP BY role");
while ($r = $rc->fetch_assoc()) $role_counts[$r['role']] = $r['c'];

$role_colors = [
    'admin'     => ['bg' => '#1a3c5e', 'label' => 'Admin'],
    'librarian' => ['bg' => '#c8963e', 'label' => 'Librarian'],
    'student'   => ['bg' => '#1e6b3c', 'label' => 'Student'],
    'teacher'   => ['bg' => '#4f46e5', 'label' => 'Teacher'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .stat-pill {
            border-radius:12px; padding:14px 20px; color:white;
            display:flex; align-items:center; gap:12px;
        }
        .stat-pill .num { font-size:1.5rem; font-weight:700; line-height:1; }
        .stat-pill .lbl { font-size:0.78rem; opacity:0.85; }
        .avatar {
            width:40px; height:40px; border-radius:50%;
            object-fit:cover; border:2px solid #e5e7eb;
        }
        .avatar-initials {
            width:40px; height:40px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-weight:700; font-size:0.85rem; color:white;
        }
        .table th { font-size:0.78rem; text-transform:uppercase;
                    letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .badge-active   { background:#dcfce7; color:#15803d; }
        .badge-inactive { background:#fee2e2; color:#dc2626; }
        .role-badge { font-size:0.72rem; padding:3px 9px; border-radius:20px; color:white; }
        .search-box { border-radius:10px; border:1.5px solid #e5e7eb; padding:10px 14px; }
        .search-box:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); outline:none; }
        .btn-primary-custom { background:#1a3c5e; color:white; border:none;
            border-radius:8px; padding:8px 18px; font-size:0.875rem; font-weight:600; }
        .btn-primary-custom:hover { background:#0f2540; color:white; }
        .action-btn { padding:4px 10px; border-radius:6px; font-size:0.8rem; }
        .page-link { color:#1a3c5e; }
        .page-item.active .page-link { background:#1a3c5e; border-color:#1a3c5e; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-people me-2" style="color:#c8963e;"></i>User Management</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;"><?= number_format($total_rows) ?> user<?= $total_rows != 1 ? 's' : '' ?> found</p>
        </div>
        <a href="add.php" class="btn btn-primary-custom">
            <i class="bi bi-person-plus me-1"></i>Add User
        </a>
    </div>

    <!-- Role Summary Pills -->
    <div class="row g-3 mb-4">
        <?php foreach ($role_colors as $role => $meta): ?>
        <div class="col-6 col-md-3">
            <div class="stat-pill" style="background:<?= $meta['bg'] ?>;">
                <div>
                    <div class="num"><?= $role_counts[$role] ?? 0 ?></div>
                    <div class="lbl"><?= $meta['label'] ?><?= ($role_counts[$role] ?? 0) != 1 ? 's' : '' ?></div>
                </div>
                <i class="bi bi-<?= $role === 'admin' ? 'shield-check' : ($role === 'librarian' ? 'person-workspace' : ($role === 'student' ? 'mortarboard' : 'person-badge')) ?> ms-auto" style="font-size:1.8rem; opacity:0.5;"></i>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Search & Filter -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0" style="border:1.5px solid #e5e7eb; border-radius:10px 0 0 10px;">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" name="search" class="form-control search-box"
                               style="border-left:none; border-radius:0 10px 10px 0;"
                               placeholder="Name, School ID, or email..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Role</label>
                    <select name="role" class="form-select search-box">
                        <option value="">All Roles</option>
                        <?php foreach ($role_colors as $role => $meta): ?>
                        <option value="<?= $role ?>" <?= $role_filter === $role ? 'selected' : '' ?>><?= $meta['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select search-box">
                        <option value="">All Status</option>
                        <option value="active"   <?= $status_filter === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="index.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Users Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">User</th>
                            <th>School ID</th>
                            <th>Role</th>
                            <th>Section / Dept.</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($users->num_rows > 0): ?>
                        <?php while ($u = $users->fetch_assoc()): ?>
                        <?php
                            $initials = strtoupper(substr($u['full_name'], 0, 1));
                            $bg = $role_colors[$u['role']]['bg'] ?? '#6b7280';
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($u['profile_pic'] && $u['profile_pic'] !== 'default.png'): ?>
                                    <img src="<?= APP_URL ?>/uploads/profiles/<?= htmlspecialchars($u['profile_pic']) ?>"
                                         class="avatar" alt="Avatar"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="avatar-initials" style="background:<?= $bg ?>; display:none;"><?= $initials ?></div>
                                    <?php else: ?>
                                    <div class="avatar-initials" style="background:<?= $bg ?>;"><?= $initials ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="font-monospace text-muted" style="font-size:0.82rem;"><?= htmlspecialchars($u['school_id']) ?></span></td>
                            <td>
                                <span class="role-badge" style="background:<?= $bg ?>;">
                                    <?= $role_colors[$u['role']]['label'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size:0.82rem;">
                                    <?= htmlspecialchars(
                                        !empty($u['grade_section']) 
                                            ? $u['grade_section'] 
                                            : (!empty($u['department']) ? $u['department'] : '—')
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span>
                            </td>
                            <td><span class="text-muted" style="font-size:0.82rem;"><?= date('M j, Y', strtotime($u['created_at'])) ?></span></td>
                            <td class="text-center">
                                <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary action-btn" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($u['id'] !== $user['id']): ?>
                                <button class="btn btn-sm action-btn <?= $u['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                        title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                                        onclick="toggleStatus(<?= $u['id'] ?>, '<?= $u['status'] ?>', '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                    <i class="bi bi-<?= $u['status'] === 'active' ? 'person-dash' : 'person-check' ?>"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger action-btn" title="Delete"
                                        onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people" style="font-size:2rem; display:block; margin-bottom:8px;"></i>
                            No users found. <a href="add.php">Add the first user</a>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3">
            <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Toggle Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="statusModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="statusModalBody"></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="statusConfirmBtn" class="btn"></a>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Are you sure you want to delete <strong id="deleteUserName"></strong>? This cannot be undone.</div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Yes, Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleStatus(id, currentStatus, name) {
    const action = currentStatus === 'active' ? 'deactivate' : 'activate';
    const btnClass = action === 'deactivate' ? 'btn-warning' : 'btn-success';
    document.getElementById('statusModalTitle').innerHTML =
        `<i class="bi bi-person-${action === 'deactivate' ? 'dash' : 'check'} me-2"></i>${action.charAt(0).toUpperCase() + action.slice(1)} User`;
    document.getElementById('statusModalBody').innerHTML =
        `Are you sure you want to <strong>${action}</strong> <strong>${name}</strong>?`;
    const btn = document.getElementById('statusConfirmBtn');
    btn.textContent = `Yes, ${action.charAt(0).toUpperCase() + action.slice(1)}`;
    btn.className = `btn ${btnClass}`;
    btn.href = `toggle_status.php?id=${id}`;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}
function confirmDelete(id, name) {
    document.getElementById('deleteUserName').textContent = name;
    document.getElementById('deleteConfirmBtn').href = `delete.php?id=${id}`;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>