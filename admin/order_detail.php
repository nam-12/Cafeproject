<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    exit('Unauthorized');
}

$order_id = (int) $_GET['id'];

// Lấy thông tin đơn hàng — bao gồm cả GPS fields mới
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

// Lấy dữ liệu từ DB
$db_shipping_fee  = (int)($order['shipping_fee']  ?? 0);
$db_distance      = (float)($order['distance']    ?? 0);
$db_subtotal      = (int)($order['sub_total']     ?? $order['subtotal'] ?? 0);
$db_total         = (int)($order['total_amount']  ?? $order['total']    ?? 0);
$db_discount      = (int)($order['discount_amount']?? 0);
$db_coupon        = $order['coupon_code'] ?? null;
$db_shipping_addr = $order['shipping_address'] ?? $order['delivery_address'] ?? null;

// GPS data
$customer_lat = (float)($order['customer_lat'] ?? 0);
$customer_lng = (float)($order['customer_lng'] ?? 0);
$distance_method = $order['distance_method'] ?? null;
$has_gps = ($customer_lat > 0 && $customer_lng > 0);

// Store coordinates
$store_lat = defined('STORE_LAT') ? (float)STORE_LAT : 21.047051;
$store_lng = defined('STORE_LNG') ? (float)STORE_LNG : 105.762203;

// Ước lượng thời gian giao hàng (phút)
// Tốc độ trung bình xe máy trong thành phố: ~25km/h
// + 10 phút chuẩn bị
$estimated_minutes = 0;
if ($db_distance > 0) {
    $travel_time = ceil(($db_distance / 25) * 60); // phút di chuyển
    $prep_time = 10; // phút chuẩn bị
    $estimated_minutes = $travel_time + $prep_time;
}

// Thời gian dự kiến giao
$estimated_delivery = $order['estimated_delivery_at'] ?? null;
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

            <!-- Khoảng cách + Provider -->
            <tr>
                <td class="text-muted">Khoảng cách:</td>
                <td>
                    <?php if ($db_distance > 0): ?>
                        <span class="badge bg-light text-dark border">
                            <i class="fas fa-route me-1" style="color:#a2836e;"></i>
                            <?= number_format($db_distance, 2) ?> km
                        </span>
                        <?php if ($distance_method): ?>
                            <small class="text-muted ms-1">
                                (<?= htmlspecialchars($distance_method) ?>)
                            </small>
                        <?php endif; ?>
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

            <!-- Ước lượng thời gian giao hàng -->
            <tr>
                <td class="text-muted">Thời gian giao (ước lượng):</td>
                <td>
                    <?php if ($estimated_minutes > 0): ?>
                        <span class="badge bg-info text-white">
                            <i class="fas fa-clock me-1"></i>
                            ~<?= $estimated_minutes ?> phút
                        </span>
                        <small class="text-muted d-block mt-1">
                            <i class="fas fa-info-circle me-1"></i>
                            Chuẩn bị ~10 phút + Di chuyển ~<?= ceil(($db_distance / 25) * 60) ?> phút (<?= number_format($db_distance, 1) ?>km × 25km/h)
                        </small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Thời gian dự kiến giao (admin set) -->
            <?php if ($estimated_delivery): ?>
            <tr>
                <td class="text-muted">Dự kiến giao lúc:</td>
                <td>
                    <span class="badge bg-primary">
                        <i class="fas fa-truck me-1"></i>
                        <?= formatDate($estimated_delivery) ?>
                    </span>
                </td>
            </tr>
            <?php endif; ?>


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

        <!-- Mini Map (nếu có GPS) -->
        <?php if ($has_gps): ?>
        <div class="mt-2 mb-3">
            <h6 class="mb-2"><i class="fas fa-map-marked-alt me-1" style="color:#6f4e37;"></i> Vị trí giao hàng</h6>
            <div id="admin-order-map-<?= $order_id ?>" 
                 style="height:200px; border-radius:10px; border:2px solid #e8ddd4; overflow:hidden;"></div>
            <script>
            (function() {
                // Chỉ init map nếu Leaflet đã load
                if (typeof L === 'undefined') {
                    // Load Leaflet dynamically
                    if (!document.getElementById('leaflet-css-admin')) {
                        var css = document.createElement('link');
                        css.id = 'leaflet-css-admin';
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        document.head.appendChild(css);
                    }
                    if (!document.getElementById('leaflet-js-admin')) {
                        var js = document.createElement('script');
                        js.id = 'leaflet-js-admin';
                        js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        js.onload = function() { initOrderMap(); };
                        document.body.appendChild(js);
                    }
                } else {
                    setTimeout(initOrderMap, 100);
                }

                function initOrderMap() {
                    var mapEl = document.getElementById('admin-order-map-<?= $order_id ?>');
                    if (!mapEl || typeof L === 'undefined') return;

                    var map = L.map(mapEl).setView([<?= $customer_lat ?>, <?= $customer_lng ?>], 14);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OSM', maxZoom: 19
                    }).addTo(map);

                    // Store marker
                    var storeIcon = L.divIcon({
                        html: '<div style="width:28px;height:28px;background:linear-gradient(135deg,#6f4e37,#8D6E63);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)"><i class="fas fa-store"></i></div>',
                        className: '', iconSize: [28,28], iconAnchor: [14,28]
                    });
                    L.marker([<?= $store_lat ?>, <?= $store_lng ?>], {icon: storeIcon})
                        .addTo(map).bindPopup('<b>☕ Cửa hàng</b>');

                    // Customer marker
                    var custIcon = L.divIcon({
                        html: '<div style="width:28px;height:28px;background:linear-gradient(135deg,#e53935,#ef5350);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)"><i class="fas fa-map-marker-alt"></i></div>',
                        className: '', iconSize: [28,28], iconAnchor: [14,28]
                    });
                    L.marker([<?= $customer_lat ?>, <?= $customer_lng ?>], {icon: custIcon})
                        .addTo(map).bindPopup('<b>📍 Khách hàng</b>');

                    // Route line
                    L.polyline([
                        [<?= $store_lat ?>, <?= $store_lng ?>],
                        [<?= $customer_lat ?>, <?= $customer_lng ?>]
                    ], {color: '#6f4e37', weight: 3, dashArray: '8,4', opacity: 0.7}).addTo(map);

                    // Fit bounds
                    map.fitBounds([
                        [<?= $store_lat ?>, <?= $store_lng ?>],
                        [<?= $customer_lat ?>, <?= $customer_lng ?>]
                    ], {padding: [30, 30]});

                    setTimeout(function(){ map.invalidateSize(); }, 300);
                }
            })();
            </script>
        </div>
        <?php endif; ?>
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