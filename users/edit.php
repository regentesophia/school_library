<?php
// ============================================
// users/edit.php - Edit User
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['admin']);
$current = current_user();

$uid = intval($_GET['id'] ?? 0);
if ($uid <= 0) { set_flash('danger','Invalid user.'); redirect(APP_URL.'/users/index.php'); }

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target) { set_flash('danger','User not found.'); redirect(APP_URL.'/users/index.php'); }

$errors = [];
$input  = $target;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['full_name']     = clean($_POST['full_name'] ?? '');
    $input['email']         = clean($_POST['email'] ?? '');
    $input['school_id']     = clean($_POST['school_id'] ?? '');
    $input['role']          = clean($_POST['role'] ?? '');
    $input['grade_section'] = clean($_POST['grade_section'] ?? '');
    $input['department']    = clean($_POST['department'] ?? '');
    $input['status']        = clean($_POST['status'] ?? 'active');
    $new_password           = $_POST['new_password'] ?? '';
    $confirm_password       = $_POST['confirm_password'] ?? '';

    if (empty($input['full_name'])) $errors[] = "Full name is required.";
    if (empty($input['email']))     $errors[] = "Email is required.";
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
    if (!in_array($input['role'], ['admin','librarian','student','teacher'])) $errors[] = "Invalid role.";

    // Prevent admin from deactivating themselves
    if ($uid === $current['id'] && $input['status'] === 'inactive') {
        $errors[] = "You cannot deactivate your own account.";
    }

    // Duplicate school_id check (exclude current)
    $chk = $conn->prepare("SELECT id FROM users WHERE school_id = ? AND id != ?");
    $chk->bind_param("si", $input['school_id'], $uid);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = "School ID already used by another user.";
    $chk->close();

    // Duplicate email check (exclude current)
    $chk2 = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk2->bind_param("si", $input['email'], $uid);
    $chk2->execute();
    if ($chk2->get_result()->num_rows > 0) $errors[] = "Email already used by another user.";
    $chk2->close();

    // Password change (optional)
    if (!empty($new_password)) {
        if (strlen($new_password) < 8) $errors[] = "New password must be at least 8 characters.";
        if ($new_password !== $confirm_password) $errors[] = "Passwords do not match.";
    }

    // Profile picture upload
    $profile_pic = $target['profile_pic'];
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/webp'];
        $file = $_FILES['profile_pic'];
        if (!in_array($file['type'], $allowed)) { $errors[] = "Profile picture must be JPG, PNG, or WebP."; }
        elseif ($file['size'] > 2 * 1024 * 1024) { $errors[] = "Profile picture must be under 2MB."; }
        else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_pic = 'user_' . time() . '_' . mt_rand(1000,9999) . '.' . strtolower($ext);
            $upload_dir = UPLOAD_PATH . 'profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_pic)) {
                if ($profile_pic !== 'default.png' && file_exists($upload_dir . $profile_pic)) unlink($upload_dir . $profile_pic);
                $profile_pic = $new_pic;
            } else { $errors[] = "Failed to upload profile picture."; }
        }
    }

    if (empty($errors)) {
        if (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                "UPDATE users SET school_id=?, full_name=?, email=?, password=?, role=?,
                 grade_section=?, department=?, profile_pic=?, status=? WHERE id=?"
            );
            $stmt->bind_param("sssssssssi",
                $input['school_id'], $input['full_name'], $input['email'], $hashed,
                $input['role'], $input['grade_section'], $input['department'],
                $profile_pic, $input['status'], $uid
            );
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET school_id=?, full_name=?, email=?, role=?,
                 grade_section=?, department=?, profile_pic=?, status=? WHERE id=?"
            );
            $stmt->bind_param("ssssssssi",
                $input['school_id'], $input['full_name'], $input['email'],
                $input['role'], $input['grade_section'], $input['department'],
                $profile_pic, $input['status'], $uid
            );
        }
        if ($stmt->execute()) {
            $stmt->close();
            log_activity($conn, $current['id'], 'EDIT_USER', "Edited user ID $uid: {$input['full_name']}");
            set_flash('success', "User '{$input['full_name']}' updated successfully!");
            redirect(APP_URL . '/users/index.php');
        } else {
            $errors[] = "Database error: " . $conn->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control,.form-select { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; font-size:0.9rem; }
        .form-control:focus,.form-select:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .section-heading { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6; padding-bottom:8px; margin-bottom:16px; }
        .avatar-preview { width:100px; height:100px; border-radius:50%; object-fit:cover; box-shadow:0 4px 12px rgba(0,0,0,0.15); border:3px solid #e5e7eb; }
        .btn-save { background:#1a3c5e; color:white; border:none; border-radius:10px; padding:11px 28px; font-weight:600; }
        .btn-save:hover { background:#0f2540; color:white; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Users</a></li>
            <li class="breadcrumb-item active">Edit User</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-person-gear me-2" style="color:#c8963e;"></i>Edit User</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <strong><i class="bi bi-exclamation-circle me-2"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="edit.php?id=<?= $uid ?>" enctype="multipart/form-data">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="section-heading">Account Information</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($input['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School ID *</label>
                            <input type="text" name="school_id" class="form-control" value="<?= htmlspecialchars($input['school_id']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($input['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role *</label>
                            <select name="role" id="roleSelect" class="form-select" required <?= $uid === $current['id'] ? 'disabled' : '' ?>>
                                <?php if ($uid === $current['id']): ?>
                                <input type="hidden" name="role" value="<?= htmlspecialchars($input['role']) ?>">
                                <?php endif; ?>
                                <option value="admin"     <?= $input['role']==='admin'     ? 'selected':'' ?>>Admin</option>
                                <option value="librarian" <?= $input['role']==='librarian' ? 'selected':'' ?>>Librarian</option>
                                <option value="student"   <?= $input['role']==='student'   ? 'selected':'' ?>>Student</option>
                                <option value="teacher"   <?= $input['role']==='teacher'   ? 'selected':'' ?>>Teacher</option>
                            </select>
                            <?php if ($uid === $current['id']): ?>
                            <input type="hidden" name="role" value="<?= htmlspecialchars($input['role']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6" id="gradeSectionField" style="display:none;">
                            <label class="form-label">Grade & Section</label>
                            <input type="text" name="grade_section" class="form-control" value="<?= htmlspecialchars($input['grade_section'] ?? '') ?>">
                        </div>
                        <div class="col-md-6" id="departmentField" style="display:none;">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($input['department'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" <?= $uid === $current['id'] ? 'disabled' : '' ?>>
                                <option value="active"   <?= $input['status']==='active'   ? 'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $input['status']==='inactive' ? 'selected':'' ?>>Inactive</option>
                            </select>
                            <?php if ($uid === $current['id']): ?>
                            <input type="hidden" name="status" value="active">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <div class="section-heading">Change Password <span class="text-muted fw-normal" style="font-size:0.75rem;">(leave blank to keep current)</span></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Min. 8 characters">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('new_password','eye1')"><i id="eye1" class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Repeat password">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('confirm_password','eye2')"><i id="eye2" class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body p-4 text-center">
                    <div class="section-heading text-start">Profile Picture</div>
                    <img id="avatarPreview"
                         src="<?= APP_URL ?>/uploads/profiles/<?= htmlspecialchars($input['profile_pic']) ?>"
                         onerror="this.src='<?= APP_URL ?>/assets/img/default.png'"
                         class="avatar-preview mb-3" alt="Preview">
                    <input type="file" name="profile_pic" id="picInput" class="form-control" accept="image/*">
                    <div class="form-text mt-1">Leave blank to keep current photo.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 mt-4">
        <button type="submit" class="btn btn-save"><i class="bi bi-floppy me-2"></i>Save Changes</button>
        <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px; padding:11px 28px;">Cancel</a>
    </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('roleSelect').addEventListener('change', function () {
    const role = this.value;
    document.getElementById('gradeSectionField').style.display = role === 'student' ? 'block' : 'none';
    document.getElementById('departmentField').style.display   = role === 'teacher' ? 'block' : 'none';
});
document.getElementById('roleSelect').dispatchEvent(new Event('change'));
document.getElementById('picInput').addEventListener('change', function () {
    if (this.files[0]) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('avatarPreview').src = e.target.result;
        reader.readAsDataURL(this.files[0]);
    }
});
function togglePwd(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>