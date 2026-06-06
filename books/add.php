<?php
// ============================================
// books/add.php - Add New Book
// ============================================
require_once '../config/db.php';
require_once '../config/session.php';

require_role(['admin', 'librarian']);
$user = current_user();

$errors = [];
$input  = [];

// ============================================
// Handle Form Submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitize inputs
    $input['isbn']             = clean($_POST['isbn'] ?? '');
    $input['title']            = clean($_POST['title'] ?? '');
    $input['author']           = clean($_POST['author'] ?? '');
    $input['publisher']        = clean($_POST['publisher'] ?? '');
    $input['publication_year'] = clean($_POST['publication_year'] ?? '');
    $input['category_id']      = intval($_POST['category_id'] ?? 0);
    $input['total_copies']     = intval($_POST['total_copies'] ?? 1);
    $input['location']         = clean($_POST['location'] ?? '');
    
    $input['status']           = clean($_POST['status'] ?? 'available');

    // ---- Validation ----
    if (empty($input['isbn']))    $errors[] = "ISBN is required.";
    if (empty($input['title']))   $errors[] = "Title is required.";
    if (empty($input['author']))  $errors[] = "Author is required.";
    if ($input['category_id'] <= 0) $errors[] = "Please select a category.";
    if ($input['total_copies'] < 1) $errors[] = "Total copies must be at least 1.";

    // Validate ISBN format (basic: 10 or 13 digits, with optional dashes)
    $isbn_clean = preg_replace('/[^0-9X]/', '', strtoupper($input['isbn']));
    if (!empty($input['isbn']) && !preg_match('/^[0-9X]{10}$|^[0-9]{13}$/', $isbn_clean)) {
        $errors[] = "ISBN must be 10 or 13 characters (digits only).";
    }

    // Check duplicate ISBN
    if (empty($errors)) {
        $chk = $conn->prepare("SELECT id FROM books WHERE isbn = ?");
        $chk->bind_param("s", $input['isbn']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $errors[] = "A book with this ISBN already exists.";
        }
        $chk->close();
    }

    // ---- Handle Cover Image Upload ----
    $cover_image = 'default_book.png';

    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $max_size      = 2 * 1024 * 1024; // 2MB
        $file          = $_FILES['cover_image'];

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Cover image must be JPG, PNG, WebP, or GIF.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "Cover image must be under 2MB.";
        } else {
            // Generate unique filename
            $ext          = pathinfo($file['name'], PATHINFO_EXTENSION);
            $cover_image  = 'book_' . time() . '_' . mt_rand(1000, 9999) . '.' . strtolower($ext);
            $upload_dir   = UPLOAD_PATH . 'books/';

            // Create directory if not exists
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $upload_dir . $cover_image)) {
                $errors[] = "Failed to upload cover image. Check folder permissions.";
                $cover_image = 'default_book.png';
            }
        }
    }

    // ---- Save to Database ----
    if (empty($errors)) {
        $available_copies = $input['total_copies']; // all copies available on add
        $added_by         = $user['id'];

        $stmt = $conn->prepare(
            "INSERT INTO books
             (isbn, title, author, publisher, publication_year, category_id,
              total_copies, available_copies, location, cover_image, description, status, added_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssiiisssi",
            $input['isbn'],
            $input['title'],
            $input['author'],
            $input['publisher'],
            $input['publication_year'],
            $input['category_id'],
            $input['total_copies'],
            $available_copies,
            $input['location'],
            $cover_image,
            $input['description'],
            $input['status'],
            $added_by
        );

        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO books
             (isbn, title, author, publisher, publication_year, category_id,
              total_copies, available_copies, location, cover_image, description, status, added_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssiiiisssi",
            $input['isbn'],
            $input['title'],
            $input['author'],
            $input['publisher'],
            $input['publication_year'],
            $input['category_id'],
            $input['total_copies'],
            $available_copies,
            $input['location'],
            $cover_image,
            $input['description'],
            $input['status'],
            $added_by
        );

        if ($stmt->execute()) {
            $new_book_id = $conn->insert_id;
            $stmt->close();
            log_activity($conn, $user['id'], 'ADD_BOOK', "Added book: {$input['title']} (ISBN: {$input['isbn']})");
            set_flash('success', "Book '{$input['title']}' added successfully!");
            redirect(APP_URL . "/books/index.php");
        } else {
            $errors[] = "Database error: " . $conn->error;
            $stmt->close();
        }
    }
}

// Fetch categories
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Book — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .form-label { font-size:0.8rem; font-weight:600; text-transform:uppercase;
                      letter-spacing:0.04em; color:#374151; margin-bottom:5px; }
        .form-control, .form-select {
            border:1.5px solid #e5e7eb; border-radius:10px;
            padding:10px 14px; font-size:0.9rem;
        }
        .form-control:focus, .form-select:focus {
            border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1);
        }
        .section-heading {
            font-size:0.75rem; font-weight:700; text-transform:uppercase;
            letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6;
            padding-bottom:8px; margin-bottom:16px;
        }
        .cover-preview {
            width:100px; height:140px; object-fit:cover;
            border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15);
            border:2px solid #e5e7eb;
        }
        .btn-save {
            background:#1a3c5e; color:white; border:none;
            border-radius:10px; padding:11px 28px; font-weight:600;
        }
        .btn-save:hover { background:#0f2540; color:white; }
        .required-star { color:#dc2626; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Books</a></li>
            <li class="breadcrumb-item active">Add Book</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title mb-0"><i class="bi bi-plus-circle me-2" style="color:#c8963e;"></i>Add New Book</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Books
        </a>
    </div>

    <!-- Error Alert -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <strong><i class="bi bi-exclamation-circle me-2"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="add.php" enctype="multipart/form-data">
    <div class="row g-4">

        <!-- Left: Main Info -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4">

                    <!-- Book Identity -->
                    <div class="section-heading">Book Information</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label">Title <span class="required-star">*</span></label>
                            <input type="text" name="title" class="form-control"
                                   placeholder="e.g. To Kill a Mockingbird"
                                   value="<?= htmlspecialchars($input['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ISBN <span class="required-star">*</span></label>
                            <input type="text" name="isbn" class="form-control"
                                   placeholder="10 or 13 digits"
                                   value="<?= htmlspecialchars($input['isbn'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Author <span class="required-star">*</span></label>
                            <input type="text" name="author" class="form-control"
                                   placeholder="e.g. Harper Lee"
                                   value="<?= htmlspecialchars($input['author'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Publisher</label>
                            <input type="text" name="publisher" class="form-control"
                                   placeholder="e.g. J.B. Lippincott & Co."
                                   value="<?= htmlspecialchars($input['publisher'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Publication Year</label>
                            <input type="number" name="publication_year" class="form-control"
                                   placeholder="<?= date('Y') ?>"
                                   min="1000" max="<?= date('Y') ?>"
                                   value="<?= htmlspecialchars($input['publication_year'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category <span class="required-star">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select category...</option>
                                <?php while ($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['id'] ?>"
                                    <?= ($input['category_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shelf / Location</label>
                            <input type="text" name="location" class="form-control"
                                   placeholder="e.g. Shelf A-3"
                                   value="<?= htmlspecialchars($input['location'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="section-heading">Description</div>
                    <textarea name="description" class="form-control" rows="4"
                              placeholder="Brief description or summary of the book..."><?= htmlspecialchars($input['description'] ?? '') ?></textarea>

                </div>
            </div>
        </div>

        <!-- Right: Copies, Status, Cover -->
        <div class="col-lg-4">

            <!-- Copies & Status -->
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="section-heading">Inventory</div>
                    <div class="mb-3">
                        <label class="form-label">Total Copies <span class="required-star">*</span></label>
                        <input type="number" name="total_copies" class="form-control"
                               min="1" max="9999"
                               value="<?= htmlspecialchars($input['total_copies'] ?? 1) ?>" required>
                        <div class="form-text">All copies will be marked available on add.</div>
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="available"   <?= ($input['status'] ?? '') === 'available'   ? 'selected' : '' ?>>Available</option>
                            <option value="unavailable" <?= ($input['status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Cover Image -->
            <div class="card">
                <div class="card-body p-4">
                    <div class="section-heading">Cover Image</div>
                    <div class="text-center mb-3">
                        <img id="coverPreview"
                             src="<?= APP_URL ?>/assets/img/default_book.png"
                             class="cover-preview" alt="Cover Preview">
                    </div>
                    <input type="file" name="cover_image" id="coverInput"
                           class="form-control" accept="image/*">
                    <div class="form-text mt-1">JPG, PNG, WebP. Max 2MB.</div>
                </div>
            </div>

        </div>
    </div>

    <!-- Submit -->
    <div class="d-flex gap-3 mt-4">
        <button type="submit" class="btn btn-save">
            <i class="bi bi-floppy me-2"></i>Save Book
        </button>
        <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px; padding:11px 28px;">
            Cancel
        </a>
    </div>

    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live cover image preview
document.getElementById('coverInput').addEventListener('change', function () {
    const file = this.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('coverPreview').src = e.target.result;
        reader.readAsDataURL(file);
    }
});
</script>
</body>
</html>
