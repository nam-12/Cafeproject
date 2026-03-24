<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Lấy tham số lọc
 $report_type = isset($_GET['type']) ? $_GET['type'] : 'day';
 $from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
 $to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

// Hàm tính doanh thu theo khoảng thời gian
function getRevenue($pdo, $from, $to) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(o.total_amount), 0) as total_revenue,
            COALESCE(AVG(o.total_amount), 0) as avg_order_value,
            COUNT(DISTINCT DATE(o.completed_at)) as days_count
        FROM orders o
        WHERE o.status = 'completed'
        AND DATE(o.completed_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    return $stmt->fetch();
}

function getTopProducts($pdo, $from, $to, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT 
            oi.product_name,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.subtotal) as total_revenue
        FROM order_items oi
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.status = 'completed'
        AND DATE(o.completed_at) BETWEEN ? AND ?
        GROUP BY oi.product_id, oi.product_name
        ORDER BY total_quantity DESC
        LIMIT ?
    ");
    $stmt->execute([$from, $to, $limit]);
    return $stmt->fetchAll();
}

switch ($report_type) {
    case 'day':
        $from_date = $to_date = date('Y-m-d');
        $title = "Báo cáo hôm nay (" . date('d/m/Y') . ")";
        break;
    case 'week':
        $from_date = date('Y-m-d', strtotime('monday this week'));
        $to_date = date('Y-m-d', strtotime('sunday this week'));
        $title = "Báo cáo tuần này";
        break;
    case 'month':
        $from_date = date('Y-m-01');
        $to_date = date('Y-m-t');
        $title = "Báo cáo tháng này";
        break;
    case 'custom':
        $title = "Báo cáo tùy chỉnh";
        break;
}

 $revenue_data = getRevenue($pdo, $from_date, $to_date);
 $top_products = getTopProducts($pdo, $from_date, $to_date);

// Lấy dữ liệu biểu đồ (7 ngày gần nhất)
 $chart_stmt = $pdo->prepare("
    SELECT 
        DATE(o.completed_at) as date,
        COALESCE(SUM(o.total_amount), 0) as revenue,
        COUNT(o.id) as orders
    FROM orders o
    WHERE o.status = 'completed'
    AND DATE(o.completed_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
    GROUP BY DATE(o.completed_at)
    ORDER BY date ASC
");
 $chart_stmt->execute();
 $chart_data = $chart_stmt->fetchAll();

// Xử lý xuất báo cáo
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=bao_cao_' . $from_date . '_' . $to_date . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, ['BÁO CÁO DOANH THU']);
    fputcsv($output, []);

    fputcsv($output, ['Khoảng thời gian']);
    fputcsv($output, ['Từ ngày', $from_date]);
    fputcsv($output, ['Đến ngày', $to_date]);
    fputcsv($output, []);

    fputcsv($output, ['THỐNG KÊ TỔNG QUAN']);
    fputcsv($output, ['Tổng đơn hàng', $revenue_data['total_orders']]);
    fputcsv($output, ['Tổng doanh thu (VND)', number_format($revenue_data['total_revenue'])]);
    fputcsv($output, ['Giá trị trung bình (VND)', number_format($revenue_data['avg_order_value'])]);
    fputcsv($output, []);

    fputcsv($output, ['SẢN PHẨM BÁN CHẠY']);
    fputcsv($output, ['STT', 'Tên sản phẩm', 'Số lượng', 'Doanh thu (VND)']);

    $index = 1;
    foreach ($top_products as $product) {
        fputcsv($output, [
            $index++,
            $product['product_name'],
            $product['total_quantity'],
            $product['total_revenue']
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo Doanh thu - Cafe Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/cssad/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="spinner-container" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="layout-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header-section">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h1><?php echo $title; ?></h1>
                        <p>Phân tích và báo cáo doanh thu</p>
                    </div>
                </div>
                <a href="?type=<?php echo $report_type; ?>&from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&export=1" class="btn-export">
                    <i class="fas fa-file-excel"></i>
                    Xuất báo cáo
                </a>
            </div>

            <!-- Filter -->
            <div class="search-filter-section">
                <form method="GET" id="filterForm">
                    <div class="search-container">
                        <div class="filter-box">
                            <label class="form-label">Loại báo cáo</label>
                            <select name="type" class="filter-select" id="reportType" onchange="toggleCustomDate(this.value)">
                                <option value="day" <?php echo $report_type == 'day' ? 'selected' : ''; ?>>Hôm nay</option>
                                <option value="week" <?php echo $report_type == 'week' ? 'selected' : ''; ?>>Tuần này</option>
                                <option value="month" <?php echo $report_type == 'month' ? 'selected' : ''; ?>>Tháng này</option>
                                <option value="custom" <?php echo $report_type == 'custom' ? 'selected' : ''; ?>>Tùy chỉnh</option>
                            </select>
                        </div>
                        <div class="filter-box" id="from_date_div" style="display: <?php echo $report_type == 'custom' ? 'block' : 'none'; ?>;">
                            <label class="form-label">Từ ngày</label>
                            <input type="date" name="from" class="filter-input" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="filter-box" id="to_date_div" style="display: <?php echo $report_type == 'custom' ? 'block' : 'none'; ?>;">
                            <label class="form-label">Đến ngày</label>
                            <input type="date" name="to" class="filter-input" value="<?php echo $to_date; ?>">
                        </div>
                        <button type="submit" class="btn-modal btn-primary-modal">
                            <i class="fas fa-search"></i>
                            Xem báo cáo
                        </button>
                    </div>
                </form>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($revenue_data['total_orders']); ?></div>
                            <div class="stat-label">Tổng đơn hàng</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($revenue_data['total_revenue']); ?></div>
                            <div class="stat-label">Tổng doanh thu (đ)</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($revenue_data['avg_order_value']); ?></div>
                            <div class="stat-label">Giá trị TB/Đơn (đ)</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts and Products -->
            <div class="row g-4">
                <!-- Revenue Chart -->
                <div class="col-lg-8">
                    <div class="table-container">
                        <div class="card-header-custom">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Biểu đồ doanh thu 7 ngày gần đây
                            </h5>
                        </div>
                        <div class="card-body-custom">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top Products -->
                <div class="col-lg-4">
                    <div class="table-container">
                        <div class="card-header-custom">
                            <h5 class="mb-0">
                                <i class="fas fa-star me-2"></i>
                                Top sản phẩm bán chạy
                            </h5>
                        </div>
                        <div class="card-body-custom">
                            <?php if (count($top_products) > 0): ?>
                                <div class="product-list">
                                    <?php foreach ($top_products as $index => $product): ?>
                                        <div class="product-item">
                                            <div class="product-rank">#<?php echo $index + 1; ?></div>
                                            <div class="product-info">
                                                <div class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                                <div class="product-quantity">SL: <?php echo $product['total_quantity']; ?></div>
                                            </div>
                                            <div class="product-revenue">
                                                <?php echo number_format($product['total_revenue']); ?>đ
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="fas fa-box-open"></i>
                                    </div>
                                    <h3>Không có dữ liệu</h3>
                                    <p>Chưa có sản phẩm nào được bán trong khoảng thời gian này.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>// Show loading spinner
function showLoading() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

// Hide loading spinner
function hideLoading() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

// Toggle custom date fields
function toggleCustomDate(type) {
    const fromDiv = document.getElementById('from_date_div');
    const toDiv = document.getElementById('to_date_div');
    if (type === 'custom') {
        fromDiv.style.display = 'block';
        toDiv.style.display = 'block';
    } else {
        fromDiv.style.display = 'none';
        toDiv.style.display = 'none';
    }
}

// Initialize chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    const chartData = <?php echo json_encode($chart_data); ?>;
    
    // Tạo gradient cho đường
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(34, 197, 94, 0.3)');
    gradient.addColorStop(1, 'rgba(34, 197, 94, 0.01)');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.map(d => {
                const date = new Date(d.date);
                return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
            }),
            datasets: [{
                label: 'Doanh thu (VNĐ)',
                data: chartData.map(d => d.revenue),
                backgroundColor: gradient, // Sử dụng gradient
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: 'rgba(34, 197, 94, 1)',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 14,
                            weight: 'bold'
                        },
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(30, 41, 59, 0.9)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return 'Doanh thu: ' + context.parsed.y.toLocaleString('vi-VN') + 'đ';
                        },
                        title: function(context) {
                            return 'Ngày: ' + context[0].label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(148, 163, 184, 0.1)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000).toFixed(1) + 'Mđ';
                            } else if (value >= 1000) {
                                return (value / 1000).toFixed(0) + 'Kđ';
                            }
                            return value.toLocaleString('vi-VN') + 'đ';
                        },
                        font: {
                            size: 12
                        },
                        padding: 10
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 12
                        },
                        padding: 10
                    }
                }
            },
            elements: {
                line: {
                    borderJoinStyle: 'round'
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeInOutQuart'
            }
        }
    });
});

// Handle form submission with loading spinner
document.getElementById('filterForm').addEventListener('submit', function() {
    showLoading();
});

// Mobile menu toggle
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('active');
}</script>
</body>
</html>