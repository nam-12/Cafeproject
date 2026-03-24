<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

$order = $_SESSION['last_order'] ?? null;
unset($_SESSION['last_order']);
$order_info = null;
$db = getDB();

// ══════════════════════════════════════════════════════════════
// BƯỚC 1: Đến từ manual_payment.php (có ?order_id=...)
// ══════════════════════════════════════════════════════════════
if (!$order_info && isset($_GET['order_id'])) {

    $order_id = (int) $_GET['order_id'];

    $stmt = $db->prepare("
        SELECT
            o.order_number,
            o.customer_name,
            o.customer_email,
            o.shipping_address,
            o.payment_method,
            o.status           AS order_status,
            o.coupon_code,
            o.discount_amount,
            o.sub_total,
            o.shipping_fee,
            o.distance,
            o.total_amount,
            p.status           AS payment_status
        FROM orders o
        LEFT JOIN payments p ON o.id = p.order_id
        WHERE o.id = ?
        ORDER BY p.id DESC
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $order_info = [
            'order_number'    => $row['order_number'],
            'customer_name'   => $row['customer_name'],
            'customer_email'  => $row['customer_email'],
            'shipping_address'=> $row['shipping_address'],
            'payment_method'  => $row['payment_method'] ?? 'BANK_TRANSFER',
            'order_status'    => $row['order_status'],
            'payment_status'  => $row['payment_status'] ?? 'pending',
            'coupon_code'     => $row['coupon_code'] ?? null,
            'discount_amount' => (int)($row['discount_amount'] ?? 0),
            'subtotal'        => (int)($row['sub_total']       ?? 0),
            'shipping_fee'    => (int)($row['shipping_fee']    ?? 0),
            'distance'        => (float)($row['distance']      ?? 0),
            'total'           => (int)($row['total_amount']    ?? 0),
        ];
    }
}

// ══════════════════════════════════════════════════════════════
// BƯỚC 2: Đến từ create_order.php qua $_SESSION['order_success']
// ══════════════════════════════════════════════════════════════
elseif (isset($_SESSION['order_success'])) {

    $order_info = $_SESSION['order_success'];
    unset($_SESSION['order_success']);

    if (!empty($order_info['order_number'])) {

        // Luôn lấy lại từ DB để đảm bảo shipping_fee, distance chính xác
        $stmt = $db->prepare("
            SELECT
                o.payment_method,
                o.status           AS order_status,
                o.coupon_code,
                o.discount_amount,
                o.sub_total,
                o.shipping_fee,
                o.distance,
                o.total_amount,
                p.status           AS payment_status
            FROM orders o
            LEFT JOIN payments p ON o.id = p.order_id
            WHERE o.order_number = ?
            ORDER BY p.id DESC
            LIMIT 1
        ");
        $stmt->execute([$order_info['order_number']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // Ghi đè bằng dữ liệu DB (nguồn tin cậy nhất)
            $order_info['order_status']   = $result['order_status'];
            $order_info['payment_status'] = $result['payment_status'] ?? 'none';
            $order_info['coupon_code']    = $result['coupon_code']    ?? null;
            $order_info['discount_amount']= (int)($result['discount_amount'] ?? 0);
            $order_info['subtotal']       = (int)($result['sub_total']       ?? 0);
            $order_info['shipping_fee']   = (int)($result['shipping_fee']    ?? 0);
            $order_info['distance']       = (float)($result['distance']      ?? 0);
            $order_info['total']          = (int)($result['total_amount']    ?? $order_info['total'] ?? 0);

            if (!empty($result['payment_method'])) {
                $order_info['payment_method'] = $result['payment_method'];
            }
        }
    }

    // Giá trị mặc định nếu DB không trả về
    $order_info['order_status']   = $order_info['order_status']   ?? 'pending';
    $order_info['payment_status'] = $order_info['payment_status'] ?? 'none';
    $order_info['subtotal']       = $order_info['subtotal']       ?? 0;
    $order_info['shipping_fee']   = $order_info['shipping_fee']   ?? 0;
    $order_info['distance']       = $order_info['distance']       ?? 0;
    $order_info['discount_amount']= $order_info['discount_amount']?? 0;
}

// ══════════════════════════════════════════════════════════════
// BƯỚC 3: Không có dữ liệu → về trang chủ
// ══════════════════════════════════════════════════════════════
if (!$order_info) {
    header('Location: index.php');
    exit;
}

// ══════════════════════════════════════════════════════════════
// BƯỚC 4: Xác định trạng thái hiển thị
// ══════════════════════════════════════════════════════════════
$current_status = $order_info['order_status'];
$payment_status = $order_info['payment_status'];
$payment_method = $order_info['payment_method'];

// Kiểm tra lại tổng (đề phòng session cũ sai)
$expected_total = $order_info['subtotal'] + $order_info['shipping_fee'] - $order_info['discount_amount'];
if ($order_info['total'] <= 0 && $expected_total > 0) {
    $order_info['total'] = $expected_total;
}

$alert_message = "";

if (in_array($current_status, ['cancelled', 'failed'])) {
    $title_text         = "Đặt hàng không thành công!";
    $status_badge_class = "bg-danger";
    $status_badge_text  = ($current_status === 'cancelled') ? 'Đã hủy' : 'Thất bại';
    $icon_class         = "fas fa-times";
    $icon_bg            = "#dc3545";
    $alert_class        = "alert-danger";
    $alert_message      = ($current_status === 'cancelled')
        ? "Đơn hàng của bạn đã bị hủy."
        : "Thanh toán thất bại. Vui lòng thử lại.";
} else {
    $title_text  = "Đặt hàng thành công!";
    $icon_class  = "fas fa-check";
    $icon_bg     = "#28a745";
    $alert_class = "alert-info";

    if ($payment_method === 'BANK_TRANSFER' && $payment_status === 'success') {
        $status_badge_class = "bg-success";
        $status_badge_text  = "Đã thanh toán & Chờ xác nhận";
        $alert_message      = "Đơn hàng đã được thanh toán thành công. Chúng tôi sẽ gửi email xác nhận.";
    } elseif ($current_status === 'confirmed') {
        $status_badge_class = "bg-success";
        $status_badge_text  = "Đã xác nhận";
        $alert_message      = "Đơn hàng đã được xác nhận và đang được chuẩn bị.";
    } else {
        $status_badge_class = "bg-warning";
        $status_badge_text  = "Chờ xác nhận";
        $alert_message      = "Chúng tôi sẽ liên hệ với bạn để xác nhận đơn hàng.";
    }
}

function getTimelineStatus($status) {
    if (in_array($status, ['cancelled', 'failed']))           return 'cancelled';
    if (in_array($status, ['confirmed', 'shipping', 'completed'])) return 'active';
    return 'pending';
}
$timeline_status = getTimelineStatus($current_status);

// ══════════════════════════════════════════════════════════════
// GỬI EMAIL XÁC NHẬN (chỉ 1 lần)
// ══════════════════════════════════════════════════════════════
require_once '../config/send_mail.php';
if (!empty($order_info['order_number'])) {
    $stmt = $db->prepare("SELECT email_sent FROM orders WHERE order_number = ?");
    $stmt->execute([$order_info['order_number']]);
    $check = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($check
        && $check['email_sent'] == 0
        && !in_array($current_status, ['cancelled', 'failed'])
        && $payment_status !== 'failed'
    ) {
        $paymentText = ($payment_method === 'BANK_TRANSFER')
            ? 'Chuyển khoản ngân hàng'
            : 'Thanh toán khi nhận hàng (COD)';

        $sent = sendOrderEmail(
            $order_info['customer_email'],
            $order_info['customer_name'],
            $order_info['order_number'],
            $order_info['total'],
            $paymentText,
            $order_info['shipping_address']
        );
        if ($sent) {
            $stmt = $db->prepare("UPDATE orders SET email_sent = 1 WHERE order_number = ?");
            $stmt->execute([$order_info['order_number']]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title_text) ?> - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/order_success.css">
</head>
<body>
<div class="container">
    <div class="success-container">

        <!-- Icon trạng thái -->
        <div class="success-icon" style="background-color:<?= htmlspecialchars($icon_bg) ?>">
            <i class="<?= htmlspecialchars($icon_class) ?>"></i>
        </div>

        <h1 class="success-title"><?= htmlspecialchars($title_text) ?></h1>

        <p class="text-muted mb-4">
            Cảm ơn bạn <strong><?= htmlspecialchars($order_info['customer_name']) ?></strong>
            ghé thăm tại Coffee House
        </p>

        <div class="order-number">
            <div class="text-uppercase mb-2" style="opacity:.8;">Mã đơn hàng của bạn</div>
            <h3><?= htmlspecialchars($order_info['order_number']) ?></h3>
        </div>

        <!-- ── Chi tiết đơn hàng ── -->
        <div class="info-box">

            <div class="info-row">
                <span class="text-muted">Phương thức thanh toán:</span>
                <strong>
                    <?= ($payment_method === 'BANK_TRANSFER')
                        ? 'Chuyển khoản Ngân hàng'
                        : 'Thanh toán khi nhận hàng (COD)' ?>
                </strong>
            </div>

            <div class="info-row">
                <span class="text-muted">Địa chỉ giao hàng:</span>
                <strong><?= htmlspecialchars($order_info['shipping_address'] ?? 'N/A') ?></strong>
            </div>

            <?php if (!empty($order_info['distance']) && $order_info['distance'] > 0): ?>
            <div class="info-row">
                <span class="text-muted">Khoảng cách:</span>
                <strong><?= number_format($order_info['distance'], 2) ?> km</strong>
            </div>
            <?php endif; ?>

            <!-- Tạm tính -->
            <div class="info-row">
                <span class="text-muted">Tạm tính (sản phẩm):</span>
                <strong><?= formatCurrency($order_info['subtotal']) ?></strong>
            </div>

            <!-- Phí vận chuyển — đây là giá trị từ DB, luôn chính xác -->
            <div class="info-row">
                <span class="text-muted">Phí vận chuyển:</span>
                <strong <?= $order_info['shipping_fee'] > 0 ? 'style="color:#a2836e;"' : '' ?>>
                    <?php if ($order_info['shipping_fee'] > 0): ?>
                        <?= formatCurrency($order_info['shipping_fee']) ?>
                        <?php if ($order_info['distance'] > 0): ?>
                            <small class="text-muted fw-normal">(<?= number_format($order_info['distance'], 1) ?> km)</small>
                        <?php endif; ?>
                    <?php else: ?>
                        Miễn phí
                    <?php endif; ?>
                </strong>
            </div>

            <!-- Giảm giá coupon -->
            <?php if (!empty($order_info['coupon_code'])): ?>
            <div class="info-row">
                <span class="text-muted">Giảm giá (voucher):</span>
                <strong class="text-danger">
                    <i class="fas fa-ticket-alt me-1"></i>
                    <?= htmlspecialchars($order_info['coupon_code']) ?>
                    (-<?= formatCurrency($order_info['discount_amount']) ?>)
                </strong>
            </div>
            <?php endif; ?>

            <!-- Tổng cộng -->
            <div class="info-row" style="border-top:2px solid #dee2e6;padding-top:12px;margin-top:12px;">
                <span class="text-muted" style="font-weight:600;">Tổng giá trị đơn hàng:</span>
                <strong class="text-success" style="font-size:1.3rem;">
                    <?= formatCurrency($order_info['total']) ?>
                </strong>
            </div>

            <div class="info-row">
                <span class="text-muted">Trạng thái:</span>
                <span class="badge <?= htmlspecialchars($status_badge_class) ?>">
                    <?= htmlspecialchars($status_badge_text) ?>
                </span>
            </div>

        </div><!-- /info-box -->

        <!-- ── Timeline ── -->
        <?php if ($timeline_status !== 'cancelled'): ?>
        <div class="timeline">
            <h6 class="mb-3"><i class="fas fa-clock me-2"></i>Quy trình xử lý đơn hàng</h6>

            <div class="timeline-item">
                <div class="timeline-icon"><i class="fas fa-check"></i></div>
                <div class="timeline-content text-start">
                    <strong>Đơn hàng đã được tiếp nhận</strong><br>
                    <small class="text-muted">Chúng tôi đã nhận được đơn hàng của bạn</small>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-icon"
                     style="background:<?= in_array($current_status, ['confirmed','shipping','completed']) ? '#337ab7' : '#dee2e6' ?>;">
                    <i class="fas fa-box"></i>
                </div>
                <div class="timeline-content text-start">
                    <strong>Đang chuẩn bị</strong><br>
                    <small class="text-muted">Đơn hàng đang được chuẩn bị</small>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-icon"
                     style="background:<?= in_array($current_status, ['shipping','completed']) ? '#ffc107' : '#dee2e6' ?>;">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <div class="timeline-content text-start">
                    <strong>Đang giao hàng</strong><br>
                    <small class="text-muted">Shipper đang giao hàng</small>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-icon"
                     style="background:<?= ($current_status === 'completed') ? '#28a745' : '#dee2e6' ?>;">
                    <i class="fas fa-home"></i>
                </div>
                <div class="timeline-content text-start">
                    <strong>Đã giao hàng</strong><br>
                    <small class="text-muted">Giao thành công</small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="alert <?= htmlspecialchars($alert_class) ?> mb-4">
            <i class="fas fa-info-circle me-2"></i><?= $alert_message ?>
        </div>

        <div class="d-flex justify-content-center flex-wrap">
            <a href="index.php" class="btn btn-primary-custom btn-action">
                <i class="fas fa-home me-2"></i>Về trang chủ
            </a>
        </div>

        <div class="mt-4 pt-4 border-top">
            <p class="text-muted mb-2">
                <i class="fas fa-headset me-2"></i>Cần hỗ trợ? Hotline: <strong>1900-xxxx</strong>
            </p>
            <p class="text-muted small mb-0">
                <i class="fas fa-envelope me-2"></i>Email: junghior925@gmail.com
            </p>
        </div>

    </div><!-- /success-container -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/order_success.js"></script>
</body>
</html>