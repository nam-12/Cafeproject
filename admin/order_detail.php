<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    exit('Unauthorized');
}

$order_id = (int) $_GET['id'];

// Lấy thông tin đơn hàng — bao gồm cả shipping_fee, distance, shipping_address
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    exit('Không tìm thấy đơn hàng');
}

// Lấy chi tiết sản phẩm
$items_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Nhãn trạng thái đơn hàng
$status_text = [
    'pending'   => 'Chờ xử lý',
    'confirmed' => 'Xác nhận',
    'preparing' => 'Chuẩn bị',
    'shipping'  => 'Vận chuyển',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy',
];
$status_badge = [
    'pending'   => 'warning',
    'confirmed' => 'info',
    'preparing' => 'primary',
    'shipping'  => 'secondary',
    'completed' => 'success',
    'cancelled' => 'danger',
];

// Lấy phí ship, distance, subtotal từ DB (ưu tiên cột chuẩn)
// Cột trong DB: shipping_fee, distance, sub_total (hoặc subtotal)
$db_shipping_fee  = (int)($order['shipping_fee']  ?? 0);
$db_distance      = (float)($order['distance']    ?? 0);
$db_subtotal      = (int)($order['sub_total']     ?? $order['subtotal'] ?? 0);
$db_total         = (int)($order['total_amount']  ?? $order['total']    ?? 0);
$db_discount      = (int)($order['discount_amount']?? 0);
$db_coupon        = $order['coupon_code'] ?? null;

// Địa chỉ giao hàng — cột thật trong DB là shipping_address
$db_shipping_addr = $order['shipping_address'] ?? $order['delivery_address'] ?? null;
?>

<div class="row">

    <!-- ══ Thông tin đơn hàng ══ -->
    <div class="col-md-6">
        <h5 class="mb-3">Thông tin đơn hàng</h5>
        <table class="table table-borderless">
            <tr>
                <td class="text-muted" style="width:160px;">Mã đơn:</td>
                <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Khách hàng:</td>
                <td><?= htmlspecialchars($order['customer_name'] ?: 'Khách lẻ') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Số điện thoại:</td>
                <td><?= htmlspecialchars($order['customer_phone'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Email:</td>
                <td><?= htmlspecialchars($order['customer_email'] ?? '-') ?></td>
            </tr>

            <!-- Địa chỉ giao hàng -->
            <tr>
                <td class="text-muted">Địa chỉ giao:</td>
                <td>
                    <?php if ($db_shipping_addr): ?>
                        <?= htmlspecialchars($db_shipping_addr) ?>
                    <?php else: ?>
                        <span class="text-muted fst-italic">Chưa có</span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Khoảng cách -->
            <tr>
                <td class="text-muted">Khoảng cách:</td>
                <td>
                    <?php if ($db_distance > 0): ?>
                        <span class="badge bg-light text-dark border">
                            <i class="fas fa-route me-1" style="color:#a2836e;"></i>
                            <?= number_format($db_distance, 2) ?> km
                        </span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Phí vận chuyển -->
            <tr>
                <td class="text-muted">Phí vận chuyển:</td>
                <td>
                    <?php if ($db_shipping_fee > 0): ?>
                        <strong style="color:#a2836e;">
                            <?= formatCurrency($db_shipping_fee) ?>
                        </strong>
                    <?php else: ?>
                        <span class="badge bg-success">Miễn phí</span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Coupon -->
            <?php if ($db_coupon): ?>
            <tr>
                <td class="text-muted">Mã giảm giá:</td>
                <td>
                    <span class="badge bg-warning text-dark">
                        <i class="fas fa-ticket-alt me-1"></i>
                        <?= htmlspecialchars($db_coupon) ?>
                    </span>
                    <span class="text-danger ms-1">
                        -<?= formatCurrency($db_discount) ?>
                    </span>
                </td>
            </tr>
            <?php endif; ?>

            <tr>
                <td class="text-muted">Thanh toán:</td>
                <td><?= htmlspecialchars($order['payment_method'] ?? '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Trạng thái:</td>
                <td>
                    <span class="badge bg-<?= $status_badge[$order['status']] ?? 'secondary' ?>">
                        <?= $status_text[$order['status']] ?? $order['status'] ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td class="text-muted">Thời gian tạo:</td>
                <td><?= formatDate($order['created_at']) ?></td>
            </tr>
            <?php if (!empty($order['completed_at'])): ?>
            <tr>
                <td class="text-muted">Hoàn thành:</td>
                <td><?= formatDate($order['completed_at']) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- ══ Chi tiết sản phẩm + Tổng tiền ══ -->
    <div class="col-md-6">
        <h5 class="mb-3">Chi tiết sản phẩm</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Sản phẩm</th>
                        <th class="text-center">SL</th>
                        <th class="text-end">Đơn giá</th>
                        <th class="text-end">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="text-center"><?= (int)$item['quantity'] ?></td>
                        <td class="text-end"><?= formatCurrency($item['price']) ?></td>
                        <td class="text-end"><strong><?= formatCurrency($item['subtotal']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <!-- Tạm tính -->
                    <?php if ($db_subtotal > 0): ?>
                    <tr class="table-light">
                        <td colspan="3" class="text-end text-muted">Tạm tính:</td>
                        <td class="text-end"><?= formatCurrency($db_subtotal) ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Phí vận chuyển -->
                    <tr class="table-light">
                        <td colspan="3" class="text-end text-muted">
                            Phí vận chuyển
                            <?php if ($db_distance > 0): ?>
                                <small class="text-muted">(<?= number_format($db_distance, 1) ?> km)</small>
                            <?php endif; ?>:
                        </td>
                        <td class="text-end" style="color:#a2836e; font-weight:600;">
                            <?= $db_shipping_fee > 0 ? formatCurrency($db_shipping_fee) : 'Miễn phí' ?>
                        </td>
                    </tr>

                    <!-- Giảm giá -->
                    <?php if ($db_discount > 0): ?>
                    <tr class="table-light">
                        <td colspan="3" class="text-end text-muted">
                            Giảm giá
                            <?php if ($db_coupon): ?>
                                <small>(<?= htmlspecialchars($db_coupon) ?>)</small>
                            <?php endif; ?>:
                        </td>
                        <td class="text-end text-danger">-<?= formatCurrency($db_discount) ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Tổng cộng -->
                    <tr class="table-light fw-bold">
                        <td colspan="3" class="text-end">Tổng cộng:</td>
                        <td class="text-end text-success">
                            <h5 class="mb-0"><?= formatCurrency($db_total) ?></h5>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>