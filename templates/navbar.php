<?php
require_once '../config/init.php';
// Đếm số lượng trong giỏ hàng
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));

// Xác định trang hiện tại để set class 'active' (Tùy chọn)
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar navbar-premium navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand-premium" href="index.php">
            <i class="fas fa-mug-hot"></i> Coffee House
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            style="border-color: var(--gold); color: var(--gold);">
            <span class="navbar-toggler-icon" style="filter: invert(1);"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link-premium <?= ($current_page == 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="fas fa-home me-2"></i>Trang chủ
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link-premium <?= ($current_page == 'coupons.php') ? 'active' : '' ?>" href="coupons.php">
                        <i class="fas fa-ticket-alt me-2"></i>Khuyến mãi
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link-premium <?= ($current_page == 'track_order.php') ? 'active' : '' ?>" href="track_order.php">
                        <i class="fas fa-shipping-fast me-2"></i>Đơn hàng
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link-premium <?= ($current_page == 'profile.php') ? 'active' : '' ?>" href="profile.php">
                        <i class="fas fa-user me-2"></i>Tài khoản
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link-premium" href="../home.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
                    </a>
                </li>
                <li class="nav-item ms-3">
                    <a href="cart.php" class="cart-btn-premium">
                        <i class="fas fa-shopping-cart me-2"></i>
                        Giỏ hàng
                        <?php if ($cart_count > 0): ?>
                            <span class="cart-badge-premium"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<link rel="stylesheet" href="../assets/css/navbar.css">