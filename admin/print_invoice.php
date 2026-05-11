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
    <style>
        /* ── Reset & Base ────────────────────────────── */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #2D1B00;
            background: #f5f0eb;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Invoice Container ───────────────────────── */
        .invoice {
            max-width: 380px;
            margin: 20px auto;
            background: #fff;
            padding: 24px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(45, 27, 0, 0.1);
            position: relative;
        }

        /* Decorative top border */
        .invoice::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #6f4e37, #D4AF37, #6f4e37);
            border-radius: 12px 12px 0 0;
        }

        /* ── Header ──────────────────────────────────── */
        .invoice-header {
            text-align: center;
            padding-bottom: 16px;
            border-bottom: 2px dashed #e0d5c9;
            margin-bottom: 14px;
        }
        .store-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: #3E2723;
            letter-spacing: 0.03em;
        }
        .store-name i { color: #6f4e37; margin-right: 6px; }
        .store-info {
            font-size: 0.78rem;
            color: #8D6E63;
            margin-top: 4px;
        }
        .invoice-title {
            font-size: 1rem;
            font-weight: 700;
            color: #6f4e37;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        /* ── Order Meta ──────────────────────────────── */
        .order-meta {
            font-size: 0.82rem;
            color: #5D4037;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f0ebe5;
        }
        .order-meta .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
        }
        .order-meta .label { color: #8D6E63; }
        .order-meta .value { font-weight: 600; text-align: right; }

        /* ── Items Table ─────────────────────────────── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 0.85rem;
        }
        .items-table th {
            background: #fdf8f4;
            color: #5D4037;
            font-weight: 700;
            padding: 8px 6px;
            text-align: left;
            border-bottom: 2px solid #e8ddd4;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .items-table th:nth-child(2),
        .items-table td:nth-child(2) { text-align: center; }
        .items-table th:nth-child(3),
        .items-table td:nth-child(3),
        .items-table th:nth-child(4),
        .items-table td:nth-child(4) { text-align: right; }
        .items-table td {
            padding: 7px 6px;
            border-bottom: 1px solid #f5f0eb;
        }
        .items-table tbody tr:last-child td { border-bottom: none; }

        /* ── Totals ──────────────────────────────────── */
        .totals {
            border-top: 2px dashed #e0d5c9;
            padding-top: 10px;
            margin-bottom: 14px;
        }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 0.85rem;
        }
        .totals .row.shipping { color: #6f4e37; }
        .totals .row.discount { color: #e53935; }
        .totals .row.grand {
            border-top: 2px solid #3E2723;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 1.1rem;
            font-weight: 800;
            color: #2D1B00;
        }

        /* ── Delivery Info ───────────────────────────── */
        .delivery-info {
            background: #fdf8f4;
            border: 1px solid #e8ddd4;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.8rem;
            margin-bottom: 14px;
            color: #5D4037;
        }
        .delivery-info .title {
            font-weight: 700;
            color: #3E2723;
            margin-bottom: 4px;
            font-size: 0.82rem;
        }
        .delivery-info .title i { color: #6f4e37; margin-right: 4px; }

        /* ── Footer ──────────────────────────────────── */
        .invoice-footer {
            text-align: center;
            border-top: 2px dashed #e0d5c9;
            padding-top: 14px;
            color: #8D6E63;
            font-size: 0.78rem;
        }
        .invoice-footer .thanks {
            font-size: 0.9rem;
            font-weight: 700;
            color: #6f4e37;
            margin-bottom: 4px;
        }

        /* ── Print Controls ──────────────────────────── */
        .print-controls {
            text-align: center;
            margin: 16px auto;
            max-width: 380px;
        }
        .print-controls button {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            margin: 0 4px;
            transition: all 0.2s ease;
        }
        .btn-print {
            background: linear-gradient(135deg, #6f4e37, #8D6E63);
            color: #fff;
            box-shadow: 0 3px 10px rgba(111,78,55,0.25);
        }
        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(111,78,55,0.35); }
        .btn-back {
            background: #f0ebe5;
            color: #5D4037;
        }
        .btn-back:hover { background: #e8ddd4; }

        /* ── Print Styles ────────────────────────────── */
        @media print {
            body { background: #fff; }
            .invoice {
                max-width: 100%;
                margin: 0;
                padding: 10px;
                box-shadow: none;
                border-radius: 0;
            }
            .invoice::before { display: none; }
            .print-controls { display: none !important; }
            @page {
                size: 80mm auto;
                margin: 2mm;
            }
        }
    </style>
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
