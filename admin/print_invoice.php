<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    die('Unauthorized');
}

$order_id = (int) $_GET['id'];

// Lấy thông tin đơn hàng
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Không tìm thấy đơn hàng');
}

// Lấy chi tiết sản phẩm
$items_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Dữ liệu
$db_shipping_fee = (int)($order['shipping_fee'] ?? 0);
$db_distance     = (float)($order['distance'] ?? 0);
$db_subtotal     = (int)($order['sub_total'] ?? $order['subtotal'] ?? 0);
$db_total        = (int)($order['total_amount'] ?? $order['total'] ?? 0);
$db_discount     = (int)($order['discount_amount'] ?? 0);
$db_coupon       = $order['coupon_code'] ?? null;
$db_addr         = $order['shipping_address'] ?? '';

$store_name    = defined('STORE_NAME') ? STORE_NAME : 'Coffee House';
$store_address = defined('STORE_ADDRESS') ? STORE_ADDRESS : '';
$store_phone   = '0123.456.789'; // Có thể thêm vào config.php

// Ước lượng thời gian giao
$est_min = $db_distance > 0 ? (ceil(($db_distance / 25) * 60) + 10) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hóa đơn #<?= htmlspecialchars($order['order_number']) ?> - <?= $store_name ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/cssad/print_invoice.css">
</head>
<body>

    <!-- Nút in / quay lại -->
    <div class="print-controls">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print me-1"></i> In hóa đơn
        </button>
        <button class="btn-back" onclick="window.close(); return false;">
            <i class="fas fa-arrow-left me-1"></i> Đóng
        </button>
    </div>

    <div class="invoice">

        <!-- ═══ Header ═══ -->
        <div class="invoice-header">
            <div class="store-name"><i class="fas fa-coffee"></i><?= htmlspecialchars($store_name) ?></div>
            <?php if ($store_address): ?>
                <div class="store-info"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($store_address) ?></div>
            <?php endif; ?>
            <div class="store-info"><i class="fas fa-phone me-1"></i><?= $store_phone ?></div>
            <div class="invoice-title">HÓA ĐƠN BÁN HÀNG</div>
        </div>

        <!-- ═══ Order Meta ═══ -->
        <div class="order-meta">
            <div class="row">
                <span class="label">Mã đơn:</span>
                <span class="value"><?= htmlspecialchars($order['order_number']) ?></span>
            </div>
            <div class="row">
                <span class="label">Ngày:</span>
                <span class="value"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
            </div>
            <?php if (!empty($order['customer_name'])): ?>
            <div class="row">
                <span class="label">Khách hàng:</span>
                <span class="value"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['customer_phone'])): ?>
            <div class="row">
                <span class="label">SĐT:</span>
                <span class="value"><?= htmlspecialchars($order['customer_phone']) ?></span>
            </div>
            <?php endif; ?>
            <div class="row">
                <span class="label">Thanh toán:</span>
                <span class="value"><?= htmlspecialchars($order['payment_method'] ?? 'Tiền mặt') ?></span>
            </div>
        </div>

        <!-- ═══ Items ═══ -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Sản phẩm</th>
                    <th>SL</th>
                    <th>Đơn giá</th>
                    <th>T.Tiền</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= (int)$item['quantity'] ?></td>
                    <td><?= formatCurrency($item['price']) ?></td>
                    <td><strong><?= formatCurrency($item['subtotal']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ═══ Totals ═══ -->
        <div class="totals">
            <?php if ($db_subtotal > 0): ?>
            <div class="row">
                <span>Tạm tính:</span>
                <span><?= formatCurrency($db_subtotal) ?></span>
            </div>
            <?php endif; ?>

            <div class="row shipping">
                <span>Phí giao hàng <?= $db_distance > 0 ? '(' . number_format($db_distance, 1) . 'km)' : '' ?>:</span>
                <span><?= $db_shipping_fee > 0 ? formatCurrency($db_shipping_fee) : 'Miễn phí' ?></span>
            </div>

            <?php if ($db_discount > 0): ?>
            <div class="row discount">
                <span>Giảm giá <?= $db_coupon ? '(' . htmlspecialchars($db_coupon) . ')' : '' ?>:</span>
                <span>-<?= formatCurrency($db_discount) ?></span>
            </div>
            <?php endif; ?>

            <div class="row grand">
                <span>TỔNG CỘNG:</span>
                <span><?= formatCurrency($db_total) ?></span>
            </div>
        </div>


        <!-- ═══ Footer ═══ -->
        <div class="invoice-footer">
            <div class="thanks">Cảm ơn quý khách! ☕</div>
            <div>Hẹn gặp lại lần sau</div>
            <div style="margin-top:8px; font-size:0.7rem; color:#bbb;">
                In lúc: <?= date('d/m/Y H:i:s') ?>
            </div>
        </div>

    </div>

    <script>
        // Tự động mở hộp thoại in khi trang load
        window.addEventListener('load', function() {
            // Delay nhỏ để đảm bảo render xong
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
