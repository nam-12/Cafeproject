<?php
// ===============================
// 1. CẤU HÌNH MÔI TRƯỜNG
// ===============================
define('ENVIRONMENT', 'development');
define('DEBUG_MODE', true);

// ===============================
// 2. CẤU HÌNH DATABASE
// ===============================
define('DB_HOST', 'localhost');
define('DB_NAME', 'cafe_management');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ===============================
// 3. CẤU HÌNH URL
// ===============================
define('BASE_URL', 'http://localhost/cafe/');
define('ASSETS_URL', BASE_URL . 'assets/');
define('UPLOAD_PATH', __DIR__ . '/uploads/');

// ===============================
// 4. CẤU HÌNH BẢO MẬT
// ===============================
define('SITE_KEY', 'cafe-shop-secret-key-2024');
define('HASH_ALGORITHM', 'sha256');

// ===============================
// 5. THÔNG TIN CHUYỂN KHOẢN
// ===============================
define('BANK_NAME', 'Ngân hàng TMCP Quân đội (MB Bank)');
define('BANK_CODE', 'MB');
define('BANK_ACCOUNT_NO', '0937658390');
define('BANK_ACCOUNT_NAME', 'HOANG TUAN HUNG');

// ===============================


// ===============================
// 7. CẤU HÌNH EMAIL
// ===============================
define('MAIL_FROM', 'junghior925@gmail.com');
define('MAIL_FROM_NAME', 'Cafe Shop');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'junghior925@gmail.com');
define('SMTP_PASS', 'taug hdzu tykj kkaq');

// ===============================
// 8. CẤU HÌNH SESSION
// ===============================
define('SESSION_LIFETIME', 3600 * 2);
define('SESSION_NAME', 'CAFE_SHOP_SESSION');

// ===============================
// 9. TIMEZONE
// ===============================
define('APP_TIMEZONE', 'Asia/Ho_Chi_Minh');
// Thực thi cài đặt Timezone
date_default_timezone_set(APP_TIMEZONE);

// ===============================
// ===== CẤU HÌNH GIAO HÀNG =====
define('GRAPH_HOPPER_API_KEY', 'cce29cb5-0d85-4d3d-898a-d52d380d7bf6');  // Dán API key GraphHopper vào đây
define('STORE_ADDRESS', '41A Đ.Phú Diễn,Phú Diễn, Bắc Từ Liêm, Hà Nội'); // ← Thay bằng địa chỉ quán thật
define('STORE_LAT', 21.047051);  // ← Thay bằng tọa độ latitude quán thật
define('STORE_LNG', 105.762203); // ← Thay bằng tọa độ longitude quán thật

// 10. HELPER
// ===============================
function getConfig(string $key, $default = null) {
    return defined($key) ? constant($key) : $default;
}
