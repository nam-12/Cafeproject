<?php
require_once '../config/init.php';

// Kiểm tra đăng nhập và quyền admin
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role_check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$role_check->execute([$user_id]);
$user_role = $role_check->fetchColumn();

if ($user_role !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Không có quyền']);
    exit;
}

// Kiểm tra CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Token không hợp lệ']);
    exit;
}

$upload_dir = __DIR__ . '/upload/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Xử lý upload avatar mặc định
if (isset($_FILES['default_avatar_upload'])) {
    $file = $_FILES['default_avatar_upload'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận file ảnh (JPG, PNG, GIF, WEBP)']);
        exit;
    }
    
    if ($file['size'] > 5000000) {
        echo json_encode(['success' => false, 'message' => 'Kích thước file tối đa 5MB']);
        exit;
    }
    
    // Tìm số thứ tự tiếp theo
    $existing_files = glob($upload_dir . 'default_avatar_*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    $max_number = 0;
    foreach ($existing_files as $existing_file) {
        if (preg_match('/default_avatar_(\d+)\./', basename($existing_file), $matches)) {
            $max_number = max($max_number, intval($matches[1]));
        }
    }
    $next_number = $max_number + 1;
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'default_avatar_' . $next_number . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => true, 'filename' => $filename]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi khi lưu file']);
    }
    exit;
}

// Xử lý xóa avatar mặc định
if (isset($_POST['delete_default_avatar'])) {
    $avatar_path = $_POST['avatar_path'];
    $filename = basename($avatar_path);
    
    // Kiểm tra file có phải là default avatar không
    if (!preg_match('/^default_avatar_\d+\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
        echo json_encode(['success' => false, 'message' => 'File không hợp lệ']);
        exit;
    }
    
    $filepath = $upload_dir . $filename;
    
    if (file_exists($filepath)) {
        // Kiểm tra xem có user nào đang dùng avatar này không
        $check_usage = $pdo->prepare("SELECT COUNT(*) FROM users WHERE avatar = ?");
        $check_usage->execute([$avatar_path]);
        $usage_count = $check_usage->fetchColumn();
        
        if ($usage_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Không thể xóa: Có ' . $usage_count . ' người dùng đang sử dụng avatar này']);
            exit;
        }
        
        if (unlink($filepath)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi xóa file']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'File không tồn tại']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ']);
?>