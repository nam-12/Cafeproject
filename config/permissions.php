<?php
/**
 * Hệ thống Phân Quyền (Permission System)
 * File: config/permissions.php
 */

// ========================================
// HÀM KIỂM TRA QUYỀN TRUY CẬP
// ========================================

/**
 * Kiểm tra quyền truy cập trang - nếu không có quyền sẽ redirect
 * @param string|array $requiredPermissions - Quyền yêu cầu (string hoặc array)
 * @param bool $requireAll - true nếu phải có tất cả quyền, false nếu chỉ cần 1
 * @throws Exception - Nếu không có quyền
 */
function requirePermission($requiredPermissions, $requireAll = false) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../include/login.php');
        exit;
    }
    
    // Convert string to array
    if (is_string($requiredPermissions)) {
        $requiredPermissions = [$requiredPermissions];
    }
    
    // Check permissions
    $hasAccess = $requireAll 
        ? hasAllPermissions($requiredPermissions) 
        : hasAnyPermission($requiredPermissions);
    
    if (!$hasAccess) {
        // Log the unauthorized access attempt
        logActivity('unauthorized_access', $_GET['page'] ?? 'unknown', 'Cố gắng truy cập mà không có quyền');
        
        // Display error message
        header('HTTP/1.0 403 Forbidden');
        die('
            <!DOCTYPE html>
            <html lang="vi">
            <head>
                <meta charset="UTF-8">
                <title>Lỗi 403 - Truy Cập Bị Từ Chối</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
                <div class="text-center">
                    <h1 class="display-1">403</h1>
                    <h2 class="mb-4">Truy cập bị từ chối</h2>
                    <p class="mb-4">Bạn không có quyền truy cập trang này.</p>
                    <a href="index.php" class="btn btn-primary">Quay lại</a>
                </div>
            </body>
            </html>
        ');
    }
}

/**
 * Ghi lại hoạt động của người dùng (Activity Log)
 * @param string $action - Tên hành động
 * @param string $module - Module được truy cập
 * @param string $description - Mô tả chi tiết
 */
function logActivity($action, $module = '', $description = '') {
    global $pdo;
    
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $module,
            $description,
            $ip,
            $userAgent
        ]);
    } catch (Exception $e) {
        // Nếu có lỗi khi ghi log, bỏ qua
    }
}

/**
 * Lấy tất cả các permissions
 * @return array - Mảng tất cả permissions
 */
function getAllPermissions() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT id, name, display_name, description, module
            FROM permissions 
            ORDER BY module, display_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ========================================
// HELPER FUNCTIONS CHO VIEWS
// ========================================

/**
 * Hiển thị nút hoặc link chỉ nếu user có quyền
 * @param string $permission - Quyền yêu cầu
 * @param string $html - HTML để hiển thị
 * @return string - HTML hoặc chuỗi rỗng
 */
function showIfPermitted($permission, $html = '') {
    return hasPermission($permission) ? $html : '';
}

/**
 * Kiểm tra xem module có được phép truy cập không
 * @param string $module - Tên module
 * @return bool
 */
function canAccessModule($module) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            INNER JOIN roles r ON rp.role_id = r.id
            INNER JOIN user_roles ur ON r.id = ur.role_id
            WHERE ur.user_id = ? AND p.module = ?
        ");
        $stmt->execute([$_SESSION['user_id'] ?? 0, $module]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        return false;
    }
}
?>
