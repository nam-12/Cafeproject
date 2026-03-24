<?php
/**
 * Coupon Maintenance Functions
 * Quản lý tự động xóa/cập nhật các coupon hết hạn
 */

require_once 'init.php';

/**
 * Kiểm tra và cập nhật trạng thái coupon hết hạn
 */
function checkAndUpdateExpiredCoupons() {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        // Cập nhật status thành 'expired' cho các coupon hết hạn
        $stmt = $pdo->prepare("
            UPDATE coupons 
            SET status = 'expired' 
            WHERE status != 'expired' 
            AND end_date < ?
        ");
        $stmt->execute([$now]);
        $updated_count = $stmt->rowCount();
        
        logActivity(
            'system_maintenance',
            'coupons',
            "Cập nhật trạng thái coupon hết hạn: $updated_count bản ghi"
        );
        
        return [
            'success' => true,
            'message' => "Đã cập nhật $updated_count mã giảm giá hết hạn",
            'updated_count' => $updated_count
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ];
    }
}

/**
 * Xóa tất cả coupon hết hạn (tuân theo cấu hình)
 * @param array $config Cấu hình xóa: ['auto_delete' => true, 'days_after_expiry' => 30]
 */
function autoDeleteExpiredCoupons($config = []) {
    global $pdo;
    
    // Cấu hình mặc định
    $default_config = [
        'auto_delete' => true,
        'days_after_expiry' => 0,  // Xóa coupon hết hạn ngay lập tức (0 = không chờ)
        'keep_records' => false,     // Có lưu giữ lịch sử không
    ];
    
    $config = array_merge($default_config, $config);
    
    if (!$config['auto_delete']) {
        return [
            'success' => true,
            'message' => 'Chức năng xóa tự động đã bị tắt',
            'deleted_count' => 0
        ];
    }
    
    try {
        $now = date('Y-m-d H:i:s');
        
        // Nếu không giữ lịch sử, xóa toàn bộ coupon hết hạn (end_date < now)
        if (!$config['keep_records']) {
            // Trước tiên xóa coupon_usage liên quan (cascade delete)
            $stmt = $pdo->prepare("
                DELETE FROM coupon_usage 
                WHERE coupon_id IN (
                    SELECT id FROM coupons 
                    WHERE status = 'expired' AND end_date < ?
                )
            ");
            $stmt->execute([$now]);
            
            // Sau đó xóa coupons
            $stmt = $pdo->prepare("
                DELETE FROM coupons 
                WHERE status = 'expired' 
                AND end_date < ?
            ");
            $stmt->execute([$now]);
            $deleted_count = $stmt->rowCount();
        } else {
            // Nếu giữ lịch sử, chỉ đánh dấu inactive
            $stmt = $pdo->prepare("
                UPDATE coupons 
                SET status = 'inactive' 
                WHERE status = 'expired' 
                AND end_date < ?
            ");
            $stmt->execute([$now]);
            $deleted_count = $stmt->rowCount();
        }
        
        if ($deleted_count > 0) {
            logActivity(
                'system_maintenance',
                'coupons',
                "Xóa/ẩn " . ($config['keep_records'] ? "ẩn" : "xóa") . " $deleted_count mã giảm giá hết hạn"
            );
        }
        
        return [
            'success' => true,
            'message' => "Đã xóa $deleted_count mã giảm giá hết hạn",
            'deleted_count' => $deleted_count,
            'action' => $config['keep_records'] ? 'marked_inactive' : 'deleted'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ];
    }
}

/**
 * Lấy danh sách coupon sắp hết hạn
 * @param int $days Số ngày trước khi hết hạn
 * @return array Danh sách coupon sắp hết hạn
 */
function getExpiringCoupons($days = 7) {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        $expire_date = date('Y-m-d H:i:s', strtotime("+$days days"));
        
        $stmt = $pdo->prepare("
            SELECT id, code, name, discount_type, discount_value, 
                   end_date, status, used_count 
            FROM coupons 
            WHERE status = 'active' 
            AND end_date > ? 
            AND end_date <= ?
            ORDER BY end_date ASC
        ");
        $stmt->execute([$now, $expire_date]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Lấy danh sách coupon đã hết hạn
 * @param int $limit Số lượng tối đa
 * @return array Danh sách coupon hết hạn
 */
function getExpiredCoupons($limit = 20) {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("
            SELECT id, code, name, discount_type, discount_value, 
                   end_date, status, used_count 
            FROM coupons 
            WHERE end_date < ?
            ORDER BY end_date DESC
            LIMIT ?
        ");
        $stmt->execute([$now, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Lấy thống kê coupon
 */
function getCouponStats() {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        // Tổng số coupon
        $total = $pdo->query("SELECT COUNT(*) FROM coupons")->fetchColumn();
        
        // Coupon đang hoạt động (start_date <= now AND end_date > now AND status != inactive)
        $active = $pdo->query("
            SELECT COUNT(*) FROM coupons 
            WHERE status != 'inactive' 
            AND start_date <= '$now' 
            AND end_date > '$now'
        ")->fetchColumn();
        
        // Coupon hết hạn (end_date < now)
        $expired = $pdo->query("
            SELECT COUNT(*) FROM coupons 
            WHERE end_date < '$now'
        ")->fetchColumn();
        
        // Coupon sắp hết hạn (7 ngày)
        $expiring_date = date('Y-m-d H:i:s', strtotime('+7 days'));
        $expiring = $pdo->query("
            SELECT COUNT(*) FROM coupons 
            WHERE status != 'inactive' 
            AND end_date > '$now' 
            AND end_date <= '$expiring_date'
        ")->fetchColumn();
        
        // Coupon chưa bắt đầu
        $upcoming = $pdo->query("
            SELECT COUNT(*) FROM coupons 
            WHERE start_date > '$now'
        ")->fetchColumn();
        
        // Coupon không hoạt động
        $inactive = $pdo->query("
            SELECT COUNT(*) FROM coupons 
            WHERE status = 'inactive'
        ")->fetchColumn();
        
        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'expiring' => $expiring,
            'upcoming' => $upcoming,
            'inactive' => $inactive
        ];
    } catch (Exception $e) {
        return [
            'total' => 0,
            'active' => 0,
            'expired' => 0,
            'expiring' => 0,
            'upcoming' => 0,
            'inactive' => 0
        ];
    }
}

/**
 * Xóa một coupon cụ thể
 */
function deleteCoupon($coupon_id) {
    global $pdo;
    
    try {
        // Xóa lịch sử sử dụng coupon
        $pdo->prepare("DELETE FROM coupon_usage WHERE coupon_id = ?")->execute([$coupon_id]);
        
        // Xóa coupon
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->execute([$coupon_id]);
        
        if ($stmt->rowCount() > 0) {
            logActivity(
                'delete_coupon',
                'coupons',
                "Xóa mã giảm giá ID: $coupon_id"
            );
            return ['success' => true, 'message' => 'Xóa mã giảm giá thành công'];
        } else {
            return ['success' => false, 'message' => 'Không tìm thấy mã giảm giá'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Xóa tất cả coupon hết hạn ngay lập tức
 */
function deleteAllExpiredCoupons() {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        // Lấy ID các coupon hết hạn
        $expired_ids = $pdo->query("
            SELECT id FROM coupons 
            WHERE end_date < '$now'
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        // Xóa lịch sử sử dụng
        if (!empty($expired_ids)) {
            $ids_str = implode(',', $expired_ids);
            $pdo->exec("DELETE FROM coupon_usage WHERE coupon_id IN ($ids_str)");
        }
        
        // Xóa coupon
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE end_date < ?");
        $stmt->execute([$now]);
        $deleted_count = $stmt->rowCount();
        
        if ($deleted_count > 0) {
            logActivity(
                'system_maintenance',
                'coupons',
                "Xóa $deleted_count mã giảm giá hết hạn"
            );
        }
        
        return [
            'success' => true,
            'message' => "Đã xóa $deleted_count mã giảm giá hết hạn",
            'deleted_count' => $deleted_count
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ];
    }
}

// Nếu được gọi trực tiếp từ CLI hoặc cron job
if (php_sapi_name() === 'cli' || (isset($_GET['action']) && $_GET['action'] === 'maintain_coupons')) {
    header('Content-Type: application/json');
    
    // Cập nhật trạng thái coupon hết hạn
    $result1 = checkAndUpdateExpiredCoupons();
    
    // Xóa coupon hết hạn (sau 30 ngày)
    $result2 = autoDeleteExpiredCoupons([
        'auto_delete' => true,
        'days_after_expiry' => 30,
        'keep_records' => false
    ]);
    
    echo json_encode([
        'update_status' => $result1,
        'delete_expired' => $result2,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
