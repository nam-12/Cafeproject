<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Ghi nhật ký truy cập
logActivity('view_dashboard', 'dashboard', 'Truy cập trang chủ');

// Lấy thống kê 
 $stats = [];

// Tổng sản phẩm
 $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE status='active'");
 $stats['total_products'] = $stmt->fetch()['total'];

// Sản phẩm sắp hết hàng
 $stmt = $pdo->query("SELECT COUNT(*) as total FROM low_stock_products");
 $stats['low_stock'] = $stmt->fetch()['total'];

// Doanh thu
 $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(completed_at) = CURDATE() AND status='completed'");
 $stats['today_revenue'] = $stmt->fetch()['total'];

// Đơn hàng
 $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()");
 $stats['today_orders'] = $stmt->fetch()['total'];

// Lấy danh sách sản phẩm sắp hết hàng
 $low_stock_products = $pdo->query("SELECT * FROM low_stock_products LIMIT 5")->fetchAll();

// Doanh thu 7 ngày gần đây
 $recent_revenue = $pdo->query("
    SELECT DATE(completed_at) as date, SUM(total_amount) as revenue
    FROM orders
    WHERE completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status='completed'
    GROUP BY DATE(completed_at)
    ORDER BY date DESC
")->fetchAll();

// Tính toán phần trăm thay đổi so với hôm qua
 $yesterday_revenue = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status='completed'")->fetch()['total'];
 $revenue_change = $yesterday_revenue > 0 ? (($stats['today_revenue'] - $yesterday_revenue) / $yesterday_revenue) * 100 : 0;

 $yesterday_orders = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetch()['total'];
 $orders_change = $yesterday_orders > 0 ? (($stats['today_orders'] - $yesterday_orders) / $yesterday_orders) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Cửa hàng Cafe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/index.css">
   
</head>
<body class="admin-layout">
    <!-- Sidebar -->
    
        <?php include 'sidebar.php'; ?>
    
    
    <!-- Main content -->
    <div class="content-wrapper">
        <div class="page-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-sm mobile-menu-btn me-3" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h1>  <i class="fas fa-home me-2 "></i>Bảng điều khiển</h1>
                </div>
            </div>
            <div class="date-display">
                <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                <div class="card stat-card primary">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-mug-hot"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_products']; ?></div>
                        <div class="stat-label">Tổng sản phẩm hoạt động</div>
                        <div class="progress mt-3" style="height: 6px;">
                            <div class="progress-bar" role="progressbar" style="width: 100%; background-color: var(--primary-color);" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                <div class="card stat-card danger">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                        <div class="stat-label">Sắp hết hàng</div>
                        <?php if ($stats['low_stock'] > 0): ?>
                        <div class="stat-change negative">
                            <i class="fas fa-arrow-up"></i> Cần nhập hàng
                        </div>
                        <?php endif; ?>
                        <div class="progress mt-3" style="height: 6px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo min($stats['low_stock'] * 20, 100); ?>%; background-color: #fca5a5;" aria-valuenow="<?php echo min($stats['low_stock'] * 20, 100); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                <div class="card stat-card success">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['today_revenue']); ?>đ</div>
                        <div class="stat-label">Doanh thu hôm nay</div>
                        <div class="stat-change <?php echo $revenue_change >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $revenue_change >= 0 ? 'up' : 'down'; ?>"></i> 
                            <?php echo abs(round($revenue_change, 1)); ?>% so với hôm qua
                        </div>
                        <div class="progress mt-3" style="height: 6px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo min($stats['today_revenue'] / 10000, 100); ?>%; background-color: #86efac;" aria-valuenow="<?php echo min($stats['today_revenue'] / 10000, 100); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                <div class="card stat-card warning">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['today_orders']; ?></div>
                        <div class="stat-label">Đơn hàng hôm nay</div>
                        <div class="stat-change <?php echo $orders_change >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $orders_change >= 0 ? 'up' : 'down'; ?>"></i> 
                            <?php echo abs(round($orders_change, 1)); ?>% so với hôm qua
                        </div>
                        <div class="progress mt-3" style="height: 6px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo min($stats['today_orders'] * 5, 100); ?>%; background-color: #fcd34d;" aria-valuenow="<?php echo min($stats['today_orders'] * 5, 100); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <?php if ($stats['low_stock'] > 0): ?>
        <div class="alert alert-stock d-flex align-items-center" role="alert">
            <i class="fas fa-exclamation-circle me-4"></i>
            <div>
                <h5 class="alert-heading mb-1">Cảnh báo tồn kho!</h5>
                <p class="mb-0">Có <?php echo $stats['low_stock']; ?> sản phẩm sắp hết hàng. Vui lòng nhập thêm hàng.</p>
            </div>
            <div class="ms-auto">
                <a href="inventory.php" class="btn btn-action btn-danger">Nhập hàng ngay</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Low Stock Products & Recent Revenue in the same row -->
        <div class="row">
            <!-- Low Stock Products -->
            <div class="col-lg-6 col-md-12 mb-4">
                <div class="card content-card danger h-100">
                    <div class="card-header">
                        <i class="fas fa-exclamation-triangle"></i>
                        Sản phẩm sắp hết hàng
                    </div>
                    <div class="card-body">
                        <?php if (count($low_stock_products) > 0): ?>
                        <div class="product-list">
                            <?php foreach ($low_stock_products as $item): ?>
                            <div class="product-item">
                                <div class="product-icon">
                                    <i class="fas fa-coffee"></i>
                                </div>
                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="product-category"><?php echo htmlspecialchars($item['category_name']); ?></div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value text-danger"><?php echo $item['quantity']; ?> <?php echo $item['unit']; ?></div>
                                    <div class="stock-min">Tối thiểu: <?php echo $item['min_quantity']; ?> <?php echo $item['unit']; ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-4">
                            <a href="inventory.php" class="btn btn-action btn-danger">Xem tất cả</a>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>Tất cả sản phẩm đều đủ hàng.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Revenue -->
            <div class="col-lg-6 col-md-12 mb-4">
                <div class="card content-card success h-100">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i>
                        Doanh thu 7 ngày gần đây
                    </div>
                    <div class="card-body">
                        <?php if (count($recent_revenue) > 0): ?>
                        <div class="chart-container">
                            <?php 
                            // Tìm giá trị doanh thu cao nhất để tính toán tỷ lệ phần trăm
                            $max_revenue = max(array_column($recent_revenue, 'revenue'));
                            ?>
                            <?php foreach ($recent_revenue as $rev): ?>
                            <div class="revenue-bar" style="width: <?php echo ($rev['revenue'] / $max_revenue) * 100; ?>%;">
                                <div class="revenue-bar-label"><?php echo date('d/m', strtotime($rev['date'])); ?></div>
                                <div class="revenue-bar-value"><?php echo number_format($rev['revenue']); ?>đ</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-4">
                            <a href="reports.php" class="btn btn-action btn-success">Xem báo cáo chi tiết</a>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>Chưa có dữ liệu doanh thu.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('adminSidebar');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebarOverlay = document.createElement('div');
            sidebarOverlay.className = 'sidebar-overlay';

            // Thêm overlay vào body
            document.body.appendChild(sidebarOverlay);

            // Mobile menu toggle
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');

                    // Prevent body scroll khi sidebar mở
                    if (sidebar.classList.contains('show')) {
                        document.body.style.overflow = 'hidden';
                    } else {
                        document.body.style.overflow = '';
                    }
                });
            }

            // Close sidebar when clicking overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
                document.body.style.overflow = '';
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 992 &&
                    !sidebar.contains(event.target) &&
                    !mobileMenuBtn.contains(event.target) &&
                    sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });

            // Handle escape key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        });
    </script>
</body>
</html>