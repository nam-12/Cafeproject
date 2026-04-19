<?php
require_once '../config/init.php';
// ========== BƯỚC 1: KIỂM TRA ĐĂNG NHẬP ==========
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Vui lòng đăng nhập để xem đơn hàng!";
    header('Location: ../login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
// ========== BƯỚC 2: LẤY THÔNG TIN USER ==========
try {
    $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
} catch (PDOException $e) {
    die("Lỗi truy vấn user: " . $e->getMessage());
}
// ========== BƯỚC 3: LẤY DANH SÁCH ĐƠN HÀNG CỦA USER ==========
try {
    $orders_stmt = $pdo->prepare("
        SELECT 
            o.*,
            COUNT(oi.id) AS item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.user_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $orders_stmt->execute([$user_id]);
    $orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    // --- NEW: normalize empty/undefined status -> 'cancelled' to avoid "Không xác định"
    foreach ($orders as &$ord) {
        $raw = trim((string)($ord['status'] ?? ''));
        if ($raw === '') {
            $ord['status'] = 'cancelled';
        } else {
            $ord['status'] = $raw;
        }
    }
    unset($ord);
} catch (PDOException $e) {
    die("Lỗi lấy danh sách đơn hàng: " . $e->getMessage());
}
// ========== BƯỚC 4: XỬ LÝ CHI TIẾT ĐƠN HÀNG (NẾU CÓ) ==========
$order_detail = null;
$order_items = [];
$tracking_history = [];
if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    
    try {
        // Lấy thông tin đơn hàng (hỗ trợ user_id hoặc customer_id)
        $detail_stmt = $pdo->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND user_id = ?
        ");
        $detail_stmt->execute([$order_id, $user_id]);
        $order_detail = $detail_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order_detail) {
            // Lấy danh sách sản phẩm trong đơn hàng
            $items_stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = ?
                ORDER BY id ASC
            ");
            $items_stmt->execute([$order_id]);
            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Lấy lịch sử tracking
            $tracking_stmt = $pdo->prepare("
                SELECT * FROM order_tracking 
                WHERE order_id = ?
                ORDER BY created_at DESC
            ");
            $tracking_stmt->execute([$order_id]);
            $tracking_history = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);
            // Nếu đơn hàng đã hủy, chỉ giữ lại các event từ hủy trở về trước
            $cancel_index = null;
            foreach ($tracking_history as $index => $track) {
                if ($track['status'] === 'cancelled') {
                    $cancel_index = $index;
                    break;
                }
            }
            if ($cancel_index !== null) {
                $tracking_history = array_slice($tracking_history, $cancel_index);
                $tracking_history = array_values($tracking_history);
            }
            // --- NEW: nếu order_detail->status rỗng thì mặc định 'cancelled'
            $raw_detail_status = trim((string)($order_detail['status'] ?? ''));
            if ($raw_detail_status === '') {
                $order_detail['status'] = 'cancelled';
            } else {
                $order_detail['status'] = $raw_detail_status;
            }

            // Auto-fill timeline từ pending đến trạng thái hiện tại
            if (!empty($order_detail['status'])) {
                $current_status_code = $order_detail['status'];
                $all_statuses = ['pending', 'confirmed', 'preparing', 'shipping', 'completed', 'cancelled'];
                $current_index = array_search($current_status_code, $all_statuses);
                
                if ($current_index !== false) {
                    // Nếu đơn hàng đã hủy, không thêm các trạng thái giả mạo sau hủy
                    if ($current_status_code === 'cancelled') {
                        if (count($tracking_history) === 0) {
                            // Nếu không có record tracking nhưng đơn đã hủy, tạo ít nhất hai mốc: pending -> cancelled
                            $created_time = strtotime($order_detail['created_at']);
                            $tracking_history = [
                                [
                                    'status' => 'cancelled',
                                    'note' => '',
                                    'created_at' => date('Y-m-d H:i:s', $created_time + 3600)
                                ],
                                [
                                    'status' => 'pending',
                                    'note' => '',
                                    'created_at' => date('Y-m-d H:i:s', $created_time)
                                ],
                            ];
                        }
                    } else {
                        // Lấy danh sách các status đã có trong tracking history
                        $tracked_statuses = array_column($tracking_history, 'status');
                        $created_time = strtotime($order_detail['created_at']);
                        
                        // Nếu không có tracking records, tạo từ pending đến current status
                        if (count($tracking_history) === 0) {
                            for ($i = 0; $i <= $current_index; $i++) {
                                $tracking_history[] = [
                                    'status' => $all_statuses[$i],
                                    'note' => '',
                                    'created_at' => date('Y-m-d H:i:s', $created_time + ($i * 3600))
                                ];
                            }
                        } else {
                            // Nếu có tracking records nhưng không đủ, thêm các bước còn lại
                            for ($i = 0; $i <= $current_index; $i++) {
                                if (!in_array($all_statuses[$i], $tracked_statuses)) {
                                    // Thêm bước này nếu chưa có - TIME tính từ lúc tạo đơn + i*1h
                                    $tracking_history[] = [
                                        'status' => $all_statuses[$i],
                                        'note' => '',
                                        'created_at' => date('Y-m-d H:i:s', $created_time + ($i * 3600))
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            // Sắp xếp tracking history theo thời gian DESC (mới nhất trước)
            if (!empty($tracking_history)) {
                usort($tracking_history, function($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
            }
        } else {
            $_SESSION['error'] = "Không tìm thấy đơn hàng hoặc bạn không có quyền xem đơn hàng này!";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi lấy chi tiết đơn hàng: " . $e->getMessage();
    }
}
// ========== BƯỚC 5: ĐỊNH NGHĨA THÔNG TIN TRẠNG THÁI ==========
$status_config = [
    'pending' => [
        'text' => 'Chờ xác nhận',
        'icon' => 'fa-clock',
        'color' => 'warning',
        'bg' => '#FFF3CD',
        'description' => 'Đơn hàng đang chờ được xác nhận'
    ],
    'confirmed' => [
        'text' => 'Đã xác nhận',
        'icon' => 'fa-check-circle',
        'color' => 'info',
        'bg' => '#CFF4FC',
        'description' => 'Đơn hàng đã được xác nhận'
    ],
    'preparing' => [
        'text' => 'Đang chuẩn bị',
        'icon' => 'fa-box',
        'color' => 'primary',
        'bg' => '#CFE2FF',
        'description' => 'Đơn hàng đang được chuẩn bị'
    ],
    'shipping' => [
        'text' => 'Đang giao hàng',
        'icon' => 'fa-shipping-fast',
        'color' => 'info',
        'bg' => '#CFF4FC',
        'description' => 'Đơn hàng đang trên đường giao đến bạn'
    ],
    'completed' => [
        'text' => 'Hoàn thành',
        'icon' => 'fa-check-double',
        'color' => 'success',
        'bg' => '#D1E7DD',
        'description' => 'Đơn hàng đã được giao thành công'
    ],
    'cancelled' => [
        'text' => 'Đã hủy',
        'icon' => 'fa-times-circle',
        'color' => 'danger',
        'bg' => '#F8D7DA',
        'description' => 'Đơn hàng đã bị hủy'
    ]
];
// ========== BƯỚC 6: ĐẾM SỐ LƯỢNG GIỎ HÀNG ==========
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
}
// ========== BƯỚC 7: THỐNG KÊ ĐƠN HÀNG ==========
$stats = [
    'total' => count($orders),
    'processing' => 0,
    'shipping' => 0,
    'completed' => 0,
    'cancelled' => 0
];
foreach ($orders as $order) {
    if (in_array($order['status'], ['pending', 'confirmed', 'preparing'])) {
        $stats['processing']++;
    } elseif ($order['status'] === 'shipping') {
        $stats['shipping']++;
    } elseif ($order['status'] === 'completed') {
        $stats['completed']++;
    } elseif ($order['status'] === 'cancelled') {
        $stats['cancelled']++;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đơn hàng - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/track_order.css">
</head>
<body>
    <!-- Navbar -->
    <?php include '../templates/navbar.php'; ?>
    
    <!-- Orders Container -->
    <section class="orders-container">
        <div class="container">
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (!$order_detail): ?>
            <!-- ========== HIỂN THỊ DANH SÁCH ĐƠN HÀNG ========== -->
            <div class="page-title">
                <h1><i class="fas fa-shopping-bag me-3"></i>Đơn hàng của tôi</h1>
                <p style="color: var(--coffee-light); font-size: 1.1rem;">Quản lý và theo dõi đơn hàng của bạn</p>
            </div>
            <!-- Statistics -->
            <div class="orders-stats">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Tổng đơn hàng</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['processing']; ?></div>
                    <div class="stat-label">Đang xử lý</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shipping-fast"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['shipping']; ?></div>
                    <div class="stat-label">Đang giao</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Đã giao</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                    <div class="stat-label">Đã hủy</div>
                </div>
            </div>
            <!-- Orders List -->
            <?php if (count($orders) > 0): ?>
                <?php foreach ($orders as $order): 
                    $status = $order['status'] ?? '';
                    $info = isset($status_config[$status]) ? $status_config[$status] : [
                        'text' => $status ? ucfirst($status) : 'Không xác định',
                        'icon' => 'fa-info-circle',
                        'color' => 'secondary',
                        'bg' => '#E9ECEF',
                        'description' => 'Cập nhật trạng thái'
                    ];
                ?>
                <div class="order-card">
                    <div class="order-header-row">
                        <div>
                            <div class="order-number">
                                <i class="fas fa-receipt me-2"></i>
                                #<?php echo htmlspecialchars($order['order_number']); ?>
                            </div>
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                            </small>
                        </div>
                        <span class="status-badge" style="background: <?php echo $info['bg']; ?>; color: var(--coffee-dark);">
                            <i class="fas <?php echo $info['icon']; ?>"></i>
                            <?php echo $info['text']; ?>
                        </span>
                    </div>
                    <div class="order-info-row">
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Khách hàng</div>
                                <div class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Số điện thoại</div>
                                <div class="info-value"><?php echo htmlspecialchars($order['customer_phone']); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-box"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Sản phẩm</div>
                                <div class="info-value"><?php echo $order['item_count']; ?> món</div>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Tổng tiền</div>
                                <div class="info-value" style="color: #28a745; font-size: 1.2rem;">
                                    <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="order-actions">
                        <a href="track_order.php?order_id=<?php echo $order['id']; ?>" class="btn-premium">
                            <i class="fas fa-eye"></i>
                            Xem chi tiết
                        </a>
                        <?php if ($order['status'] === 'completed'): ?>
                        <a href="review.php?order_id=<?php echo $order['id']; ?>" class="btn-premium btn-review">
                            <i class="fas fa-star"></i>
                            Đánh giá
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <h3 style="color: var(--coffee-dark); margin-bottom: 1rem;">Chưa có đơn hàng nào</h3>
                    <p style="color: var(--coffee-light); font-size: 1.1rem; margin-bottom: 2rem;">
                        Bạn chưa có đơn hàng nào. Hãy bắt đầu mua sắm ngay!
                    </p>
                    <a href="../customer/index.php" class="btn-premium btn-review">
                        <i class="fas fa-shopping-cart me-2"></i>
                        Mua sắm ngay
                    </a>
                </div>
            <?php endif; ?>
            <?php else: ?>
            <!-- ========== HIỂN THỊ CHI TIẾT ĐƠN HÀNG ========== -->
            <div class="detail-container">
                <div class="detail-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h2 style="color: var(--coffee-dark); margin-bottom: 0.5rem;">
                                <i class="fas fa-receipt me-2"></i>
                                Chi tiết đơn hàng
                            </h2>
                            <h4 class="text-primary">#<?php echo htmlspecialchars($order_detail['order_number']); ?></h4>
                        </div>
                        <?php 
                        $status = $order_detail['status'] ?? ($order_detail['order_status'] ?? '');
                        $info = isset($status_config[$status]) ? $status_config[$status] : [
                            'text' => $status ? ucfirst($status) : 'Không xác định',
                            'icon' => 'fa-info-circle',
                            'color' => 'secondary',
                            'bg' => '#E9ECEF',
                            'description' => 'Cập nhật trạng thái'
                        ];
                        ?>
                        <span class="status-badge" style="background: <?php echo $info['bg']; ?>; color: var(--coffee-dark);">
                            <i class="fas <?php echo $info['icon']; ?>"></i>
                            <?php echo $info['text']; ?>
                        </span>
                    </div>
                </div>
                <div class="row">
                    <!-- Thông tin đơn hàng -->
                    <div class="col-lg-5">
                        <div class="order-info-box">
                            <h5>
                                <i class="fas fa-info-circle me-2"></i>
                                Thông tin đơn hàng
                            </h5>
                            
                            <div class="info-row">
                                <small>Khách hàng</small>
                                <div class="info-value-main">
                                    <?php echo htmlspecialchars($order_detail['customer_name']); ?>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <small>Số điện thoại</small>
                                <div class="info-value-main">
                                    <?php echo htmlspecialchars($order_detail['customer_phone']); ?>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <small>Địa chỉ giao hàng</small>
                                <div class="info-value-main">
                                    <?php echo htmlspecialchars($order_detail['shipping_address'] ?? '—'); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($order_detail['estimated_delivery_at'])): ?>
                            <div class="info-row">
                                <small>Thời gian dự kiến giao</small>
                                <div class="info-value-main" style="color: #007bff; font-weight: 700;">
                                    <?php echo date('d/m/Y H:i', strtotime($order_detail['estimated_delivery_at'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-row">
                                <small>Phí vận chuyển</small>
                                <div class="info-value-main">
                                    <?php if (!empty($order_detail['shipping_fee']) && (int)$order_detail['shipping_fee'] > 0): ?>
                                        <?php echo number_format($order_detail['shipping_fee'], 0, ',', '.'); ?>đ
                                    <?php else: ?>
                                        <span style="color: #28a745; font-weight: 600;">Miễn phí</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-row">
                                <small>Phương thức thanh toán</small>
                                <div class="info-value-main">
                                    <?php 
                                    $payment_methods = [
                                        'COD' => 'Tiền mặt',
                                        'BANK_TRANSFER' => 'Chuyển khoản'
                                    ];
                                    echo $payment_methods[$order_detail['payment_method']] ?? 'Tiền mặt';
                                    ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($order_detail['coupon_code'])): ?>
                            <div class="info-row" style="background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%); padding: 1rem; border-radius: 10px; margin: 1rem 0;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <small style="color: #856404; font-weight: 600;">
                                            <i class="fas fa-ticket-alt me-2"></i>Voucher áp dụng
                                        </small>
                                        <div class="info-value-main" style="color: #856404; font-size: 1.1rem; font-weight: 700; margin-top: 0.3rem;">
                                            <?php echo htmlspecialchars($order_detail['coupon_code']); ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <small style="color: #856404;">Giảm giá</small>
                                        <div style="color: #dc3545; font-size: 1.3rem; font-weight: 800; margin-top: 0.3rem;">
                                            -<?php echo number_format($order_detail['discount_amount'] ?? 0, 0, ',', '.'); ?>đ
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-row">
                                <small>Tổng tiền</small>
                                <div style="font-size: 1.8rem; font-weight: 800; color: var(--gold); margin-top: 0.3rem; font-family: 'Cormorant Garamond', serif;">
                                    <?php echo number_format($order_detail['total_amount'], 0, ',', '.'); ?>đ
                                </div>
                            </div>
                            
                            <?php if (!empty($order_detail['note'])): ?>
                            <div style="margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                <small>Ghi chú</small>
                                <div style="margin-top: 0.5rem;">
                                    <?php echo nl2br(htmlspecialchars($order_detail['note'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Danh sách sản phẩm -->
                        <h5 style="color: var(--coffee-dark); margin-bottom: 1.5rem;">
                            <i class="fas fa-shopping-bag me-2"></i>
                            Sản phẩm (<?php echo count($order_items); ?> món)
                        </h5>
                        <div class="product-list">
                            <?php foreach ($order_items as $item): ?>
                            <div class="product-item">
                                <div>
                                    <strong style="color: var(--coffee-dark);">
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </strong>
                                    <br>
                                    <small style="color: var(--coffee-light);">
                                        x<?php echo $item['quantity']; ?> - <?php echo number_format($item['price'], 0, ',', '.'); ?>đ
                                    </small>
                                </div>
                                <div class="text-end">
                                    <strong style="color: #28a745; font-size: 1.2rem;">
                                        <?php echo number_format($item['subtotal'], 0, ',', '.'); ?>đ
                                    </strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Timeline tracking -->
                    <div class="col-lg-7">
                        <h5 style="color: var(--coffee-dark); margin-bottom: 2rem;">
                            <i class="fas fa-route me-2"></i>
                            Lịch sử vận chuyển
                        </h5>
                        
                        <?php if (count($tracking_history) > 0): ?>
                        <div class="timeline-premium">
                            <?php 
                            $total_tracks = count($tracking_history);
                            foreach ($tracking_history as $index => $track): 
                                $is_current = ($index == 0);
                                $track_status = $track['status'];
                                $info = isset($status_config[$track_status]) ? $status_config[$track_status] : [
                                    'text' => $track_status,
                                    'icon' => 'fa-info-circle',
                                    'color' => 'secondary',
                                    'description' => 'Cập nhật trạng thái'
                                ];
                            ?>
                            <div class="timeline-item <?php echo $is_current ? 'active' : ''; ?>">
                                <div class="timeline-icon bg-<?php echo $info['color']; ?>">
                                    <i class="fas <?php echo $info['icon']; ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 style="color: var(--coffee-dark); margin-bottom: 0;">
                                            <i class="fas <?php echo $info['icon']; ?> me-2 text-<?php echo $info['color']; ?>"></i>
                                            <?php echo $info['text']; ?>
                                        </h6>
                                        <small style="color: var(--coffee-light);">
                                            <?php echo date('d/m/Y H:i', strtotime($track['created_at'])); ?>
                                        </small>
                                    </div>
                                    <?php if (!empty($track['note'])): ?>
                                    <p style="color: var(--coffee-medium); margin-bottom: 0;">
                                        <?php echo nl2br(htmlspecialchars($track['note'])); ?>
                                    </p>
                                    <?php else: ?>
                                    <p style="color: var(--coffee-medium); margin-bottom: 0;">
                                        <?php echo $info['description']; ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="text-align: center; padding: 3rem; background: var(--cream); border-radius: 15px;">
                            <i class="fas fa-info-circle" style="font-size: 3rem; color: var(--gold); margin-bottom: 1rem;"></i>
                            <p style="color: var(--coffee-light); margin: 0;">
                                Chưa có thông tin vận chuyển
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Alert based on status -->
                        <?php
                        $status = $order_detail['status'] ?? '';
                        $stateMessages = [
                            'pending' => [
                                'title' => 'Đơn hàng đang chờ xác nhận',
                                'text' => 'Đơn hàng của bạn đã được nhận. Vui lòng chờ chúng tôi xác nhận và tiến hành xử lý.',
                                'class' => 'warning',
                                'icon' => 'fa-clock',
                                'bg' => 'linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%)',
                                'border' => '#ffc107',
                                'color' => '#856404'
                            ],
                            'confirmed' => [
                                'title' => 'Đơn hàng đã xác nhận',
                                'text' => 'Đơn hàng đã được xác nhận và đang chuẩn bị đóng gói.',
                                'class' => 'info',
                                'icon' => 'fa-check-circle',
                                'bg' => 'linear-gradient(135deg, #cff4fc 0%, #b6effb 100%)',
                                'border' => '#0dcaf0',
                                'color' => '#055160'
                            ],
                            'preparing' => [
                                'title' => 'Đang chuẩn bị hàng',
                                'text' => 'Nhân viên đang đóng gói đơn hàng và chuẩn bị giao vận.',
                                'class' => 'primary',
                                'icon' => 'fa-box',
                                'bg' => 'linear-gradient(135deg, #cfe2ff 0%, #9ec5fe 100%)',
                                'border' => '#0d6efd',
                                'color' => '#084298'
                            ],
                            'shipping' => [
                                'title' => 'Đang giao hàng',
                                'text' => 'Đơn hàng đang trên đường giao tới bạn. Vui lòng chuẩn bị nhận hàng.',
                                'class' => 'info',
                                'icon' => 'fa-shipping-fast',
                                'bg' => 'linear-gradient(135deg, #cfe2ff 0%, #9ec5fe 100%)',
                                'border' => '#0dcaf0',
                                'color' => '#084298'
                            ],
                            'completed' => [
                                'title' => 'Đơn hàng đã hoàn thành',
                                'text' => 'Cảm ơn bạn đã mua hàng. Hãy đánh giá sản phẩm để giúp chúng tôi cải thiện dịch vụ.',
                                'class' => 'success',
                                'icon' => 'fa-check-circle',
                                'bg' => 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)',
                                'border' => '#28a745',
                                'color' => '#155724'
                            ],
                            'cancelled' => [
                                'title' => 'Đơn hàng đã bị hủy',
                                'text' => 'Đơn hàng này đã bị hủy. Nếu có thắc mắc, vui lòng liên hệ hỗ trợ.',
                                'class' => 'danger',
                                'icon' => 'fa-times-circle',
                                'bg' => 'linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%)',
                                'border' => '#dc3545',
                                'color' => '#721c24'
                            ],
                        ];
                        $statusDisplay = $stateMessages[$status] ?? [
                            'title' => 'Đang cập nhật trạng thái',
                            'text' => 'Chúng tôi đang cập nhật tình trạng đơn hàng. Vui lòng quay lại sau.',
                            'class' => 'secondary',
                            'icon' => 'fa-info-circle',
                            'bg' => 'linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%)',
                            'border' => '#6c757d',
                            'color' => '#495057'
                        ];
                        ?>
                        <div class="alert-custom" style="background: <?php echo $statusDisplay['bg']; ?>; border-left-color: <?php echo $statusDisplay['border']; ?>;">
                            <i class="fas <?php echo $statusDisplay['icon']; ?> me-2" style="color: <?php echo $statusDisplay['color']; ?>;"></i>
                            <strong style="color: <?php echo $statusDisplay['color']; ?>;"><?php echo $statusDisplay['title']; ?></strong>
                            <p style="color: <?php echo $statusDisplay['color']; ?>; margin: 0.5rem 0 0 0;">
                                <?php echo $statusDisplay['text']; ?>
                            </p>
                        </div>

                    </div>
                </div>
                
                <div class="text-center mt-4" style="padding-top: 2rem; border-top: 2px solid var(--cream-dark);">
                    <a href="track_order.php" class="btn-premium me-3">
                        <i class="fas fa-arrow-left me-2"></i>
                        Quay lại danh sách
                    </a>
                    <?php if ($order_detail['status'] === 'completed'): ?>
                    <a href="review.php?order_id=<?php echo $order_detail['id']; ?>" class="btn-premium btn-review">
                        <i class="fas fa-star me-2"></i>
                        Đánh giá sản phẩm
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn-premium btn-review">
                        <i class="fas fa-shopping-bag me-2"></i>
                        Tiếp tục mua sắm
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scroll to top when viewing order detail
        <?php if ($order_detail): ?>
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
        <?php endif; ?>
        // Auto refresh page every 60 seconds if there are pending orders
        <?php if ($stats['processing'] > 0 && !$order_detail): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>

        // Tự động ẩn alert sau 2 giây
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert.alert-dismissible');
            alerts.forEach(alertBox => {
                alertBox.classList.remove('show');
                alertBox.classList.add('hide');
                setTimeout(() => {
                    alertBox.remove();
                }, 500);
            });
        }, 2000);
    </script>
</body>
</html>