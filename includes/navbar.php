<?php
// includes/navbar.php - Top Navigation Bar
$user   = current_user();
$cart_n = count($_SESSION['reservation_cart'] ?? []);
?>
<nav class="navbar navbar-expand-lg navbar-dark" style="background: #1a3c5e;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= APP_URL ?>/dashboard.php">
            <i class="bi bi-book-half" style="color:#c8963e; font-size:1.3rem;"></i>
            <span style="font-family:'Playfair Display',serif;">SLS</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/books/index.php"><i class="bi bi-journals me-1"></i>Books</a></li>

                <?php if (has_role(['admin', 'librarian'])): ?>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/borrow/index.php"><i class="bi bi-arrow-left-right me-1"></i>Borrowing</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/reservations/index.php"><i class="bi bi-bookmark-star me-1"></i>Reservations</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/users/index.php"><i class="bi bi-people me-1"></i>Users</a></li>
                <?php endif; ?>

                <?php if (has_role(['student', 'teacher'])): ?>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/borrow/my_borrows.php"><i class="bi bi-bookmark-check me-1"></i>My Books</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/reservations/index.php"><i class="bi bi-bookmark-star me-1"></i>Reservations</a></li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1" href="<?= APP_URL ?>/reservations/cart.php">
                        <i class="bi bi-cart3"></i> Cart
                        <?php if ($cart_n > 0): ?>
                        <span class="badge rounded-pill" style="background:#c8963e; font-size:0.65rem;"><?= $cart_n ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/announcements/index.php"><i class="bi bi-megaphone me-1"></i>Announcements</a></li>

                <?php if (has_role(['admin', 'librarian'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-bar-chart-line me-1"></i>Reports</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/reports/borrows.php"><i class="bi bi-journal-text me-2"></i>Borrow History</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/reports/overdue.php"><i class="bi bi-clock-history me-2"></i>Overdue Books</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/reports/fines.php"><i class="bi bi-cash-coin me-2"></i>Fines Report</a></li>
                        <?php if (has_role(['admin'])): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/reports/activity.php"><i class="bi bi-activity me-2"></i>Activity Logs</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item"><span class="badge rounded-pill" style="background:#c8963e; font-size:0.75rem;"><?= ucfirst($user['role']) ?></span></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                        <img src="<?= APP_URL ?>/uploads/profiles/<?= htmlspecialchars($user['pic']) ?>"
                             onerror="this.src='<?= APP_URL ?>/assets/img/default.png'"
                             class="rounded-circle" width="32" height="32"
                             style="object-fit:cover; border:2px solid #c8963e;" alt="Profile">
                        <span class="d-none d-lg-inline"><?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><div class="dropdown-item-text"><strong><?= htmlspecialchars($user['name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($user['school_id']) ?></small></div></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/profile.php?tab=password"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>