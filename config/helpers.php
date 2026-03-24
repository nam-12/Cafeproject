<?php
// Format số tiền theo định dạng VND
function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . ' ₫';
}

// Format ngày tháng
function formatDate($date, $format = 'd/m/Y H:i') {
    return date($format, strtotime($date));
}

// Escape HTML để tránh XSS
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Lấy giá trị từ array an toàn
function getArrayValue($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// Thêm sản phẩm vào giỏ hàng
function addToCart($productId, $quantity = 1) {
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    // 1. Validate và chuẩn hóa đầu vào
    $productId = (int)$productId;
    $quantity = (int)$quantity;
    
    if ($productId <= 0 || $quantity <= 0) {
        throw new Exception('Thông tin sản phẩm không hợp lệ!');
    }
    
    try {
        $db = getDB();
        
        // 2. Truy vấn thông tin sản phẩm (Lấy thêm các cột giảm giá)
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('Sản phẩm không tồn tại hoặc không còn bán!');
        }

        // --- Bắt đầu Logic Tính toán Giá Thực tế (Promo Logic) ---
        $now = time();
        $startDate = $product['discount_start_date'] ? strtotime($product['discount_start_date']) : 0;
        $endDate = $product['discount_end_date'] ? strtotime($product['discount_end_date']) : 2147483647;
        
        $isPromoActive = ($product['discount_type'] !== 'none' && $now >= $startDate && $now <= $endDate);
        
        // Giá an toàn từ DB: Ưu tiên sale_price nếu đang trong đợt giảm giá
        $finalPrice = ($isPromoActive && !empty($product['sale_price']) && $product['sale_price'] < $product['price']) 
                      ? (float)$product['sale_price'] 
                      : (float)$product['price'];
        // --- Kết thúc Logic Giá ---
        
        // 3. Lấy tồn kho thực tế từ bảng inventory
        $stmtInventory = $db->prepare("SELECT quantity FROM inventory WHERE product_id = ?");
        $stmtInventory->execute([$productId]);
        $inventory = $stmtInventory->fetch(PDO::FETCH_ASSOC);
        $current_stock = $inventory['quantity'] ?? 0;
        
        // 4. Xử lý Logic Thêm mới hoặc Cập nhật
        if (isset($_SESSION['cart'][$productId])) {
            $current_cart_qty = $_SESSION['cart'][$productId]['quantity'];
            $new_quantity = $current_cart_qty + $quantity;
            
            if ($new_quantity > $current_stock) {
                throw new Exception("Không đủ hàng trong kho! Số lượng tối đa có thể thêm thêm là: " . ($current_stock - $current_cart_qty));
            }
            
            $_SESSION['cart'][$productId]['quantity'] = $new_quantity;
            
            // Cập nhật lại các thông tin quan trọng để đảm bảo đồng bộ giá mới nhất
            $_SESSION['cart'][$productId]['name'] = $product['name'];
            $_SESSION['cart'][$productId]['price'] = $finalPrice; // Sử dụng giá đã tính toán
            $_SESSION['cart'][$productId]['stock'] = $current_stock; 
            
            return true;
            
        } else {
            if ($current_stock < $quantity) {
                throw new Exception('Sản phẩm không đủ số lượng trong kho!');
            }
            
            $_SESSION['cart'][$productId] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $finalPrice, // Sử dụng giá đã tính toán
                'image' => $product['image'],
                'quantity' => $quantity,
                'stock' => $current_stock 
            ];
            
            return true;
        }
        
    } catch (PDOException $e) {
        if (function_exists('logError')) {
            logError('Add to cart error: ' . $e->getMessage(), ['product_id' => $productId]); 
        }
        throw new Exception('Lỗi hệ thống khi truy vấn sản phẩm.');
    } catch (Exception $e) {
        throw $e; 
    }
}

// Cập nhật số lượng sản phẩm trong giỏ
function updateCartQuantity($productId, $quantity) {
    if (isset($_SESSION['cart'][$productId])) {
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
        } else {
            $_SESSION['cart'][$productId]['quantity'] = $quantity;
        }
    }
}

// Xóa sản phẩm khỏi giỏ hàng
function removeFromCart($productId) {
    if (isset($_SESSION['cart'][$productId])) {
        unset($_SESSION['cart'][$productId]);
    }
}

// Lấy tổng số lượng sản phẩm trong giỏ
function getCartCount() {
    if (!isset($_SESSION['cart'])) {
        return 0;
    }
    
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $count += $item['quantity'];
    }
    return $count;
}

// Tính tổng tiền giỏ hàng
function getCartTotal() {
    if (!isset($_SESSION['cart'])) {
        return 0;
    }
    
    $total = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

/// ================================================================
// HÀM TÍNH PHÍ VẬN CHUYỂN
// ================================================================

/**
 * Tính phí ship theo khoảng cách
 * <= 2km  → 10,000đ
 * <= 5km  → 15,000đ
 * >  5km  → 20,000đ + 3,000đ/km vượt (làm tròn lên)
 */
function calculateShippingFee(float $km): int {
    if ($km <= 2) return 10000;
    if ($km <= 5) return 15000;
    return 20000 + (int)(ceil($km - 5) * 3000);
}

/**
 * Lấy khoảng cách từ quán tới địa chỉ khách
 * Ưu tiên Google Maps API, fallback về OpenStreetMap
 */
function getShippingDistance(string $customerAddress): array {
    $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';

    if (!empty($apiKey)) {
        return _getDistanceGoogleMaps($customerAddress, $apiKey);
    }
    return _getDistanceOpenStreetMap($customerAddress);
}

function _getDistanceGoogleMaps(string $address, string $apiKey): array {
    $origin      = urlencode(STORE_ADDRESS);
    $destination = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json"
         . "?origins={$origin}&destinations={$destination}&mode=driving&language=vi&key={$apiKey}";

    $ctx  = stream_context_create(['http' => ['timeout' => 6]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return _fallbackDistance($address);

    $data = json_decode($resp, true);
    if (($data['status'] ?? '') !== 'OK'
        || ($data['rows'][0]['elements'][0]['status'] ?? '') !== 'OK') {
        return ['success' => false, 'km' => 3.0, 'error' => 'Địa chỉ không tìm thấy trên bản đồ'];
    }

    $km = round($data['rows'][0]['elements'][0]['distance']['value'] / 1000, 2);
    return ['success' => true, 'km' => $km, 'error' => ''];
}

function _getDistanceOpenStreetMap(string $address): array {
    $encoded = urlencode($address . ', Việt Nam');
    $url     = "https://nominatim.openstreetmap.org/search?q={$encoded}&format=json&limit=1";
    $ctx     = stream_context_create(['http' => [
        'header'  => "User-Agent: CafeProject/1.0\r\n",
        'timeout' => 6
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return _fallbackDistance($address);

    $results = json_decode($resp, true);
    if (empty($results)) {
        return ['success' => false, 'km' => 3.0, 'error' => 'Không geocode được địa chỉ'];
    }

    $lat = (float)$results[0]['lat'];
    $lng = (float)$results[0]['lon'];

    // Haversine formula (đường chim bay × 1.3 ≈ đường bộ)
    $R    = 6371;
    $dLat = deg2rad($lat - STORE_LAT);
    $dLng = deg2rad($lng - STORE_LNG);
    $a    = sin($dLat/2)**2 + cos(deg2rad(STORE_LAT)) * cos(deg2rad($lat)) * sin($dLng/2)**2;
    $km   = round($R * 2 * atan2(sqrt($a), sqrt(1-$a)) * 1.3, 2);

    return ['success' => true, 'km' => $km, 'error' => ''];
}

function _fallbackDistance(string $address): array {
    error_log("[Shipping] Geocode failed for: {$address} — dùng mặc định 3km");
    return ['success' => false, 'km' => 3.0, 'error' => 'Dùng khoảng cách ước lượng'];
}

// Xóa toàn bộ giỏ hàng
function clearCart() {
    $_SESSION['cart'] = [];
}

// Tạo mã đơn hàng duy nhất
function generateOrderNumber() {
    return 'ORD' . date('YmdHis') . rand(1000, 9999);
}

// Tạo mã hóa đơn
function generateInvoiceNumber() {
    return 'INV' . date('YmdHis') . rand(100, 999);
}
// Tạo request ID cho payment gateway
function generateRequestId() {
    return time() . rand(1000, 9999);
}

// Gửi email hóa đơn
function sendInvoiceEmail($toEmail, $orderData, $invoiceHtml) {
    $subject = "Hóa đơn điện tử - Đơn hàng " . $orderData['order_number'];
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">" . "\r\n";
    
    $message = "
    <html>
    <head>
        <title>{$subject}</title>
    </head>
    <body>
        <h2>Cảm ơn bạn đã đặt hàng!</h2>
        <p>Xin chào {$orderData['customer_name']},</p>
        <p>Đơn hàng của bạn đã được xác nhận. Dưới đây là hóa đơn chi tiết:</p>
        {$invoiceHtml}
        <p>Trân trọng,<br>Cafe Shop Team</p>
    </body>
    </html>
    ";
    
    return mail($toEmail, $subject, $message, $headers);
}

// Tạo URL QR code cho chuyển khoản VietQR
function generateBankQRUrl($amount, $orderNumber, $note = '') {
    $accountNo = BANK_ACCOUNT_NO;
    $accountName = BANK_ACCOUNT_NAME;
    $addInfo = $note ?: "DH " . $orderNumber;
    $bankBin = '970422'; // MB Bank
    $template = 'compact2';
    $qrUrl = "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-{$template}.png";
    $qrUrl .= "?amount={$amount}";
    $qrUrl .= "&addInfo=" . urlencode($addInfo);
    $qrUrl .= "&accountName=" . urlencode($accountName);
    return $qrUrl;
}

// Lấy mã BIN ngân hàng
function getBankBin($bankCode = 'MB') {
    $bankBins = [
        'MB' => '970422',      // MB Bank
        'VCB' => '970436',     // Vietcombank
        'TCB' => '970407',     // Techcombank
        'ACB' => '970416',     // ACB
        'VTB' => '970415',     // Vietinbank
        'BIDV' => '970418',    // BIDV
        'AGB' => '970405',     // Agribank
        'VPB' => '970432',     // VPBank
        'TPB' => '970423',     // TPBank
        'STB' => '970403',     // Sacombank
        'HDB' => '970437',     // HDBank
        'SHB' => '970443',     // SHB
        'EIB' => '970431',     // Eximbank
        'MSB' => '970426',     // MSB
        'OCB' => '970448',     // OCB
        'SCB' => '970429',     // SCB
        'SEA' => '970440',     // SeABank
        'VIB' => '970441',     // VIB
        'LPB' => '970449',     // LienVietPostBank
        'BAB' => '970409',     // BacABank
    ];
    
    return $bankBins[$bankCode] ?? '970422';
}

// HÀM DATABASE

// Lưu log payment vào database
function logPayment($paymentId, $endpoint, $payload) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO payment_logs (payment_id, endpoint, payload) VALUES (?, ?, ?)");
    return $stmt->execute([$paymentId, $endpoint, json_encode($payload)]);
}

// Cập nhật trạng thái thanh toán
function updatePaymentStatus($paymentId, $status, $gatewayTxnId = null, $responsePayload = null) {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE payments 
        SET status = ?, 
            gateway_txn_id = COALESCE(?, gateway_txn_id),
            response_payload = COALESCE(?, response_payload)
        WHERE id = ?
    ");
    return $stmt->execute([$status, $gatewayTxnId, $responsePayload, $paymentId]);
}

// Cập nhật trạng thái đơn hàng
function updateOrderStatus($orderId, $status) {
    $db = getDB();
    
    try {
        // Cập nhật orders table
        $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $orderId]);
        $affected = $stmt->rowCount();
        
        // Thêm vào order_tracking (luôn ghi log tracking để trace)
        $stmtTrack = $db->prepare("INSERT INTO order_tracking (order_id, status, note) VALUES (?, ?, ?)");
        $note = "Trạng thái cập nhật: " . $status;
        $stmtTrack->execute([$orderId, $status, $note]);
        
        if ($affected === 0) {
            // Không có row bị ảnh hưởng — log chi tiết để debug 
            logError('updateOrderStatus: no rows affected', ['order_id' => $orderId, 'status' => $status]);
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        logError('updateOrderStatus error: ' . $e->getMessage(), ['order_id' => $orderId, 'status' => $status]);
        return false;
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

// Hàm kiểm tra quyền admin
if (!function_exists('checkAdmin')) {
    function checkAdmin() {
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
            header('Location: index.php');
            exit;
        }
    }
}

function getUserAvatarPath($avatar)
{
    // Avatar mặc định
    $defaultAvatar = '/assets/images/default-avatar.png';

    // Không có avatar
    if (empty($avatar)) {
        return $defaultAvatar;
    }

    // Nếu là avatar mặc định trong admin
    if (str_starts_with($avatar, 'default/')) {
        $path = 'admin/upload/' . $avatar;
        return file_exists($path) ? $path : $defaultAvatar;
    }

    // Avatar user upload
    $path = 'admin/upload/' . ltrim($avatar, '/');
    return file_exists($path) ? $path : $defaultAvatar;
}
/**
 * Dịch hành động sang tiếng Việt
 */
function translateAction($action) {
    $translations = [
        // Dashboard & System
        'view_dashboard' => 'Xem bảng điều khiển',
        
        // Authentication
        'login' => 'Đăng nhập',
        'login_failed' => 'Đăng nhập thất bại',
        'logout' => 'Đăng xuất',
        'unauthorized_access' => 'Cố gắng truy cập không hợp lệ',
        
        // Roles & Permissions
        'add_role' => 'Thêm vai trò',
        'update_role' => 'Cập nhật vai trò',
        'update_role_permissions' => 'Cập nhật quyền vai trò',
        'delete_role' => 'Xóa vai trò',
        'assign_role' => 'Gán vai trò',
        'remove_role' => 'Xóa vai trò',
        
        // Users & Employees
        'add_user' => 'Thêm nhân viên',
        'create_user' => 'Tạo tài khoản nhân viên',
        'update_user' => 'Cập nhật nhân viên',
        'delete_user' => 'Xóa nhân viên',
        'toggle_user_status' => 'Thay đổi trạng thái nhân viên',
        'activate_user' => 'Kích hoạt nhân viên',
        'deactivate_user' => 'Vô hiệu hóa nhân viên',
        
        // Products
        'create_product' => 'Thêm sản phẩm',
        'add_product' => 'Thêm sản phẩm',
        'update_product' => 'Cập nhật sản phẩm',
        'delete_product' => 'Xóa sản phẩm',
        'import_product' => 'Nhập sản phẩm',
        'export_product' => 'Xuất sản phẩm',
        
        // Categories
        'create_category' => 'Thêm danh mục',
        'add_category' => 'Thêm danh mục',
        'update_category' => 'Cập nhật danh mục',
        'delete_category' => 'Xóa danh mục',
        
        // Coupons
        'create_coupon' => 'Thêm mã giảm giá',
        'add_coupon' => 'Thêm mã giảm giá',
        'update_coupon' => 'Cập nhật mã giảm giá',
        'delete_coupon' => 'Xóa mã giảm giá',
        'bulk_delete_coupon' => 'Xóa nhiều mã giảm giá',
        'coupon_used' => 'Sử dụng mã giảm giá',
        'clean_expired_coupons' => 'Dọn dẹp mã giảm giá hết hạn',
        'update_expired_coupons' => 'Cập nhật trạng thái coupon hết hạn',
        'delete_expired_coupons' => 'Xóa coupon hết hạn',
        
        // Orders
        'create_order' => 'Tạo đơn hàng',
        'add_order' => 'Thêm đơn hàng',
        'update_order' => 'Cập nhật đơn hàng',
        'delete_order' => 'Xóa đơn hàng',
        'approve_order' => 'Phê duyệt đơn hàng',
        'reject_order' => 'Từ chối đơn hàng',
        'cancel_order' => 'Hủy đơn hàng',
        'complete_order' => 'Hoàn thành đơn hàng',
        'update_order_status' => 'Cập nhật trạng thái đơn hàng',
        
        // Inventory
        'view_inventory' => 'Xem kho hàng',
        'update_inventory' => 'Cập nhật kho hàng',
        'adjust_stock' => 'Điều chỉnh số lượng kho',
        'low_stock_alert' => 'Cảnh báo kho hàng thấp',
        'restock' => 'Nhập hàng',
        
        // Reviews & Ratings
        'approve_review' => 'Phê duyệt đánh giá',
        'reject_review' => 'Từ chối đánh giá',
        'delete_review' => 'Xóa đánh giá',
        'reply_review' => 'Trả lời đánh giá',
        
        // Reports & Analytics
        'view_reports' => 'Xem báo cáo',
        'export_report' => 'Xuất báo cáo',
        'generate_report' => 'Tạo báo cáo',
        'view_analytics' => 'Xem thống kê',
        
        // System & Maintenance
        'system_maintenance' => 'Dọn dẹp hệ thống',
        'backup_database' => 'Sao lưu cơ sở dữ liệu',
        'clear_cache' => 'Xóa bộ nhớ cache',
        'update_settings' => 'Cập nhật cài đặt',
        'view_logs' => 'Xem nhật ký',
        'clear_logs' => 'Xóa nhật ký',
        
        // Profile & Settings
        'update_profile' => 'Cập nhật hồ sơ',
        'change_password' => 'Đổi mật khẩu',
        'update_avatar' => 'Cập nhật ảnh đại diện',
        
        // Customer & Shopping
        'add_to_cart' => 'Thêm vào giỏ hàng',
        'remove_from_cart' => 'Xóa khỏi giỏ hàng',
        'checkout' => 'Thanh toán',
        'apply_coupon' => 'Áp dụng mã giảm giá',
        'payment_success' => 'Thanh toán thành công',
        'payment_failed' => 'Thanh toán thất bại',
        'track_order' => 'Theo dõi đơn hàng',
    ];
    
    return isset($translations[$action]) ? $translations[$action] : ucfirst(str_replace('_', ' ', $action));
}

/**
 * Dịch module sang tiếng Việt
 */
function translateModule($module) {
    $translations = [
        'dashboard' => 'Bảng điều khiển',
        'auth' => 'Xác thực',
        'authentication' => 'Xác thực',
        'users' => 'Quản lý nhân viên',
        'employees' => 'Quản lý nhân viên',
        'staff' => 'Quản lý nhân viên',
        'roles' => 'Quản lý vai trò',
        'permissions' => 'Quản lý quyền',
        'products' => 'Quản lý sản phẩm',
        'categories' => 'Quản lý danh mục',
        'coupons' => 'Quản lý mã giảm giá',
        'orders' => 'Quản lý đơn hàng',
        'inventory' => 'Quản lý kho hàng',
        'stock' => 'Quản lý kho hàng',
        'reports' => 'Báo cáo & Thống kê',
        'analytics' => 'Thống kê',
        'reviews' => 'Quản lý đánh giá',
        'settings' => 'Cài đặt',
        'system' => 'Hệ thống',
        'system_maintenance' => 'Dọn dẹp mã giảm giá',
        'maintenance' => 'Bảo trì hệ thống',
        'profile' => 'Hồ sơ người dùng',
        'cart' => 'Giỏ hàng',
        'checkout' => 'Thanh toán',
        'payment' => 'Thanh toán',
        'customer' => 'Khách hàng',
        'shopping' => 'Mua sắm',
    ];
    
    return isset($translations[$module]) ? $translations[$module] : ucfirst($module);
}

// ========================================
// HÀM QUẢN LÝ ROLE VÀ PERMISSIONS
// ========================================

/**
 * Lấy tất cả roles
 */
function getAllRoles() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY name");
    return $stmt->fetchAll();
}

/**
 * Lấy roles của user
 */
function getUserRoles($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT r.* FROM roles r
        INNER JOIN user_roles ur ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY r.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Gán role cho user
 */
function assignRoleToUser($userId, $roleId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, assigned_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE assigned_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$userId, $roleId, $_SESSION['user_id'] ?? null]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Xóa role khỏi user
 */
function removeRoleFromUser($userId, $roleId) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
    return $stmt->execute([$userId, $roleId]);
}

/**
 * Lấy permissions của role
 */
function getRolePermissions($roleId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT permission_id FROM role_permissions
        WHERE role_id = ?
    ");
    $stmt->execute([$roleId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Lấy permissions của user qua roles
 */
function getUserPermissions($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.name
        FROM permissions p
        INNER JOIN role_permissions rp ON p.id = rp.permission_id
        INNER JOIN user_roles ur ON rp.role_id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Kiểm tra user có permission không
 */
function hasPermission($permission, $userId = null) {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if ($userId === null) {
        $userId = $_SESSION['user_id'];
    }

    // Admin có tất cả quyền
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            INNER JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ? AND p.name = ?
        ");
        $stmt->execute([$userId, $permission]);
        return $stmt->fetch()['count'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Kiểm tra user có bất kỳ permission nào trong danh sách
 */
function hasAnyPermission($permissions, $userId = null) {
    if (!is_array($permissions)) {
        $permissions = [$permissions];
    }

    foreach ($permissions as $permission) {
        if (hasPermission($permission, $userId)) {
            return true;
        }
    }
    return false;
}

/**
 * Kiểm tra user có tất cả permissions trong danh sách
 */
function hasAllPermissions($permissions, $userId = null) {
    if (!is_array($permissions)) {
        $permissions = [$permissions];
    }

    foreach ($permissions as $permission) {
        if (!hasPermission($permission, $userId)) {
            return false;
        }
    }
    return true;
}