<?php
// ============================================
// announcements/index.php
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_login();
$user = current_user();

// Handle post new announcement (admin/librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_role(['admin','librarian'])) {
    $title       = clean($_POST['title'] ?? '');
    $content     = clean($_POST['content'] ?? '');
    $target_role = clean($_POST['target_role'] ?? 'all');
    $post_errors = [];

    if (empty($title))   $post_errors[] = "Title is required.";
    if (empty($content)) $post_errors[] = "Content is required.";
    if (!in_array($target_role, ['all','student','teacher'])) $post_errors[] = "Invalid target.";

    if (empty($post_errors)) {
        $stmt = $conn->prepare(
            "INSERT INTO announcements (title, content, posted_by, target_role) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("ssis", $title, $content, $user['id'], $target_role);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $user['id'], 'POST_ANNOUNCEMENT', "Posted: $title");
        set_flash('success', "Announcement posted successfully!");
        redirect(APP_URL . '/announcements/index.php');
    }
}

// Handle delete
if (isset($_GET['delete']) && has_role(['admin'])) {
    $del_id = intval($_GET['delete']);

    $ds = $conn->prepare("DELETE FROM announcements WHERE id=?");
    $ds->bind_param("i", $del_id);
    $ds->execute();
    $ds->close();

    log_activity($conn, $user['id'], 'DELETE_ANNOUNCEMENT', "Deleted announcement ID $del_id");
    set_flash('success', "Announcement deleted.");
    redirect(APP_URL . '/announcements/index.php');
}

// Fetch announcements relevant to this user's role
$role = $user['role'];
if (has_role(['admin','librarian'])) {
    $announcements = $conn->query(
        "SELECT a.*, u.full_name AS author, u.role AS author_role
         FROM announcements a JOIN users u ON u.id=a.posted_by
         ORDER BY a.created_at DESC"
    );
} else {
    $stmt = $conn->prepare(
        "SELECT a.*, u.full_name AS author, u.role AS author_role
         FROM announcements a JOIN users u ON u.id=a.posted_by
         WHERE a.target_role='all' OR a.target_role=?
         ORDER BY a.created_at DESC"
    );
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $announcements = $stmt->get_result();
    $stmt->close();
}

$target_labels = ['all'=>'Everyone','student'=>'Students','teacher'=>'Teachers'];
$target_colors = ['all'=>['#1a3c5e','#e8f0fe'],'student'=>['#15803d','#dcfce7'],'teacher'=>['#4f46e5','#ede9fe']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .announcement-card { background:white; border-radius:14px; padding:24px; margin-bottom:16px; box-shadow:0 2px 8px rgba(0,0,0,0.05); border-left:4px solid #1a3c5e; transition:transform 0.15s; }
        .announcement-card:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(0,0,0,0.1); }
        .ann-title { font-family:'Playfair Display',serif; font-size:1.15rem; color:#1a3c5e; font-weight:700; }
        .ann-meta { font-size:0.78rem; color:#6b7280; }
        .ann-body { font-size:0.9rem; color:#374151; line-height:1.7; margin-top:10px; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control,.form-select { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; font-size:0.9rem; }
        .form-control:focus,.form-select:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .section-heading { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6; padding-bottom:8px; margin-bottom:16px; }
        .btn-post { background:#1a3c5e; color:white; border:none; border-radius:10px; padding:10px 24px; font-weight:600; }
        .btn-post:hover { background:#0f2540; color:white; }
        .empty-state { text-align:center; padding:60px 20px; color:#9ca3af; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>
    <?php if (isset($post_errors) && !empty($post_errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <ul class="mb-0"><?php foreach($post_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-megaphone me-2" style="color:#c8963e;"></i>Announcements</h1>
        <p class="text-muted mb-0" style="font-size:0.85rem;">Library news and updates</p>
    </div>

    <div class="row g-4">
        <!-- Announcements Feed -->
        <div class="col-lg-<?= has_role(['admin','librarian']) ? '8' : '12' ?>">
            <?php if ($announcements->num_rows > 0): ?>
            <?php while ($a = $announcements->fetch_assoc()):
                $tc = $target_colors[$a['target_role']] ?? $target_colors['all'];
                $border_colors = ['admin'=>'#1a3c5e','librarian'=>'#c8963e','student'=>'#1e6b3c','teacher'=>'#4f46e5'];
                $border = $border_colors[$a['author_role']] ?? '#1a3c5e';
            ?>
            <div class="announcement-card" style="border-left-color:<?= $border ?>;">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="ann-title"><?= htmlspecialchars($a['title']) ?></span>
                            <span class="badge rounded-pill"
                                  style="background:<?= $tc[1] ?>;color:<?= $tc[0] ?>;font-size:0.7rem;">
                                <i class="bi bi-people me-1"></i><?= $target_labels[$a['target_role']] ?>
                            </span>
                        </div>
                        <div class="ann-meta">
                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($a['author']) ?>
                            <span class="badge bg-light text-dark ms-1" style="font-size:0.65rem;"><?= ucfirst($a['author_role']) ?></span>
                            &nbsp;·&nbsp;
                            <i class="bi bi-clock me-1"></i><?= date('F j, Y \a\t g:i A', strtotime($a['created_at'])) ?>
                        </div>
                        <div class="ann-body"><?= nl2br(htmlspecialchars($a['content'])) ?></div>
                    </div>
                    <?php if (has_role(['admin'])): ?>
                    <button class="btn btn-sm btn-outline-danger flex-shrink-0"
                            style="border-radius:8px;font-size:0.78rem;"
                            onclick="if(confirm('Delete this announcement?')) window.location='?delete=<?= $a['id'] ?>'">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <i class="bi bi-megaphone" style="font-size:3rem;display:block;margin-bottom:12px;color:#d1d5db;"></i>
                    <p class="mb-0">No announcements yet.</p>
                    <?php if (has_role(['admin','librarian'])): ?>
                    <p class="text-muted" style="font-size:0.85rem;">Use the form on the right to post the first announcement.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Post Form (Admin/Librarian only) -->
        <?php if (has_role(['admin','librarian'])): ?>
        <div class="col-lg-4">
            <div class="card" style="position:sticky;top:80px;">
                <div class="card-body p-4">
                    <div class="section-heading">Post Announcement</div>
                    <form method="POST" action="index.php">
                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" class="form-control"
                                   placeholder="e.g. Library Hours Update"
                                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message *</label>
                            <textarea name="content" class="form-control" rows="5"
                                      placeholder="Write your announcement here..."
                                      required><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Target Audience</label>
                            <select name="target_role" class="form-select">
                                <option value="all">Everyone</option>
                                <option value="student">Students Only</option>
                                <option value="teacher">Teachers Only</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-post w-100">
                            <i class="bi bi-send me-2"></i>Post Announcement
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>