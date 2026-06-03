<?php
// ============================================
// index.php - Login Page
// Demonstrates: setcookie(), $_COOKIE, sessions
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_logged_in()) {
    redirect(APP_URL . "/dashboard.php");
}

$error       = '';
$remembered  = '';

// ---- COOKIE: Remember last School ID ----
if (isset($_COOKIE['remember_school_id'])) {
    $remembered = htmlspecialchars($_COOKIE['remember_school_id']);
}

// ============================================
// Handle Login Form Submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id   = clean($_POST['school_id']   ?? '');
    $password    = $_POST['password']           ?? '';
    $remember_me = isset($_POST['remember_me']);

    if (empty($school_id) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, school_id, full_name, email, password, role, profile_pic, status
             FROM users WHERE school_id = ? LIMIT 1"
        );
        $stmt->bind_param("s", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password'])) {
            // ---- COOKIE: Set or clear "remember me"
            if ($remember_me) {
                // Remember school ID for 7 days
                setcookie('remember_school_id', $user['school_id'], time() + (7 * 24 * 60 * 60), '/');
            } else {
                // Clear the cookie if not checked (expire it in the past)
                setcookie('remember_school_id', '', time() - 1, '/');
            }

            // ---- SESSION: Store logged-in user data ----
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['school_id']   = $user['school_id'];
            $_SESSION['full_name']   = $user['full_name'];
            $_SESSION['email']       = $user['email'];
            $_SESSION['role']        = $user['role'];
            $_SESSION['profile_pic'] = $user['profile_pic'];

            log_activity($conn, $user['id'], 'LOGIN', 'User logged in successfully.');
            set_flash('success', "Welcome back, " . $user['full_name'] . "!");
            redirect(APP_URL . "/dashboard.php");

        } elseif ($user && $user['status'] === 'inactive') {
            $error = "Your account has been deactivated. Contact the administrator.";
        } else {
            $error = "Invalid School ID or password.";
        }
    }
}

$url_msg = clean($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:   #1a3c5e;
            --accent:    #c8963e;
            --light-bg:  #f4f1eb;
            --card-bg:   #ffffff;
            --text-main: #1e1e1e;
            --text-muted:#6b7280;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'DM Sans',sans-serif;
            background-color:var(--light-bg);
            min-height:100vh;
            display:flex; align-items:center; justify-content:center;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(26,60,94,0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(200,150,62,0.08) 0%, transparent 50%);
        }
        .login-wrapper {
            display:flex; width:900px; max-width:95vw;
            min-height:540px; border-radius:20px;
            overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.15);
        }
        .login-brand {
            flex:1;
            background:linear-gradient(145deg, var(--primary) 0%, #0f2540 100%);
            color:white; display:flex; flex-direction:column;
            align-items:center; justify-content:center;
            padding:48px 36px; position:relative; overflow:hidden;
        }
        .login-brand::before {
            content:''; position:absolute;
            width:300px; height:300px; border-radius:50%;
            border:60px solid rgba(200,150,62,0.12);
            top:-80px; right:-80px;
        }
        .login-brand::after {
            content:''; position:absolute;
            width:200px; height:200px; border-radius:50%;
            border:40px solid rgba(255,255,255,0.05);
            bottom:-60px; left:-40px;
        }
        .brand-icon   { font-size:3.5rem; color:var(--accent); margin-bottom:20px; }
        .brand-title  { font-family:'Playfair Display',serif; font-size:1.8rem; text-align:center; line-height:1.3; margin-bottom:12px; }
        .brand-subtitle { font-size:0.875rem; opacity:0.7; text-align:center; }
        .brand-divider  { width:50px; height:3px; background:var(--accent); border-radius:2px; margin:20px auto; }
        .brand-roles    { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin-top:8px; }
        .brand-role-badge { font-size:0.75rem; padding:4px 12px; border-radius:20px; border:1px solid rgba(255,255,255,0.25); color:rgba(255,255,255,0.8); }
        .login-form-panel {
            flex:1.1; background:var(--card-bg);
            display:flex; flex-direction:column; justify-content:center;
            padding:52px 48px;
        }
        .form-title    { font-family:'Playfair Display',serif; font-size:1.7rem; color:var(--primary); margin-bottom:6px; }
        .form-subtitle { font-size:0.875rem; color:var(--text-muted); margin-bottom:32px; }
        .form-label    { font-size:0.8rem; font-weight:600; color:var(--text-main); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:6px; }
        .form-control  { border:1.5px solid #e5e7eb; border-radius:10px; padding:12px 16px; font-size:0.9rem; transition:border-color 0.2s, box-shadow 0.2s; }
        .form-control:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .input-group-text { background:#f9fafb; border:1.5px solid #e5e7eb; border-right:none; border-radius:10px 0 0 10px; color:var(--text-muted); }
        .input-group .form-control { border-left:none; border-radius:0 10px 10px 0; }
        .input-group:focus-within .input-group-text { border-color:var(--primary); }
        .btn-login {
            background:var(--primary); color:white; border:none;
            border-radius:10px; padding:13px; font-size:0.95rem;
            font-weight:600; letter-spacing:0.02em;
            transition:background 0.2s, transform 0.1s; width:100%; margin-top:8px;
        }
        .btn-login:hover { background:#0f2540; transform:translateY(-1px); }
        .login-footer  { font-size:0.78rem; color:var(--text-muted); text-align:center; margin-top:24px; }
        .alert         { font-size:0.875rem; border-radius:10px; }
        .toggle-password {
            cursor:pointer; border:1.5px solid #e5e7eb; border-left:none;
            border-radius:0 10px 10px 0 !important;
            background:#f9fafb; color:var(--text-muted); padding:0 14px;
        }
        .toggle-password:hover { color:var(--primary); }
        /* Cookie notice badge */
        .cookie-notice {
            font-size:0.75rem; color:var(--text-muted);
            background:#f9fafb; border-radius:8px; padding:6px 12px;
            margin-top:12px; display:flex; align-items:center; gap:6px;
        }
        @media (max-width:640px) {
            .login-brand { display:none; }
            .login-form-panel { padding:36px 24px; }
        }
    </style>
</head>
<body>
<div class="login-wrapper">

    <!-- LEFT: Branding -->
    <div class="login-brand">
        <div class="brand-icon"><i class="bi bi-book-half"></i></div>
        <h1 class="brand-title">School Library<br>Management System</h1>
        <div class="brand-divider"></div>
        <p class="brand-subtitle">Your gateway to knowledge and learning resources</p>
        <div class="brand-roles">
            <span class="brand-role-badge"><i class="bi bi-shield-check me-1"></i>Admin</span>
            <span class="brand-role-badge"><i class="bi bi-person-workspace me-1"></i>Librarian</span>
            <span class="brand-role-badge"><i class="bi bi-mortarboard me-1"></i>Student</span>
            <span class="brand-role-badge"><i class="bi bi-person-badge me-1"></i>Teacher</span>
        </div>
    </div>

    <!-- RIGHT: Login Form -->
    <div class="login-form-panel">
        <h2 class="form-title">Welcome back</h2>
        <p class="form-subtitle">Sign in with your School ID to continue</p>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($url_msg): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($url_msg) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="index.php" novalidate>

            <!-- School ID — pre-filled from cookie if remembered -->
            <div class="mb-4">
                <label class="form-label" for="school_id">School ID</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                    <input
                        type="text"
                        class="form-control"
                        id="school_id"
                        name="school_id"
                        placeholder="e.g. ADMIN-001 or 2024-0123"
                        value="<?= $remembered ?: htmlspecialchars($_POST['school_id'] ?? '') ?>"
                        required autofocus
                    >
                </div>
                <?php if ($remembered): ?>
                <!-- Show cookie notice when School ID is pre-filled from cookie -->
                <div class="cookie-notice">
                    <i class="bi bi-cookie"></i>
                    School ID remembered from your last login.
                </div>
                <?php endif; ?>
            </div>

            <!-- Password -->
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password"
                           name="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-password btn" id="togglePwd">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Remember Me checkbox — controls the cookie -->
            <div class="mb-3 d-flex align-items-center gap-2">
                <input
                    type="checkbox"
                    class="form-check-input mt-0"
                    id="remember_me"
                    name="remember_me"
                    <?= $remembered ? 'checked' : '' ?>
                >
                <label class="form-check-label" for="remember_me" style="font-size:0.875rem; color:var(--text-muted);">
                    Remember my School ID
                </label>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <p class="login-footer">
            <i class="bi bi-shield-lock me-1"></i>
            Forgot your password? Contact your <strong>Admin</strong> or <strong>Librarian</strong>.
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePwd').addEventListener('click', function () {
    const pwd  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'bi bi-eye';
    }
});
</script>
</body>
</html>