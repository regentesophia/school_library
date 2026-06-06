<?php
// ============================================
// books/index.php - Book Catalog
// ============================================
require_once '../config/db.php';
require_once '../config/session.php';

require_login();
$user = current_user();

// ============================================
// Search & Filter Parameters
// ============================================
$search     = clean($_GET['search'] ?? '');
$cat_filter = intval($_GET['category'] ?? 0);
$status_filter = clean($_GET['status'] ?? '');
$page       = max(1, intval($_GET['page'] ?? 1));
$per_page   = 10;
$offset     = ($page - 1) * $per_page;

// Build WHERE clause dynamically
$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($cat_filter > 0) {
    $where   .= " AND b.category_id = ?";
    $params[] = $cat_filter;
    $types   .= "i";
}
if ($status_filter !== '') {
    $where   .= " AND b.status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}

// Count total for pagination
$count_sql  = "SELECT COUNT(*) AS c FROM books b $where";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows  = $count_stmt->get_result()->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);
$count_stmt->close();

// Fetch books
$sql  = "SELECT b.id, b.isbn, b.title, b.author, b.publisher,
                b.publication_year, b.total_copies, b.available_copies,
                b.cover_image, b.status, b.location,
                c.name AS category
         FROM books b
         JOIN categories c ON c.id = b.category_id
         $where
         ORDER BY b.title ASC
         LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$books = $stmt->get_result();
$stmt->close();

// Fetch all categories for filter dropdown
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Books — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #f4f1eb; }
        .card  { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family: 'Playfair Display', serif; color: #1a3c5e; font-size: 1.6rem; }
        .book-cover {
            width: 48px; height: 64px; object-fit: cover;
            border-radius: 5px; box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .table th { font-size: 0.78rem; text-transform: uppercase;
                    letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
        .table td { vertical-align: middle; font-size: 0.875rem; }
        .badge-avail   { background:#dcfce7; color:#15803d; }
        .badge-unavail { background:#fee2e2; color:#dc2626; }
        .copies-bar {
            height: 5px; border-radius: 3px; background: #e5e7eb; margin-top: 4px;
        }
        .copies-fill { height: 100%; border-radius: 3px; background: #1a3c5e; }
        .btn-primary-custom {
            background: #1a3c5e; color: white; border: none;
            border-radius: 8px; padding: 8px 18px; font-size: 0.875rem;
            font-weight: 600; transition: background 0.2s;
        }
        .btn-primary-custom:hover { background: #0f2540; color: white; }
        .search-box { border-radius: 10px; border: 1.5px solid #e5e7eb; padding: 10px 14px; }
        .search-box:focus { border-color: #1a3c5e; box-shadow: 0 0 0 3px rgba(26,60,94,0.1); outline: none; }
        .action-btn { padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; }
        .page-link { color: #1a3c5e; }
        .page-item.active .page-link { background: #1a3c5e; border-color: #1a3c5e; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-journals me-2" style="color:#c8963e;"></i>Book Catalog</h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;">
                <?= number_format($total_rows) ?> book<?= $total_rows != 1 ? 's' : '' ?> found
            </p>
        </div>
        <?php if (has_role(['admin', 'librarian'])): ?>
        <a href="add.php" class="btn btn-primary-custom">
            <i class="bi bi-plus-lg me-1"></i>Add Book
        </a>
        <?php endif; ?>
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
                               placeholder="Title, author, or ISBN..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Category</label>
                    <select name="category" class="form-select search-box">
                        <option value="">All Categories</option>
                        <?php
                        $categories->data_seek(0);
                        while ($cat = $categories->fetch_assoc()):
                        ?>
                        <option value="<?= $cat['id'] ?>" <?= $cat_filter == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select search-box">
                        <option value="">All Status</option>
                        <option value="available"   <?= $status_filter === 'available'   ? 'selected' : '' ?>>Available</option>
                        <option value="unavailable" <?= $status_filter === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom w-100">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Books Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="width:64px;">Cover</th>
                            <th>Title / Author</th>
                            <th>ISBN</th>
                            <th>Category</th>
                            <th>Copies</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($books->num_rows > 0): ?>
                        <?php while ($book = $books->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <img
                                    src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($book['cover_image']) ?>"
                                    onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                                    class="book-cover" alt="Cover"
                                >
                            </td>
                            <td>
                                <a href="view.php?id=<?= $book['id'] ?>" class="fw-semibold text-decoration-none" style="color:#1a3c5e;">
                                    <?= htmlspecialchars($book['title']) ?>
                                </a>
                                <div class="text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($book['author']) ?></div>
                                <?php if ($book['publication_year']): ?>
                                <div class="text-muted" style="font-size:0.75rem;"><?= $book['publication_year'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="text-muted font-monospace" style="font-size:0.8rem;"><?= htmlspecialchars($book['isbn']) ?></span></td>
                            <td>
                                <span class="badge rounded-pill" style="background:#e8f0fe; color:#1a3c5e; font-size:0.75rem;">
                                    <?= htmlspecialchars($book['category']) ?>
                                </span>
                            </td>
                            <td style="min-width:100px;">
                                <span style="font-size:0.85rem;">
                                    <strong><?= $book['available_copies'] ?></strong>
                                    <span class="text-muted">/ <?= $book['total_copies'] ?></span>
                                </span>
                                <div class="copies-bar">
                                    <?php $pct = $book['total_copies'] > 0 ? ($book['available_copies'] / $book['total_copies'] * 100) : 0; ?>
                                    <div class="copies-fill" style="width:<?= $pct ?>%; background: <?= $pct < 30 ? '#dc2626' : '#1a3c5e' ?>;"></div>
                                </div>
                            </td>
                            <td><span class="text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($book['location'] ?? '—') ?></span></td>
                            <td>
                                <span class="badge badge-<?= $book['status'] === 'available' ? 'avail' : 'unavail' ?>">
                                    <?= ucfirst($book['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="view.php?id=<?= $book['id'] ?>" class="btn btn-sm btn-outline-secondary action-btn" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (has_role(['admin', 'librarian'])): ?>
                                <a href="edit.php?id=<?= $book['id'] ?>" class="btn btn-sm btn-outline-primary action-btn" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (has_role(['admin'])): ?>
                                <button
                                    class="btn btn-sm btn-outline-danger action-btn"
                                    title="Delete"
                                    onclick="confirmDelete(<?= $book['id'] ?>, '<?= htmlspecialchars(addslashes($book['title'])) ?>')"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-search" style="font-size:2rem; display:block; margin-bottom:8px;"></i>
                                No books found. <?= has_role(['admin','librarian']) ? '<a href="add.php">Add the first book</a>' : '' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center px-4 py-3">
            <small class="text-muted">
                Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $cat_filter ?>&status=<?= urlencode($status_filter) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Delete Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteBookTitle"></strong>?
                This action <span class="text-danger">cannot be undone</span>.
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Yes, Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(id, title) {
    document.getElementById('deleteBookTitle').textContent = title;
    document.getElementById('deleteConfirmBtn').href = 'delete.php?id=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>