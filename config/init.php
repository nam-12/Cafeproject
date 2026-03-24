<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Kết nối database
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET time_zone = '+07:00';"
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Lưu kết nối vào biến global
    $GLOBALS['db'] = $pdo;
    
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die("Database Connection Error: " . $e->getMessage());
    } else {
        die("Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.");
    }
}

// Include file helpers
require_once __DIR__ . '/helpers.php';

// Include file permissions (phân quyền)
require_once __DIR__ . '/permissions.php';

// Khởi tạo giỏ hàng nếu chưa có
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Hàm lấy database connection
function getDB() {
    return $GLOBALS['db'];
}

// Hàm thêm flash message
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

// Hàm lấy và xóa flash message
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

// Hàm redirect
function redirect($url, $permanent = false) {
    if (strpos($url, 'http') !== 0) {
        $url = BASE_URL . $url;
    }
    
    if (headers_sent($file, $line)) {
        
        echo "<script>window.location.href='" . $url . "';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=" . $url . "'></noscript>";
    } else {
       
        header('Location: ' . $url, true, $permanent ? 301 : 302);
    }
    exit;
}

// Hàm lấy thông tin user (nếu đã đăng nhập)
function getCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// Hàm kiểm tra đăng nhập
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Hàm xử lý lỗi tùy chỉnh
function handleError($message, $code = 500) {
    if (DEBUG_MODE) {
        die("Error [{$code}]: {$message}");
    } else {
        die("Đã xảy ra lỗi. Vui lòng thử lại sau.");
    }
}

// Log lỗi vào file
function logError($message, $context = []) {
    $logFile = __DIR__ . '/logs/error_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logMessage = "[{$timestamp}] {$message} {$contextStr}\n";
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Hàm validate CSRF token (bảo mật)
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Hàm tạo CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hàm lấy CSRF token field
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}