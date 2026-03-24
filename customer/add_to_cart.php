<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);
    
    if (!$product_id || $product_id <= 0 || $quantity <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dữ liệu đầu vào không hợp lệ.'
        ]);
        exit;
    }

    try {
        // 1. Truy vấn Database để lấy giá thực tế và thông tin khuyến mãi
        $stmt = $pdo->prepare("
            SELECT price, sale_price, discount_type, discount_start_date, discount_end_date 
            FROM products 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new Exception("Sản phẩm không tồn tại hoặc đã ngừng kinh doanh.");
        }

        // 2. Logic kiểm tra giá khuyến mãi (Đồng bộ với trang chủ)
        $now = time();
        $startDate = $product['discount_start_date'] ? strtotime($product['discount_start_date']) : 0;
        $endDate = $product['discount_end_date'] ? strtotime($product['discount_end_date']) : 2147483647;
        
        $isPromoActive = ($product['discount_type'] !== 'none' && $now >= $startDate && $now <= $endDate);
        
        // Xác định giá cuối cùng sẽ lưu vào giỏ hàng
        $finalPrice = ($isPromoActive && !empty($product['sale_price']) && $product['sale_price'] < $product['price']) 
                      ? $product['sale_price'] 
                      : $product['price'];

        // 3. Gọi hàm addToCart với giá đã được Server xác minh
        // Lưu ý: Đảm bảo hàm addToCart() trong helpers.php của bạn chấp nhận tham số giá
        $success = addToCart($product_id, $quantity, $finalPrice); 

        // 4. Lấy lại tổng số lượng để cập nhật icon giỏ hàng trên Header
        $cart_count = getCartCount();
        
        echo json_encode([
            'success' => true,
            'cart_count' => $cart_count,
            'message' => 'Đã thêm sản phẩm vào giỏ hàng thành công!',
            'debug_price' => $finalPrice // Có thể xóa dòng này sau khi test xong
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
    
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Yêu cầu không hợp lệ.'
    ]);
}