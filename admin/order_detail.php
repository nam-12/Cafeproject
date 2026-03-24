<?php
require_once '../config/init.php';
require_once '../config/helpers.php';
if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    exit('Unauthorized');
}

$order_id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    exit('Không tìm thấy đơn hàng');
}


$items_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

$status_text = [
    'pending' => 'Chờ xử lý',
    'confirmed' => 'Xác nhận',
    'preparing' => 'Chuẩn bị',
    'shipping' => 'Vận chuyển',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy'
];

$status_badge = [
    'pending' => 'warning',
    'confirmed' => 'info',
    'preparing' => 'primary',
    'shipping' => 'secondary',
    'completed' => 'success',
    'cancelled' => 'danger'
];

$payment_text = [
    'cash' => 'Tiền mặt',
    'card' => 'Thẻ',
    'transfer' => 'Chuyển khoản'
];
?>

<div class="row">
    <div class="col-md-6">
        <h5 class="mb-3">Thông tin đơn hàng</h5>
        <table class="table table-borderless">
            <tr>
                <td class="text-muted" style="width: 140px;">Mã đơn:</td>
                <td><?php echo $order['order_number']; ?></td>
            </tr>
            <tr>
                <td class="text-muted">Khách hàng:</td>
                <td><?php echo htmlspecialchars($order['customer_name'] ?: 'Khách lẻ'); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Số điện thoại:</td>
                <td><?php echo htmlspecialchars($order['customer_phone'] ?: '-'); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Địa chỉ:</td>
                <td>
                    <?php if (!empty($order['shipping_address'])): ?>
                        <?php echo htmlspecialchars($order['shipping_address']); ?>
                    <?php else: ?>
                        <span class="text-muted">Không có địa chỉ</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="text-muted">Thanh toán:</td>
                <td><?php echo htmlspecialchars($order['payment_method']); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Trạng thái:</td>
                <td>
                    <span <?php echo $status_badge[$order['status']]; ?>>
                        <?php echo $status_text[$order['status']]; ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td class="text-muted">Thời gian tạo:</td>
                <td><?php echo formatDate($order['created_at']); ?></td>
            </tr>
            <?php if ($order['completed_at']): ?>
            <tr>
                <td class="text-muted">Hoàn thành:</td>
                <td><?php echo formatDate($order['completed_at']); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    
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
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['price']); ?></td>
                        <td class="text-end"><strong><?php echo formatCurrency($item['subtotal']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">Tổng cộng:</th>
                        <th class="text-end text-success">
                            <h5 class="mb-0"><?php echo formatCurrency($order['total_amount']); ?></h5>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>