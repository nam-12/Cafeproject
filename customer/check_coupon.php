<?php
require_once '../config/init.php';
header('Content-Type: application/json');

// 1. Kiểm tra đăng nhập
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập để sử dụng mã']);
    exit;
}

// 2. Lấy dữ liệu từ Request
$data = json_decode(file_get_contents('php://input'), true);
$code = strtoupper(trim($data['code'] ?? ''));
$subtotal = (float)($data['subtotal'] ?? 0);

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập mã giảm giá']);
    exit;
}

try {
    // 3. Truy van kiem tra ma
    $stmt = $pdo->prepare("
        SELECT cs.* FROM user_coupons uc 
        JOIN coupon_statistics cs ON uc.coupon_id = cs.id 
        WHERE uc.user_id = ? 
        AND UPPER(cs.code) = ? 
        AND uc.is_used = 0 
        AND cs.status = 'active'
        AND NOW() BETWEEN cs.start_date AND cs.end_date
    ");
    
    $stmt->execute([$user_id, $code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    // Neu khong tim thay coupon thoa man
    if (!$coupon) {
        echo json_encode(['success' => false, 'message' => 'Voucher khong hop le, da dung hoac het luot']);
        exit;
    }

    // 4. Kiểm tra điều kiện đơn hàng tối thiểu
    if ($subtotal < $coupon['min_order_value']) {
        echo json_encode([
            'success' => false, 
            'message' => 'Đơn hàng chưa đủ tối thiểu ' . number_format($coupon['min_order_value'], 0, ',', '.') . 'đ'
        ]);
        exit;
    }

    // 5. Tính toán số tiền giảm
    $discount = 0;
    if ($coupon['discount_type'] === 'percentage') {
        $discount = ($subtotal * (float)$coupon['discount_value']) / 100;
        
        // Kiểm tra mức giảm tối đa (Max Discount) - CHỈ cho percentage
        if (!empty($coupon['max_discount']) && $coupon['max_discount'] > 0) {
            if ($discount > $coupon['max_discount']) {
                $discount = (float)$coupon['max_discount'];
            }
        }
    } else {
        // Fixed amount - dùng đúng discount_value, không cắt qua max_discount
        $discount = (float)$coupon['discount_value'];
    }

    // Tránh số âm
    if ($discount > $subtotal) {
        $discount = $subtotal;
    }

    echo json_encode([
        'success' => true,
        'discount_amount' => $discount,
        'coupon_code' => $coupon['code'],
        'message' => 'Áp dụng thành công! Giảm ' . number_format($discount, 0, ',', '.') . 'đ'
    ]);

} catch (PDOException $e) {
    error_log("Coupon Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}