<?php
// ============================================
// profile.php - My Profile & Password Change
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

require_login();
$current = current_user();

// Active tab from URL
$tab    = clean($_GET['tab'] ?? 'profile');
$errors = [];
$success= '';

// ============================================
// Fetch full user data
// ============================================
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $current['id']);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ============================================
// Handle Profile Update
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $tab         = 'profile';
    $full_name   = clean($_POST['full_name'] ?? '');
    $email       = clean($_POST['email'] ?? '');
    $grade       = clean($_POST['grade_section'] ?? '');
    $dept        = clean($_POST['department'] ?? '');

    if (empty($full_name)) $errors[] = "Full name is required.";
    if (empty($email))     $errors[] = "Email is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";

    // Duplicate email check (exclude self)
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->bind_param("si", $email, $current['id']);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = "Email already used by another account.";
    $chk->close();

    // Profile picture upload
    $pic = $profile['profile_pic'];
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/webp'];
        $file    = $_FILES['profile_pic'];
        if (!in_array($file['type'], $allowed)) {
            $errors[] = "Profile picture must be JPG, PNG, or WebP.";
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = "Profile picture must be under 2MB.";
        } else {
            $ext     = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_pic = 'user_' . time() . '_' . mt_rand(1000,9999) . '.' . strtolower($ext);
            $dir     = UPLOAD_PATH . 'profiles/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $dir . $new_pic)) {
                if ($pic !== 'default.png' && file_exists($dir . $pic)) unlink($dir . $pic);
                $pic = $new_pic;
            } else {
                $errors[] = "Failed to upload photo.";
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "UPDATE users SET full_name=?, email=?, grade_section=?, department=?, profile_pic=? WHERE id=?"
        );
        $stmt->bind_param("sssssi", $full_name, $email, $grade, $dept, $pic, $current['id']);
        if ($stmt->execute()) {
            $stmt->close();
            // Update session
            $_SESSION['full_name']   = $full_name;
            $_SESSION['email']       = $email;
            $_SESSION['profile_pic'] = $pic;
            // Refresh profile data
            $profile['full_name']    = $full_name;
            $profile['email']        = $email;
            $profile['grade_section']= $grade;
            $profile['department']   = $dept;
            $profile['profile_pic']  = $pic;
            log_activity($conn, $current['id'], 'EDIT_USER', "Updated own profile.");
            $success = "Profile updated successfully!";
        } else {
            $errors[] = "Database error: " . $conn->error;
            $stmt->close();
        }
    }
}

// ============================================
// Handle Password Change
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $tab             = 'password';
    $current_pwd     = $_POST['current_password'] ?? '';
    $new_pwd         = $_POST['new_password'] ?? '';
    $confirm_pwd     = $_POST['confirm_password'] ?? '';

    if (empty($current_pwd)) $errors[] = "Current password is required.";
    if (strlen($new_pwd) < 8) $errors[] = "New password must be at least 8 characters.";
    if ($new_pwd !== $confirm_pwd) $errors[] = "New passwords do not match.";

    if (empty($errors)) {
        if (!password_verify($current_pwd, $profile['password'])) {
            $errors[] = "Current password is incorrect.";
        } else {
            $hashed = password_hash($new_pwd, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed, $current['id']);
            if ($stmt->execute()) {
                $stmt->close();
                log_activity($conn, $current['id'], 'EDIT_USER', "Changed own password.");
                $success = "Password changed successfully!";
            } else {
                $errors[] = "Database error.";
                $stmt->close();
            }
        }
    }
}

// Fetch recent borrow history
$stmt = $conn->prepare(
    "SELECT br.id, b.title, b.author, b.cover_image,
            br.borrow_date, br.due_date, br.return_date, br.status
     FROM borrow_records br
     JOIN books b ON b.id = br.book_id
     WHERE br.user_id = ?
     ORDER BY br.borrow_date DESC
     LIMIT 5"
);
$stmt->bind_param("i", $current['id']);
$stmt->execute();
$my_borrows = $stmt->get_result();
$stmt->close();

// Unpaid fines
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM fines WHERE user_id=? AND status='unpaid'");
$stmt->bind_param("i", $current['id']);
$stmt->execute();
$unpaid = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$role_colors = [
    'admin'     => '#1a3c5e',
    'librarian' => '#c8963e',
    'student'   => '#1e6b3c',
    'teacher'   => '#4f46e5',
];
$role_color = $role_colors[$profile['role']] ?? '#6b7280';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .profile-avatar {
            width:110px; height:110px; border-radius:50%; object-fit:cover;
            border:4px solid white; box-shadow:0 4px 16px rgba(0,0,0,0.15);
        }
        .profile-banner {
            height:100px; border-radius:14px 14px 0 0;
            background:linear-gradient(135deg, var(--banner-color, #1a3c5e) 0%, #0f2540 100%);
        }
        .tab-btn { border:none; background:none; padding:10px 20px; font-weight:600;
                   color:#6b7280; border-bottom:3px solid transparent; font-size:0.9rem; cursor:pointer; }
        .tab-btn.active { color:#1a3c5e; border-bottom-color:#1a3c5e; }
        .tab-btn:hover { color:#1a3c5e; }
        .tab-content-panel { display:none; }
        .tab-content-panel.active { display:block; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control,.form-select { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; font-size:0.9rem; }
        .form-control:focus,.form-select:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .form-control[readonly] { background:#f9fafb; color:#6b7280; }
        .btn-save { background:#1a3c5e; color:white; border:none; border-radius:10px; padding:10px 24px; font-weight:600; }
        .btn-save:hover { background:#0f2540; color:white; }
        .section-heading { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6; padding-bottom:8px; margin-bottom:16px; }
        .borrow-item { display:flex; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid #f3f4f6; }
        .borrow-item:last-child { border-bottom:none; }
        .mini-cover { width:36px; height:50px; object-fit:cover; border-radius:4px; flex-shrink:0; }
        .badge-borrowed { background:#dbeafe; color:#1d4ed8; }
        .badge-returned { background:#dcfce7; color:#15803d; }
        .badge-overdue  { background:#fee2e2; color:#dc2626; }
        .info-pill { display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border-radius:8px; padding:6px 12px; font-size:0.82rem; }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <div class="row g-4">
        <!-- LEFT: Profile Card -->
        <div class="col-lg-4">
            <div class="card mb-4">
                <!-- Banner -->
                <div class="profile-banner" style="--banner-color:<?= $role_color ?>;"></div>
                <!-- Avatar -->
                <div class="px-4 pb-4" style="margin-top:-55px;">
                    <img id="avatarDisplay"
                         src="<?= APP_URL ?>/uploads/profiles/<?= htmlspecialchars($profile['profile_pic']) ?>"
                         onerror="this.src='<?= APP_URL ?>/assets/img/default.png'"
                         class="profile-avatar d-block mb-3" alt="Profile">
                    <h5 class="fw-bold mb-0" style="color:#1a3c5e;"><?= htmlspecialchars($profile['full_name']) ?></h5>
                    <p class="text-muted mb-3" style="font-size:0.875rem;"><?= htmlspecialchars($profile['email']) ?></p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="info-pill">
                            <i class="bi bi-shield-check" style="color:<?= $role_color ?>;"></i>
                            <?= ucfirst($profile['role']) ?>
                        </span>
                        <span class="info-pill">
                            <i class="bi bi-person-badge text-muted"></i>
                            <?= htmlspecialchars($profile['school_id']) ?>
                        </span>
                    </div>
                    <?php if ($profile['grade_section']): ?>
                    <div class="info-pill mb-2">
                        <i class="bi bi-mortarboard text-muted"></i>
                        <?= htmlspecialchars($profile['grade_section']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['department']): ?>
                    <div class="info-pill mb-2">
                        <i class="bi bi-building text-muted"></i>
                        <?= htmlspecialchars($profile['department']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3 pt-3" style="border-top:1px solid #f3f4f6;">
                        <div class="d-flex justify-content-between" style="font-size:0.82rem;">
                            <span class="text-muted">Member Since</span>
                            <span class="fw-semibold"><?= date('M Y', strtotime($profile['created_at'])) ?></span>
                        </div>
                        <?php if ($unpaid > 0): ?>
                        <div class="d-flex justify-content-between mt-2" style="font-size:0.82rem;">
                            <span class="text-muted">Unpaid Fines</span>
                            <span class="fw-bold text-danger">₱<?= number_format($unpaid, 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Borrows Widget -->
            <?php if ($my_borrows->num_rows > 0): ?>
            <div class="card">
                <div class="card-body p-4">
                    <div class="section-heading">Recent Borrows</div>
                    <?php while ($b = $my_borrows->fetch_assoc()): ?>
                    <?php $s = ($b['status']==='borrowed' && $b['due_date'] < date('Y-m-d')) ? 'overdue' : $b['status']; ?>
                    <div class="borrow-item">
                        <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($b['cover_image']) ?>"
                             onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                             class="mini-cover" alt="cover">
                        <div class="flex-grow-1">
                            <div class="fw-semibold" style="font-size:0.82rem; line-height:1.2;">
                                <?= htmlspecialchars(strlen($b['title']) > 30 ? substr($b['title'],0,30).'…' : $b['title']) ?>
                            </div>
                            <small class="text-muted"><?= date('M j, Y', strtotime($b['borrow_date'])) ?></small>
                        </div>
                        <span class="badge badge-<?= $s ?>" style="font-size:0.68rem;"><?= ucfirst($s) ?></span>
                    </div>
                    <?php endwhile; ?>
                    <a href="<?= APP_URL ?>/borrow/my_borrows.php" class="btn btn-outline-primary btn-sm w-100 mt-3" style="border-radius:8px; font-size:0.82rem;">
                        View All Borrows
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Tabs -->
        <div class="col-lg-8">
            <div class="card">
                <!-- Tab Headers -->
                <div class="d-flex border-bottom px-4 pt-2">
                    <button class="tab-btn <?= $tab==='profile' ? 'active':'' ?>" onclick="switchTab('profile')">
                        <i class="bi bi-person me-1"></i>Edit Profile
                    </button>
                    <button class="tab-btn <?= $tab==='password' ? 'active':'' ?>" onclick="switchTab('password')">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                </div>

                <div class="card-body p-4">

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
                        <strong><i class="bi bi-exclamation-circle me-2"></i>Error:</strong>
                        <ul class="mb-0 mt-1"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
                        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- ---- PROFILE TAB ---- -->
                    <div id="tab-profile" class="tab-content-panel <?= $tab==='profile' ? 'active':'' ?>">
                        <form method="POST" action="profile.php" enctype="multipart/form-data">
                            <input type="hidden" name="update_profile" value="1">

                            <div class="section-heading">Personal Information</div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">School ID</label>
                                    <input type="text" class="form-control" readonly
                                           value="<?= htmlspecialchars($profile['school_id']) ?>">
                                    <div class="form-text">School ID cannot be changed.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?= htmlspecialchars($profile['email']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <input type="text" class="form-control" readonly
                                           value="<?= ucfirst($profile['role']) ?>">
                                </div>
                                <?php if ($profile['role'] === 'student'): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Grade & Section</label>
                                    <input type="text" name="grade_section" class="form-control"
                                           value="<?= htmlspecialchars($profile['grade_section'] ?? '') ?>"
                                           placeholder="e.g. Grade 10 - Mabini">
                                </div>
                                <?php endif; ?>
                                <?php if ($profile['role'] === 'teacher'): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-control"
                                           value="<?= htmlspecialchars($profile['department'] ?? '') ?>"
                                           placeholder="e.g. Science Dept.">
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="section-heading">Profile Picture</div>
                            <div class="d-flex align-items-center gap-4 mb-4">
                                <img id="avatarPreview"
                                     src="<?= APP_URL ?>/uploads/profiles/<?= htmlspecialchars($profile['profile_pic']) ?>"
                                     onerror="this.src='<?= APP_URL ?>/assets/img/default.png'"
                                     style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #e5e7eb;"
                                     alt="Preview">
                                <div class="flex-grow-1">
                                    <input type="file" name="profile_pic" id="picInput"
                                           class="form-control" accept="image/*">
                                    <div class="form-text">JPG, PNG, WebP · Max 2MB. Leave blank to keep current.</div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-floppy me-2"></i>Save Changes
                            </button>
                        </form>
                    </div>

                    <!-- ---- PASSWORD TAB ---- -->
                    <div id="tab-password" class="tab-content-panel <?= $tab==='password' ? 'active':'' ?>">
                        <form method="POST" action="profile.php?tab=password">
                            <input type="hidden" name="change_password" value="1">

                            <div class="section-heading">Change Password</div>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label">Current Password *</label>
                                    <div class="input-group">
                                        <input type="password" name="current_password" id="curPwd"
                                               class="form-control" placeholder="Enter current password" required>
                                        <button type="button" class="btn btn-outline-secondary"
                                                onclick="togglePwd('curPwd','eye0')"><i id="eye0" class="bi bi-eye"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">New Password *</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" id="newPwd"
                                               class="form-control" placeholder="Min. 8 characters" required>
                                        <button type="button" class="btn btn-outline-secondary"
                                                onclick="togglePwd('newPwd','eye1')"><i id="eye1" class="bi bi-eye"></i></button>
                                    </div>
                                    <div id="pwdStrength" class="form-text mt-1"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm New Password *</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confPwd"
                                               class="form-control" placeholder="Repeat new password" required>
                                        <button type="button" class="btn btn-outline-secondary"
                                                onclick="togglePwd('confPwd','eye2')"><i id="eye2" class="bi bi-eye"></i></button>
                                    </div>
                                    <div id="matchMsg" class="form-text mt-1"></div>
                                </div>
                            </div>

                            <div class="alert alert-info mt-4 mb-4" style="border-radius:10px; font-size:0.85rem;">
                                <i class="bi bi-shield-lock me-2"></i>
                                Use a strong password with uppercase letters, numbers, and special characters.
                            </div>

                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-key me-2"></i>Change Password
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.currentTarget.classList.add('active');
}

function togglePwd(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

// Profile picture live preview
document.getElementById('picInput').addEventListener('change', function () {
    if (this.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src  = e.target.result;
            document.getElementById('avatarDisplay').src  = e.target.result;
        };
        reader.readAsDataURL(this.files[0]);
    }
});

// Password strength
document.getElementById('newPwd').addEventListener('input', function () {
    const val = this.value;
    const div = document.getElementById('pwdStrength');
    let strength = 0;
    if (val.length >= 8) strength++;
    if (/[A-Z]/.test(val)) strength++;
    if (/[0-9]/.test(val)) strength++;
    if (/[^A-Za-z0-9]/.test(val)) strength++;
    const labels = ['','<span class="text-danger">Weak</span>','<span class="text-warning">Fair</span>','<span class="text-info">Good</span>','<span class="text-success">Strong ✓</span>'];
    div.innerHTML = val.length > 0 ? 'Strength: ' + (labels[strength] || labels[1]) : '';
    checkMatch();
});

// Password match checker
document.getElementById('confPwd').addEventListener('input', checkMatch);
function checkMatch() {
    const pwd  = document.getElementById('newPwd').value;
    const conf = document.getElementById('confPwd').value;
    const msg  = document.getElementById('matchMsg');
    if (conf.length === 0) { msg.innerHTML = ''; return; }
    msg.innerHTML = pwd === conf
        ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match</span>'
        : '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match</span>';
}
</script>
</body>
</html>