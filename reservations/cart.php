<?php
// ============================================
// reservations/cart.php - Reservation Cart
// Uses SESSION to hold books before submitting,
// ============================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role(['student', 'teacher']);
$user = current_user();

// ---- Handle ?add= from books/view.php "Add to Cart" button ----
if (!empty($_GET['add'])) {
    $bid = intval($_GET['add']);
    $chk = $conn->prepare("SELECT id, title, author, cover_image, available_copies FROM books WHERE id = ? AND status = 'available' LIMIT 1");
    $chk->bind_param("i", $bid);
    $chk->execute();
    $book = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($book && $book['available_copies'] > 0) {
        $_SESSION['reservation_cart'][$bid] = [
            'book_id' => $bid,
            'title'   => $book['title'],
            'author'  => $book['author'],
            'cover'   => $book['cover_image'],
            'copies'  => $book['available_copies'],
            'expiry'  => date('Y-m-d', strtotime('+3 days')),
        ];
        set_flash('success', "'{$book['title']}' added to your reservation cart.");
    } else {
        set_flash('danger', "Sorry, that book is not available.");
    }
    redirect(APP_URL . '/reservations/cart.php');
}

// ---- SESSION CART: Handle Add to Cart ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action  = clean($_POST['action']);
    $book_id = intval($_POST['book_id'] ?? 0);

    if ($action === 'add' && $book_id > 0) {
        // Check book is available
        $chk = $conn->prepare("SELECT id, title, author, cover_image, available_copies FROM books WHERE id = ? AND status = 'available' LIMIT 1");
        $chk->bind_param("i", $book_id);
        $chk->execute();
        $book = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($book && $book['available_copies'] > 0) {
            // Add to session cart (keyed by book_id, like carttest.php)
            $_SESSION['reservation_cart'][$book_id] = [
                'book_id'    => $book_id,
                'title'      => $book['title'],
                'author'     => $book['author'],
                'cover'      => $book['cover_image'],
                'copies'     => $book['available_copies'],
                'expiry'     => date('Y-m-d', strtotime('+3 days')),
            ];
            set_flash('success', "'{$book['title']}' added to your reservation cart.");
        } else {
            set_flash('danger', "Sorry, that book is not available.");
        }
        redirect(APP_URL . '/reservations/cart.php');
    }

    // Remove single book from cart (like the Remove button in carttest.php)
    if ($action === 'remove' && $book_id > 0) {
        if (isset($_SESSION['reservation_cart'][$book_id])) {
            $removed = $_SESSION['reservation_cart'][$book_id]['title'];
            unset($_SESSION['reservation_cart'][$book_id]);
            set_flash('info', "'{$removed}' removed from your cart.");
        }
        redirect(APP_URL . '/reservations/cart.php');
    }

    // Update expiry date for a cart item
    if ($action === 'update_expiry' && $book_id > 0) {
        $expiry = clean($_POST['expiry'] ?? '');
        if (!empty($expiry) && strtotime($expiry) > time()) {
            $_SESSION['reservation_cart'][$book_id]['expiry'] = $expiry;
        }
        redirect(APP_URL . '/reservations/cart.php');
    }

    // Clear entire cart
    if ($action === 'clear') {
        unset($_SESSION['reservation_cart']);
        set_flash('info', "Reservation cart cleared.");
        redirect(APP_URL . '/reservations/cart.php');
    }

    // Submit all cart items as reservations
    if ($action === 'submit') {
        if (empty($_SESSION['reservation_cart'])) {
            set_flash('danger', "Your cart is empty.");
            redirect(APP_URL . '/reservations/cart.php');
        }

        $submitted = 0;
        $skipped   = 0;

        foreach ($_SESSION['reservation_cart'] as $bid => $item) {
            // Skip if already has active reservation or borrow
            $dup = $conn->prepare(
                "SELECT id FROM reservations WHERE user_id=? AND book_id=? AND status IN ('pending','approved') LIMIT 1"
            );
            $dup->bind_param("ii", $user['id'], $bid);
            $dup->execute();
            $already_reserved = $dup->get_result()->num_rows > 0;
            $dup->close();

            $dup2 = $conn->prepare(
                "SELECT id FROM borrow_records WHERE user_id=? AND book_id=? AND status IN ('borrowed','overdue') LIMIT 1"
            );
            $dup2->bind_param("ii", $user['id'], $bid);
            $dup2->execute();
            $already_borrowed = $dup2->get_result()->num_rows > 0;
            $dup2->close();

            if ($already_reserved || $already_borrowed) {
                $skipped++;
                continue;
            }

            $stmt = $conn->prepare(
                "INSERT INTO reservations (user_id, book_id, expiry_date, status) VALUES (?, ?, ?, 'pending')"
            );
            $stmt->bind_param("iis", $user['id'], $bid, $item['expiry']);
            $stmt->execute();
            $stmt->close();
            $submitted++;

            log_activity($conn, $user['id'], 'RESERVE_BOOK',
                "Reserved book ID $bid ({$item['title']}) via cart.");
        }

        // Clear cart after submitting
        unset($_SESSION['reservation_cart']);

        $msg = "Successfully submitted $submitted reservation(s).";
        if ($skipped > 0) $msg .= " $skipped skipped (already reserved or borrowed).";
        set_flash('success', $msg);
        redirect(APP_URL . '/reservations/index.php');
    }
}

// ---- Init cart if not set (like carttest.php) ----
if (!isset($_SESSION['reservation_cart'])) {
    $_SESSION['reservation_cart'] = [];
}

// ---- Fetch available books (excluding ones already in cart) ----
$in_cart     = array_keys($_SESSION['reservation_cart']);
$cart_count  = count($in_cart);

$books = $conn->query(
    "SELECT b.id, b.title, b.author, b.cover_image, b.available_copies, c.name AS category
     FROM books b JOIN categories c ON c.id = b.category_id
     WHERE b.available_copies > 0 AND b.status = 'available'
     ORDER BY b.title ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Cart — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; background:#f4f1eb; }
        .card { border:none; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
        .page-title { font-family:'Playfair Display',serif; color:#1a3c5e; font-size:1.6rem; }
        .section-heading { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#c8963e; border-bottom:1.5px solid #f0ede6; padding-bottom:8px; margin-bottom:16px; }
        .book-thumb { width:44px; height:60px; object-fit:cover; border-radius:5px; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .book-card { border:1.5px solid #e5e7eb; border-radius:12px; padding:14px; background:white; display:flex; align-items:center; gap:12px; margin-bottom:10px; transition:border-color 0.2s; }
        .book-card:hover { border-color:#1a3c5e; }
        .book-card.in-cart { border-color:#c8963e; background:#fffbf5; }
        .cart-item { border:1.5px solid #e5e7eb; border-radius:12px; padding:14px 16px; background:white; margin-bottom:10px; }
        .table th { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
        .table td { vertical-align:middle; font-size:0.875rem; }
        .btn-add   { background:#1a3c5e; color:white; border:none; border-radius:8px; padding:6px 14px; font-size:0.8rem; font-weight:600; white-space:nowrap; }
        .btn-add:hover { background:#0f2540; color:white; }
        .btn-add:disabled { background:#9ca3af; }
        .btn-remove { background:#fee2e2; color:#dc2626; border:none; border-radius:8px; padding:6px 14px; font-size:0.8rem; font-weight:600; }
        .btn-remove:hover { background:#fecaca; color:#dc2626; }
        .btn-submit { background:#1e6b3c; color:white; border:none; border-radius:10px; padding:11px 28px; font-weight:600; }
        .btn-submit:hover { background:#155230; color:white; }
        .cart-badge { background:#c8963e; color:white; border-radius:50%; width:22px; height:22px; font-size:0.75rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
        .empty-cart { text-align:center; padding:40px 20px; color:#9ca3af; }
        .form-control { border:1.5px solid #e5e7eb; border-radius:8px; padding:6px 10px; font-size:0.85rem; }
        .form-control:focus { border-color:#1a3c5e; box-shadow:0 0 0 3px rgba(26,60,94,0.1); }
        .session-info { background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px; padding:12px 16px; font-size:0.82rem; color:#1d4ed8; margin-bottom:16px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<div class="container-fluid px-4 py-4">

    <?php show_flash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">
                <i class="bi bi-cart3 me-2" style="color:#c8963e;"></i>Reservation Cart
                <?php if ($cart_count > 0): ?>
                <span class="cart-badge ms-2"><?= $cart_count ?></span>
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0" style="font-size:0.85rem;">
                Add books to your cart, then submit all at once
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>My Reservations
        </a>
    </div>

    <!-- Session info banner — shows how session stores the cart -->
    <div class="session-info">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Session Cart:</strong>
        Your cart is stored in <code>$_SESSION['reservation_cart']</code> and will persist
        until you submit, clear it, or log out.
        <?php if ($cart_count > 0): ?>
        Currently holding <strong><?= $cart_count ?></strong> book<?= $cart_count != 1 ? 's' : '' ?>.
        <?php else: ?>
        Currently empty.
        <?php endif; ?>
    </div>

    <div class="row g-4">

        <!-- LEFT: Available Books -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-4">
                    <div class="section-heading">Available Books — Click to Add</div>
                    <?php if ($books->num_rows > 0): ?>
                    <?php while ($b = $books->fetch_assoc()):
                        $already_in_cart = isset($_SESSION['reservation_cart'][$b['id']]);
                    ?>
                    <div class="book-card <?= $already_in_cart ? 'in-cart' : '' ?>">
                        <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($b['cover_image']) ?>"
                             onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                             class="book-thumb flex-shrink-0" alt="cover">
                        <div class="flex-grow-1">
                            <div class="fw-semibold" style="font-size:0.875rem; color:#1a3c5e;">
                                <?= htmlspecialchars($b['title']) ?>
                            </div>
                            <small class="text-muted"><?= htmlspecialchars($b['author']) ?> · <?= htmlspecialchars($b['category']) ?></small>
                            <div>
                                <span class="badge" style="background:#dcfce7; color:#15803d; font-size:0.7rem;">
                                    <?= $b['available_copies'] ?> available
                                </span>
                                <?php if ($already_in_cart): ?>
                                <span class="badge" style="background:#fef3c7; color:#d97706; font-size:0.7rem;">
                                    <i class="bi bi-cart-check me-1"></i>In Cart
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" action="cart.php">
                            <input type="hidden" name="action"  value="add">
                            <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn-add" <?= $already_in_cart ? 'disabled' : '' ?>>
                                <?= $already_in_cart ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-cart-plus me-1"></i>Add' ?>
                            </button>
                        </form>
                    </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <div class="empty-cart">
                        <i class="bi bi-journals" style="font-size:2.5rem; display:block; margin-bottom:10px;"></i>
                        No books available right now.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Cart (Session) -->
        <div class="col-lg-5">
            <div class="card" style="position:sticky; top:80px;">
                <div class="card-body p-4">
                    <div class="section-heading d-flex justify-content-between align-items-center">
                        <span>Your Cart <code style="font-size:0.7rem;">$_SESSION</code></span>
                        <?php if ($cart_count > 0): ?>
                        <form method="POST" action="cart.php" class="d-inline">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    style="border-radius:6px; font-size:0.75rem;"
                                    onclick="return confirm('Clear all items from your cart?')">
                                <i class="bi bi-trash me-1"></i>Clear All
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($cart_count > 0): ?>

                    <?php foreach ($_SESSION['reservation_cart'] as $bid => $item): ?>
                    <div class="cart-item">
                        <div class="d-flex align-items-start gap-2">
                            <img src="<?= APP_URL ?>/uploads/books/<?= htmlspecialchars($item['cover']) ?>"
                                 onerror="this.src='<?= APP_URL ?>/assets/img/default_book.png'"
                                 class="book-thumb flex-shrink-0" alt="cover">
                            <div class="flex-grow-1">
                                <div class="fw-semibold" style="font-size:0.85rem; color:#1a3c5e;">
                                    <?= htmlspecialchars($item['title']) ?>
                                </div>
                                <small class="text-muted"><?= htmlspecialchars($item['author']) ?></small>

                                <!-- Expiry date per item -->
                                <form method="POST" action="cart.php" class="mt-2 d-flex gap-2 align-items-center">
                                    <input type="hidden" name="action"  value="update_expiry">
                                    <input type="hidden" name="book_id" value="<?= $bid ?>">
                                    <label style="font-size:0.75rem; color:#6b7280; white-space:nowrap;">Expires:</label>
                                    <input type="date" name="expiry" class="form-control"
                                           value="<?= htmlspecialchars($item['expiry']) ?>"
                                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                           max="<?= date('Y-m-d', strtotime('+14 days')) ?>"
                                           onchange="this.form.submit()">
                                </form>
                            </div>

                            <!-- Remove button (like carttest.php Remove) -->
                            <form method="POST" action="cart.php">
                                <input type="hidden" name="action"  value="remove">
                                <input type="hidden" name="book_id" value="<?= $bid ?>">
                                <button type="submit" class="btn-remove" title="Remove">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Submit Cart -->
                    <form method="POST" action="cart.php" class="mt-3">
                        <input type="hidden" name="action" value="submit">
                        <button type="submit" class="btn-submit w-100"
                                onclick="return confirm('Submit all <?= $cart_count ?> reservation(s)?')">
                            <i class="bi bi-send me-2"></i>Submit <?= $cart_count ?> Reservation<?= $cart_count != 1 ? 's' : '' ?>
                        </button>
                    </form>
                    <p class="text-muted mt-2 mb-0" style="font-size:0.78rem; text-align:center;">
                        The librarian will review and approve each one.
                    </p>

                    <?php else: ?>
                    <div class="empty-cart">
                        <i class="bi bi-cart3" style="font-size:2.5rem; display:block; margin-bottom:10px; color:#d1d5db;"></i>
                        <p class="mb-0">Your cart is empty.</p>
                        <p class="text-muted" style="font-size:0.82rem;">Click <strong>Add</strong> on any book to get started.</p>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>