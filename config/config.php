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
// 6. CẤU HÌNH THANH TOÁN
// ===============================
define('CURRENCY', 'VND');
define('SHIPPING_FEE', 10000);
define('FREE_SHIP_THRESHOLD', 200000);
define('VAT_RATE', 0);

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
// 10. HELPER
// ===============================
function getConfig(string $key, $default = null) {
    return defined($key) ? constant($key) : $default;
}
