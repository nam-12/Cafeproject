<?php
require_once '../config/init.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);

$ids = $data['coupon_ids'] ?? [];
if (!is_array($ids)) $ids = [$ids]; 

if (!$user_id || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ hoặc bạn chưa đăng nhập']);
    exit;
}

try {
    $pdo->beginTransaction();
    $collected_coupons = [];
    
    // 1. Chuẩn bị câu lệnh kiểm tra trạng thái và điều kiện thời gian
    // Sử dụng VIEW coupon_statistics để lấy display_status
    $checkStatus = $pdo->prepare("
        SELECT * FROM coupon_statistics 
        WHERE id = ? 
        AND display_status COLLATE utf8mb4_unicode_ci = 'Đang hoạt động'
    ");

    $checkHistory = $pdo->prepare("SELECT id FROM user_coupons WHERE user_id = ? AND coupon_id = ?");

    $stmtInsert = $pdo->prepare("INSERT INTO user_coupons (user_id, coupon_id, is_used) VALUES (?, ?, 0)");
    
    $now = new DateTime();

    foreach ($ids as $id) {
        // Kiểm tra tồn tại và trạng thái chung (active, chưa hết hạn, còn lượt...)
        $checkStatus->execute([$id]);
        $couponDetail = $checkStatus->fetch(PDO::FETCH_ASSOC);
        
        if (!$couponDetail) {
            continue; 
        }

        // 2. KIỂM TRA THỜI GIAN BẮT ĐẦU (CHẶN MÃ SẮP DIỄN RA)
        $startDate = new DateTime($couponDetail['start_date']);
        if ($now < $startDate) {
            // Nếu chỉ nhận 1 mã mà mã đó chưa diễn ra, ta trả về lỗi cụ thể
            if (count($ids) === 1) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false, 
                    'message' => 'Mã giảm giá này chưa đến thời gian diễn ra!'
                ]);
                exit;
            }
            // Nếu là "Nhận tất cả", ta lặng lẽ bỏ qua mã này
            continue;
        }

        // 3. Kiểm tra xem User đã nhận mã này chưa
        $checkHistory->execute([$user_id, $id]);
        if ($checkHistory->fetch()) {
            continue; 
        }

        // 4. Thực hiện thêm mã vào kho
        if ($stmtInsert->execute([$user_id, $id])) {
            $collected_coupons[] = $couponDetail;
        }
    }
    
    $pdo->commit();

    // 5. Trả về kết quả
    if (empty($collected_coupons)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Không có mã mới nào khả dụng để thu thập.'
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'collected_count' => count($collected_coupons),
            'coupons' => $collected_coupons,
            'message' => 'Đã thêm ' . count($collected_coupons) . ' ưu đãi vào kho của bạn!'
        ]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Coupon Collection Error: " . $e->getMessage()); // Ghi log lỗi hệ thống
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống khi xử lý mã giảm giá.']);
}