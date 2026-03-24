<?php
require_once '../config/init.php';
require_once '../config/helpers.php'; 

// Chỉ xử lý POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}

// Kiểm tra giỏ hàng và đăng nhập
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    $_SESSION['error'] = "Giỏ hàng trống!";
    header('Location: cart.php');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$cart = $_SESSION['cart'];

$selectedItems = array_filter(array_map('intval', explode(',', $_POST['selected_items'] ?? '')));
if (!empty($selectedItems)) {
    $cart = array_filter($cart, function ($item) use ($selectedItems) {
        return in_array((int)$item['id'], $selectedItems, true);
    });
    if (empty($cart)) {
        $_SESSION['error'] = 'Vui lòng chọn ít nhất 1 sản phẩm để thanh toán.';
        header('Location: cart.php');
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 1. Lấy và Validate dữ liệu
    $customer_name = sanitizeInput($_POST['customer_name'] ?? '');
    $customer_phone = sanitizeInput($_POST['customer_phone'] ?? '');
    $customer_email = sanitizeInput($_POST['customer_email'] ?? '');
    $shipping_address = sanitizeInput($_POST['shipping_address'] ?? '');
    $payment_method_code = $_POST['payment_method'] ?? 'COD';
    $note = sanitizeInput($_POST['note'] ?? '');
    $coupon_code = strtoupper(trim($_POST['applied_coupon_code'] ?? ''));

    if (empty($customer_name) || empty($customer_phone) || empty($shipping_address) || empty($customer_email)) {
        throw new Exception("Vui lòng điền đầy đủ thông tin bắt buộc!");
    } 

    // 2. Lấy thông tin Phương thức thanh toán
    $stmtPM = $pdo->prepare("SELECT id, name, type FROM payment_methods WHERE code = ? AND active = 1");
    $stmtPM->execute([$payment_method_code]);
    $paymentMethod = $stmtPM->fetch(PDO::FETCH_ASSOC);
    if (!$paymentMethod) {
        throw new Exception('Phương thức thanh toán không hợp lệ!');
    }

    // 3. Tính toán số tiền & Xử lý Mã giảm giá (Tính lại tại Server để bảo mật)
    $subtotal = 0;
    foreach ($cart as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    // Lấy shipping_fee và distance từ checkout.php (đã tính toán trên client)
    $shippingFee = floatval($_POST['client_shipping_fee'] ?? 0);
    $distance = floatval($_POST['client_distance'] ?? 0);
    
    // Validation: Nếu không có giá trị từ client, tính mặc định
    if ($shippingFee <= 0) {
        $shippingFee = 0;
    }
    if ($distance <= 0) {
        $distance = 0;
    }
    $discount_amount = 0;
    $coupon_id = null;

    if (!empty($coupon_code)) {
        $stmtCoupon = $pdo->prepare("
            SELECT cs.* FROM user_coupons uc 
            JOIN coupon_statistics cs ON uc.coupon_id = cs.id 
            WHERE uc.user_id = ? AND UPPER(cs.code) = ? 
            AND uc.is_used = 0
            AND cs.display_status COLLATE utf8mb4_unicode_ci = 'Đang hoạt động'
        ");
        $stmtCoupon->execute([$user_id, $coupon_code]);
        $coupon = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

        if ($coupon && $subtotal >= $coupon['min_order_value']) {
            $coupon_id = $coupon['id'];
            if ($coupon['discount_type'] === 'percentage') {
                $discount_amount = ($subtotal * $coupon['discount_value']) / 100;
                if (!empty($coupon['max_discount']) && $discount_amount > $coupon['max_discount']) {
                    $discount_amount = $coupon['max_discount'];
                }
            } else {
                $discount_amount = $coupon['discount_value'];
            }
        }
    }

    $total = ($subtotal + $shippingFee) - $discount_amount;
    if ($total < 0) $total = 0;

    // 4. Kiểm tra tồn kho (Trước khi tạo đơn)
    foreach ($cart as $item) {
        $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id = ?");
        $stmt->execute([$item['id']]);
        $stock = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stock || $stock['quantity'] < $item['quantity']) { 
            throw new Exception("Sản phẩm '{$item['name']}' không đủ hàng! Tồn kho hiện có: " . ($stock['quantity'] ?? 0));
        }
    }

    // 5. Tạo Đơn hàng (Bảng: orders)
    $order_number = generateOrderNumber();
    // Tất cả đơn hàng đều bắt đầu với status 'pending' - chờ admin xác nhận
    // Chỉ COD mới có thể tự động chuyển thành 'confirmed' sau khi admin review
    $status = 'pending';
    
    $stmt = $pdo->prepare("
        INSERT INTO orders 
        (order_number, user_id, customer_name, customer_email, customer_phone, shipping_address, 
        total_amount, sub_total, shipping_fee, distance, discount_amount, coupon_code, 
        payment_method, note, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $order_number, $user_id, $customer_name, $customer_email, $customer_phone, $shipping_address, 
        $total, $subtotal, $shippingFee, $distance, $discount_amount, $coupon_code,
        $payment_method_code, $note, $status
    ]);
    $order_id = $pdo->lastInsertId();

    // 6. Cập nhật trạng thái Mã giảm giá
    if ($coupon_id) {
        $updateCoupon = $pdo->prepare("UPDATE user_coupons SET is_used = 1, used_at = NOW() WHERE user_id = ? AND coupon_id = ?");
        $updateCoupon->execute([$user_id, $coupon_id]);
    }

    // 7. Thêm Chi tiết đơn hàng, Trừ kho & Ghi lịch sử kho (Kết hợp bản cũ)
    $stmt_item = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_update_stock = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ?");
    $stmt_history = $pdo->prepare("INSERT INTO inventory_history (product_id, quantity_change, type, note) VALUES (?, ?, 'out', ?)");
    
    foreach ($cart as $item) {
        $item_subtotal = $item['price'] * $item['quantity'];
        
        // Lưu chi tiết
        $stmt_item->execute([$order_id, $item['id'], $item['name'], $item['quantity'], $item['price'], $item_subtotal]);
        
        // Trừ tồn kho thực tế
        $stmt_update_stock->execute([$item['quantity'], $item['id']]);
        
        // Ghi log lịch sử kho để theo dõi (Quan trọng từ bản cũ)
        $stmt_history->execute([$item['id'], -$item['quantity'], "Đơn hàng #{$order_number}"]);
    }

    // 8. Tạo tracking record cho COD (status = pending - chờ admin xác nhận)
    if ($paymentMethod['type'] === 'cod') {
        $stmtTracking = $pdo->prepare("
            INSERT INTO order_tracking (order_id, status, note) 
            VALUES (?, 'pending', 'Đơn hàng thanh toán khi nhận hàng (COD). Chờ xác nhận từ quản lý.')
        ");
        $stmtTracking->execute([$order_id]);
    }

    // 9. Xử lý Giao dịch Thanh toán (payments) nếu không phải COD
    if ($paymentMethod['type'] !== 'cod') {
        $stmtPay = $pdo->prepare("INSERT INTO payments (order_id, payment_method_id, amount, status) VALUES (?, ?, ?, 'pending')");
        $stmtPay->execute([$order_id, $paymentMethod['id'], $total]);
    }

    // 10. Hoàn tất Transaction
    $pdo->commit();

    // 10. Lưu dữ liệu Session & Chuyển hướng
    if (!empty($selectedItems)) {
        foreach ($selectedItems as $selectedId) {
            if (isset($_SESSION['cart'][$selectedId])) {
                unset($_SESSION['cart'][$selectedId]);
            }
        }
    } else {
        $_SESSION['cart'] = []; // Xóa giỏ hàng
    }
    
    // Thông tin cho trang order_success (Kết hợp đầy đủ thông tin từ bản cũ)
    $_SESSION['order_success'] = [
        'order_number' => $order_number,
        'total' => $total,
        'customer_name' => $customer_name,
        'customer_email' => $customer_email,
        'shipping_address' => $shipping_address,
        'payment_method' => $paymentMethod['name']
    ];

    // Chuyển hướng theo loại phương thức
    if ($paymentMethod['type'] === 'qr' || $paymentMethod['type'] === 'manual') {
        header("Location: manual_payment.php?order_id={$order_id}");
    } else {
        header('Location: order_success.php');
    }
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = "Lỗi đặt hàng: " . $e->getMessage();
    
    // Lưu lại dữ liệu để khách hàng không phải nhập lại form
    $_SESSION['checkout_data'] = $_POST; 
    
    header('Location: checkout.php');
    exit;
    // ===== THÊM MỚI: Lấy và validate địa chỉ giao hàng =====
$delivery_address = trim($_POST['delivery_address'] ?? '');

// Validate phía server — KHÔNG tin dữ liệu client
if (empty($delivery_address) || mb_strlen($delivery_address) < 10) {
    $_SESSION['error_msg'] = 'Vui lòng nhập địa chỉ giao hàng đầy đủ (ít nhất 10 ký tự).';
    header('Location: checkout.php');
    exit;
}
if (mb_strlen($delivery_address) > 500) {
    $_SESSION['error_msg'] = 'Địa chỉ quá dài.';
    header('Location: checkout.php');
    exit;
}

// Sanitize
$delivery_address = htmlspecialchars(strip_tags($delivery_address), ENT_QUOTES, 'UTF-8');

// ===== THÊM MỚI: Tính khoảng cách và phí ship TRÊN SERVER =====
$distResult   = getShippingDistance($delivery_address);
$distance     = $distResult['km'];          // float, đơn vị km
$shipping_fee = calculateShippingFee($distance); // int, đơn vị VNĐ

// Ghi log nếu geocode thất bại (nhưng vẫn tiếp tục xử lý)
if (!$distResult['success']) {
    error_log("[Order] Shipping warning: " . $distResult['error']);
}

// ── Giữ nguyên code tính subtotal hiện tại ──
// Giả sử biến $subtotal đã được tính từ giỏ hàng

$grand_total = $subtotal + $shipping_fee; // Cộng thêm phí ship vào tổng

// ===== SỬA: Câu INSERT — thêm 3 cột mới =====
// Tìm câu INSERT INTO orders hiện tại trong file và sửa như sau:
$stmt = $conn->prepare(
    "INSERT INTO orders 
     (user_id, delivery_address, subtotal, shipping_fee, distance, total_price, 
      payment_method, status, note, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
);
// Điều chỉnh bind_param theo đúng cột DB của bạn:
$stmt->bind_param(
    "isiidiss",
    $_SESSION['user_id'],  // i
    $delivery_address,     // s
    $subtotal,             // i
    $shipping_fee,         // i
    $distance,             // d (float)
    $grand_total,          // i
    $payment_method,       // s
    $note                  // s
);

if (!$stmt->execute()) {
    error_log("[Order] DB insert error: " . $stmt->error);
    $_SESSION['error_msg'] = 'Lỗi hệ thống khi lưu đơn hàng, vui lòng thử lại.';
    header('Location: checkout.php');
    exit;
}

$order_id = $conn->insert_id;

// ── Giữ nguyên code lưu order_items, xóa cart... ──

// Lưu thông tin vào session để hiển thị trang thành công
$_SESSION['last_order'] = [
    'order_id'     => $order_id,
    'distance'     => $distance,
    'shipping_fee' => $shipping_fee,
    'grand_total'  => $grand_total,
    'address'      => $delivery_address,
];

header('Location: order_success.php');
exit;
}