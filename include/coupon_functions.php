<?php
/**
 * Coupon Functions
 * Xử lý các chức năng liên quan đến mã giảm giá
 */

/**
 * Lấy danh sách mã giảm giá khả dụng cho người dùng
 */
function getAvailableCoupons($pdo, $user_id = null) {
    $current_date = date('Y-m-d H:i:s');
    $available_coupons = [];
    
    $sql = "SELECT * FROM coupons 
            WHERE status = 'active' 
            AND start_date <= :current_date 
            AND end_date >= :current_date
            AND (usage_limit IS NULL OR usage_count < usage_limit)
            ORDER BY discount_value DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['current_date' => $current_date]);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($coupons as $coupon) {
        // Kiểm tra xem coupon có áp dụng cho user không
        if (!empty($coupon['user_id']) && $coupon['user_id'] != $user_id) {
            continue;
        }
        
        // Kiểm tra số lần sử dụng của user (nếu có)
        if ($user_id && !empty($coupon['usage_per_user'])) {
            $user_usage = getUserCouponUsage($pdo, $coupon['id'], $user_id);
            if ($user_usage >= $coupon['usage_per_user']) {
                continue;
            }
        }
        
        // Format hiển thị
        $coupon['discount_display'] = formatDiscountDisplay($coupon);
        $coupon['max_discount_display'] = !empty($coupon['max_discount']) ? 
            " (Tối đa: " . number_format($coupon['max_discount'], 0, ',', '.') . "đ)" : "";
        $coupon['min_order_display'] = !empty($coupon['min_order']) ? 
            "Đơn tối thiểu: " . number_format($coupon['min_order'], 0, ',', '.') . "đ" : "";
        
        $available_coupons[] = $coupon;
    }
    
    return $available_coupons;
}

/**
 * Kiểm tra và áp dụng mã giảm giá
 */
function validateAndApplyCoupon($pdo, $coupon_code, $cart_total, $cart_items = [], $user_id = null, $customer_email = '') {
    $response = [
        'success' => false,
        'message' => '',
        'data' => []
    ];
    
    // Kiểm tra mã coupon
    $coupon = getCouponByCode($pdo, $coupon_code);
    if (!$coupon) {
        $response['message'] = 'Mã giảm giá không tồn tại';
        return $response;
    }
    
    // Kiểm tra trạng thái
    if ($coupon['status'] != 'active') {
        $response['message'] = 'Mã giảm giá đã hết hiệu lực';
        return $response;
    }
    
    // Kiểm tra thời gian
    $current_date = date('Y-m-d H:i:s');
    if ($current_date < $coupon['start_date'] || $current_date > $coupon['end_date']) {
        $response['message'] = 'Mã giảm giá đã hết hạn hoặc chưa có hiệu lực';
        return $response;
    }
    
    // Kiểm tra số lần sử dụng
    if (!empty($coupon['usage_limit']) && $coupon['usage_count'] >= $coupon['usage_limit']) {
        $response['message'] = 'Mã giảm giá đã hết lượt sử dụng';
        return $response;
    }
    
    // Kiểm tra user cụ thể (nếu có)
    if (!empty($coupon['user_id']) && $coupon['user_id'] != $user_id) {
        $response['message'] = 'Mã giảm giá không áp dụng cho tài khoản của bạn';
        return $response;
    }
    
    // Kiểm tra email khách hàng (nếu có)
    if (!empty($coupon['customer_email']) && $coupon['customer_email'] != $customer_email) {
        $response['message'] = 'Mã giảm giá không áp dụng cho email của bạn';
        return $response;
    }
    
    // Kiểm tra số lần sử dụng của user
    if ($user_id && !empty($coupon['usage_per_user'])) {
        $user_usage = getUserCouponUsage($pdo, $coupon['id'], $user_id);
        if ($user_usage >= $coupon['usage_per_user']) {
            $response['message'] = 'Bạn đã sử dụng hết lượt dùng mã giảm giá này';
            return $response;
        }
    }
    
    // Kiểm tra đơn hàng tối thiểu
    if (!empty($coupon['min_order']) && $cart_total < $coupon['min_order']) {
        $response['message'] = 'Đơn hàng phải có giá trị tối thiểu ' . number_format($coupon['min_order'], 0, ',', '.') . 'đ';
        return $response;
    }
    
    // Kiểm tra sản phẩm áp dụng (nếu có)
    if (!empty($coupon['applicable_products'])) {
        $applicable_products = json_decode($coupon['applicable_products'], true);
        if (!is_array($applicable_products)) {
            $applicable_products = [];
        }
        
        $cart_product_ids = array_column($cart_items, 'product_id');
        $valid_product = false;
        
        if ($coupon['product_type'] == 'specific') {
            // Chỉ áp dụng cho sản phẩm cụ thể
            foreach ($cart_product_ids as $product_id) {
                if (in_array($product_id, $applicable_products)) {
                    $valid_product = true;
                    break;
                }
            }
            
            if (!$valid_product) {
                $response['message'] = 'Mã giảm giá không áp dụng cho sản phẩm trong giỏ hàng';
                return $response;
            }
        } elseif ($coupon['product_type'] == 'excluded') {
            // Không áp dụng cho sản phẩm cụ thể
            foreach ($cart_product_ids as $product_id) {
                if (in_array($product_id, $applicable_products)) {
                    $response['message'] = 'Mã giảm giá không áp dụng cho một số sản phẩm trong giỏ hàng';
                    return $response;
                }
            }
        }
    }
    
    // Kiểm tra danh mục áp dụng (nếu có)
    if (!empty($coupon['applicable_categories'])) {
        $applicable_categories = json_decode($coupon['applicable_categories'], true);
        if (!is_array($applicable_categories)) {
            $applicable_categories = [];
        }
        
        $valid_category = false;
        $cart_product_ids = array_column($cart_items, 'product_id');
        
        if (!empty($cart_product_ids)) {
            $placeholders = str_repeat('?,', count($cart_product_ids) - 1) . '?';
            $sql = "SELECT DISTINCT category_id FROM products WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($cart_product_ids);
            $cart_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($coupon['category_type'] == 'specific') {
                // Chỉ áp dụng cho danh mục cụ thể
                foreach ($cart_categories as $category_id) {
                    if (in_array($category_id, $applicable_categories)) {
                        $valid_category = true;
                        break;
                    }
                }
                
                if (!$valid_category) {
                    $response['message'] = 'Mã giảm giá không áp dụng cho danh mục sản phẩm trong giỏ hàng';
                    return $response;
                }
            } elseif ($coupon['category_type'] == 'excluded') {
                // Không áp dụng cho danh mục cụ thể
                foreach ($cart_categories as $category_id) {
                    if (in_array($category_id, $applicable_categories)) {
                        $response['message'] = 'Mã giảm giá không áp dụng cho một số danh mục sản phẩm trong giỏ hàng';
                        return $response;
                    }
                }
            }
        }
    }
    
    // Tính toán giảm giá
    $discount_amount = calculateDiscountAmount($coupon, $cart_total);
    
    // Chuẩn bị dữ liệu trả về
    $response['success'] = true;
    $response['message'] = 'Áp dụng mã giảm giá thành công';
    $response['data'] = [
        'coupon_id' => $coupon['id'],
        'coupon_code' => $coupon['code'],
        'coupon_name' => $coupon['name'],
        'discount_type' => $coupon['discount_type'],
        'discount_value' => $coupon['discount_value'],
        'discount_amount' => $discount_amount,
        'max_discount' => $coupon['max_discount'] ?? null,
        'min_order' => $coupon['min_order'] ?? null
    ];
    
    return $response;
}

/**
 * Lấy thông tin coupon theo mã
 */
function getCouponByCode($pdo, $coupon_code) {
    $sql = "SELECT * FROM coupons WHERE code = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$coupon_code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Lấy số lần sử dụng coupon của user
 */
function getUserCouponUsage($pdo, $coupon_id, $user_id) {
    $sql = "SELECT COUNT(*) as usage_count FROM orders 
            WHERE coupon_id = ? AND user_id = ? AND status IN ('pending', 'processing', 'completed')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$coupon_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['usage_count'] ?? 0;
}

/**
 * Tính toán số tiền giảm giá
 */
function calculateDiscountAmount($coupon, $cart_total) {
    $discount_amount = 0;
    
    if ($coupon['discount_type'] == 'percentage') {
        // Giảm giá theo phần trăm
        $discount_amount = ($cart_total * $coupon['discount_value']) / 100;
        
        // Giới hạn tối đa (nếu có)
        if (!empty($coupon['max_discount']) && $discount_amount > $coupon['max_discount']) {
            $discount_amount = $coupon['max_discount'];
        }
    } elseif ($coupon['discount_type'] == 'fixed') {
        // Giảm giá cố định
        $discount_amount = min($coupon['discount_value'], $cart_total);
    } elseif ($coupon['discount_type'] == 'free_shipping') {
        // Miễn phí vận chuyển
        $discount_amount = 30000; // Giả sử phí ship cố định là 30,000đ
    }
    
    return $discount_amount;
}

/**
 * Format hiển thị discount
 */
function formatDiscountDisplay($coupon) {
    if ($coupon['discount_type'] == 'percentage') {
        return "-" . $coupon['discount_value'] . "%";
    } elseif ($coupon['discount_type'] == 'fixed') {
        return "-" . number_format($coupon['discount_value'], 0, ',', '.') . "đ";
    } elseif ($coupon['discount_type'] == 'free_shipping') {
        return "Miễn phí vận chuyển";
    }
    return "";
}

/**
 * Format mô tả coupon
 */
function formatCouponDescription($coupon) {
    $description = $coupon['description'] ?? '';
    
    if (!empty($coupon['min_order'])) {
        $description .= (!empty($description) ? " - " : "") . "Đơn tối thiểu: " . 
                       number_format($coupon['min_order'], 0, ',', '.') . "đ";
    }
    
    if (!empty($coupon['start_date']) && !empty($coupon['end_date'])) {
        $start_date = date('d/m/Y', strtotime($coupon['start_date']));
        $end_date = date('d/m/Y', strtotime($coupon['end_date']));
        $description .= (!empty($description) ? " - " : "") . 
                       "HSD: $start_date - $end_date";
    }
    
    if (!empty($coupon['usage_per_user'])) {
        $description .= (!empty($description) ? " - " : "") . 
                       "Mỗi KH được dùng " . $coupon['usage_per_user'] . " lần";
    }
    
    return $description;
}

/**
 * Cập nhật số lần sử dụng coupon
 */
function updateCouponUsage($pdo, $coupon_id) {
    $sql = "UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$coupon_id]);
}
?>