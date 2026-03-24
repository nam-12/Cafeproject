#!/usr/bin/env php
<?php
/**
 * Coupon Maintenance Cron Job
 * Chạy tự động để dọn dẹp mã giảm giá hết hạn
 * 
 * Cron job configuration:
 * 0 2 * * * /usr/bin/php /path/to/cafe/config/coupon_cron.php
 * (Chạy lúc 2:00 AM mỗi ngày)
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/coupon_maintenance.php';

$log_file = __DIR__ . '/../logs/coupon_maintenance.log';

// Tạo thư mục logs nếu chưa tồn tại
if (!is_dir(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

try {
    log_message('=== Bắt đầu quá trình bảo trì mã giảm giá ===');
    
    // Bước 1: Cập nhật trạng thái coupon hết hạn
    log_message('Bước 1: Cập nhật trạng thái coupon hết hạn...');
    $update_result = checkAndUpdateExpiredCoupons();
    log_message('  Kết quả: ' . $update_result['message']);
    
    // Bước 2: Xóa coupon hết hạn (30 ngày sau khi hết hạn)
    log_message('Bước 2: Xóa coupon hết hạn...');
    $delete_result = autoDeleteExpiredCoupons([
        'auto_delete' => true,
        'days_after_expiry' => 30,
        'keep_records' => false
    ]);
    log_message('  Kết quả: ' . $delete_result['message']);
    
    // Bước 3: Kiểm tra coupon sắp hết hạn
    log_message('Bước 3: Kiểm tra coupon sắp hết hạn...');
    $expiring = getExpiringCoupons(7);
    log_message('  Tìm thấy ' . count($expiring) . ' mã sắp hết hạn trong 7 ngày');
    
    // Bước 4: Lấy thống kê
    log_message('Bước 4: Thống kê hiện tại...');
    $stats = getCouponStats();
    log_message('  Tổng: ' . $stats['total'] . ', Hoạt động: ' . $stats['active'] . ', Hết hạn: ' . $stats['expired'] . ', Không hoạt động: ' . $stats['inactive']);
    
    // Log hoàn thành
    log_message('✓ Hoàn tất quá trình bảo trì mã giảm giá');
    log_message('=== Kết thúc ===');
    
    exit(0);
    
} catch (Exception $e) {
    log_message('✗ Lỗi: ' . $e->getMessage());
    log_message('Stack Trace: ' . $e->getTraceAsString());
    exit(1);
}
