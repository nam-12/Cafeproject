<?php
require_once '../config/init.php';

header('Content-Type: application/json');

// 1. Chỉ chấp nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ']);
    exit;
}

// 2. Kiểm tra CSRF bảo mật
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Hết phiên làm việc hoặc Token không hợp lệ']);
    exit;
}

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'ID đơn hàng không hợp lệ']);
    exit;
}

try {
    $db = getDB();
    $db->beginTransaction();
    
    // 3. Lấy thông tin đơn hàng
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Không tìm thấy đơn hàng!');
    }
    
    // 4. Kiểm tra trạng thái (Chỉ cho phép hủy khi đang chờ xử lý hoặc đã xác nhận nhưng chưa giao)
    $allowCancel = ['pending', 'confirmed'];
    if (!in_array($order['status'], $allowCancel)) {
        throw new Exception('Đơn hàng đang trong quá trình giao hoặc đã hoàn thành, không thể hủy!');
    }

    // --- 5. LOGIC HOÀN TRẢ VOUCHER (MỚI) ---
    if (!empty($order['coupon_code'])) {
        // Tìm ID của coupon dựa trên code lưu trong đơn hàng
        $stmtC = $db->prepare("SELECT id FROM coupon_statistics WHERE UPPER(code) COLLATE utf8mb4_unicode_ci = UPPER(?) COLLATE utf8mb4_unicode_ci");
        $stmtC->execute([$order['coupon_code']]);
        $couponData = $stmtC->fetch();
        
        if ($couponData) {
            $couponId = $couponData['id'];
            // Cập nhật trạng thái voucher của người dùng về 'chưa sử dụng'
            $stmtRestoreCoupon = $db->prepare("
                UPDATE user_coupons 
                SET is_used = 0, used_at = NULL 
                WHERE user_id = ? AND coupon_id = ? AND is_used = 1
            ");
            $stmtRestoreCoupon->execute([$order['user_id'], $couponId]);
        }
    }

    // 6. Cập nhật trạng thái đơn hàng thành 'cancelled'
    // Sử dụng hàm helper nếu có, hoặc update trực tiếp
    $stmtUpdateOrder = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $stmtUpdateOrder->execute([$orderId]);
    
    // 7. Cập nhật trạng thái thanh toán (nếu có giao dịch online đang chờ)
    $stmtPayment = $db->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ? AND status = 'pending'");
    $stmtPayment->execute([$orderId]);
    
    // 8. Hoàn lại kho & Ghi lịch sử kho
    $stmtItems = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll();
    
    foreach ($items as $item) {
        // Hoàn lại số lượng vào kho chính
        $stmtInventory = $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ?");
        $stmtInventory->execute([$item['quantity'], $item['product_id']]);
        
        // Ghi log nhập lại kho do hủy đơn
        $stmtLog = $db->prepare("
            INSERT INTO inventory_history (product_id, quantity_change, type, note)
            VALUES (?, ?, 'in', ?)
        ");
        $stmtLog->execute([
            $item['product_id'],
            $item['quantity'],
            "Hoàn kho từ đơn hàng hủy #{$order['order_number']}"
        ]);
    }
    
    // 9. Thêm lịch sử theo dõi đơn hàng (Tracking)
    $stmtTracking = $db->prepare("
        INSERT INTO order_tracking (order_id, status, note)
        VALUES (?, 'cancelled', 'Đơn hàng đã được hủy thành công. Voucher và số lượng sản phẩm đã được hoàn lại.')
    ");
    $stmtTracking->execute([$orderId]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Đã hủy đơn hàng và hoàn trả voucher thành công!'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    logError('Cancel Order Error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi: ' . $e->getMessage()
    ]);
}