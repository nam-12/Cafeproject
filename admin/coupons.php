<?php
require_once '../config/init.php';
require_once '../config/coupon_maintenance.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem mã giảm giá
if (!hasPermission('view_coupons')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

// Khởi tạo biến
 $message = '';
 $error = '';
 $success = false;
 $maintenance_message = '';
 $maintenance_result = null;

// Xử lý AJAX request để lấy dữ liệu một mã giảm giá
if (isset($_GET['action']) && $_GET['action'] === 'get_coupon' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $id = intval($_GET['id']);
        if ($id <= 0) {
            throw new Exception("ID không hợp lệ!");
        }
        
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE id = ?");
        $stmt->execute([$id]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$coupon) {
            throw new Exception("Không tìm thấy mã giảm giá!");
        }
        
        echo json_encode([
            'success' => true,
            'coupon' => $coupon
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý AJAX request để lấy thống kê bảo trì
if (isset($_GET['action']) && $_GET['action'] === 'get_maintenance_stats') {
    header('Content-Type: application/json');
    
    if (!hasPermission('manage_roles')) {
        echo json_encode([
            'success' => false,
            'message' => 'Bạn không có quyền truy cập'
        ]);
        exit;
    }
    
    try {
        $stats = getCouponStats();
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý AJAX request để thực hiện dọn dẹp coupon
if (isset($_GET['action']) && $_GET['action'] === 'clean_coupons') {
    header('Content-Type: application/json');
    
    if (!hasPermission('manage_roles')) {
        echo json_encode([
            'success' => false,
            'message' => 'Bạn không có quyền thực hiện'
        ]);
        exit;
    }
    
    try {
        // Bước 1: Cập nhật trạng thái coupon hết hạn
        $updateResult = checkAndUpdateExpiredCoupons();
        
        // Bước 2: Xóa coupon hết hạn (ngay lập tức, không chờ)
        $deleteResult = autoDeleteExpiredCoupons([
            'auto_delete' => true,
            'days_after_expiry' => 0  // Xóa ngay, không chờ
        ]);
        
        // Bước 3: Lấy thống kê
        $finalStats = getCouponStats();
        
        echo json_encode([
            'success' => true,
            'update_count' => $updateResult['updated_count'] ?? 0,
            'delete_count' => $deleteResult['deleted_count'] ?? 0,
            'stats' => $finalStats,
            'message' => 'Dọn dẹp hoàn tất!'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý AJAX request để lọc và tìm kiếm
if (isset($_GET['action']) && $_GET['action'] === 'filter_coupons') {
    header('Content-Type: application/json');
    
    try {
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
        $filter_type = isset($_GET['type']) ? $_GET['type'] : '';
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        // Đếm tổng số bản ghi
        $countResult = getCouponsCount($pdo, $search, $filter_status, $filter_type);
        $totalRecords = $countResult['total'];
        $totalPages = ceil($totalRecords / $limit);
        
        // Lấy dữ liệu cho trang hiện tại
        $coupons = getCouponsList($pdo, $search, $filter_status, $filter_type, $limit, $offset);
        
        // Xử lý dữ liệu để trả về
        $couponsHtml = '';
        if (empty($coupons)) {
            $couponsHtml = '
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <h3>Không có mã giảm giá</h3>
                            <p>Không tìm thấy mã giảm giá nào phù hợp với bộ lọc.</p>
                        </div>
                    </td>
                </tr>
            ';
        } else {
            foreach ($coupons as $coupon) {
                // Determine status
                $now = new DateTime();
                $start = new DateTime($coupon['start_date']);
                $end = new DateTime($coupon['end_date']);
                
                if ($coupon['status'] === 'inactive') {
                    $statusClass = 'inactive';
                    $statusText = 'Tạm ngưng';
                } elseif ($now < $start) {
                    $statusClass = 'inactive';
                    $statusText = 'Sắp diễn ra';
                } elseif ($now > $end) {
                    $statusClass = 'expired';
                    $statusText = 'Hết hạn';
                } else {
                    $statusClass = 'active';
                    $statusText = 'Hoạt động';
                }
                
                // Format discount
                if ($coupon['discount_type'] === 'percentage') {
                    $discountText = $coupon['discount_value'] . '%';
                } else {
                    $discountText = number_format($coupon['discount_value']) . 'đ';
                }
                
                // Get usage count
                $usageStmt = $pdo->prepare("SELECT COUNT(*) as count FROM coupon_usage WHERE coupon_id = ?");
                $usageStmt->execute([$coupon['id']]);
                $usageCount = $usageStmt->fetch(PDO::FETCH_ASSOC)['count'];
                $maxUsage = $coupon['max_usage'] ? $coupon['max_usage'] : '∞';
                
                $couponsHtml .= '
                    <tr>
                        <td><input type="checkbox" class="coupon-checkbox" value="' . $coupon['id'] . '"></td>
                        <td>
                            <div class="coupon-code">' . htmlspecialchars($coupon['code']) . '</div>
                        </td>
                        <td>
                            <div class="coupon-name">' . htmlspecialchars($coupon['name']) . '</div>
                            <div class="coupon-desc">' . htmlspecialchars($coupon['description']) . '</div>
                        </td>
                        <td>
                            <span class="discount-badge">' . $discountText . '</span>
                        </td>
                        <td>
                            <small class="text-muted">Tối thiểu: ' . number_format($coupon['min_order_value']) . 'đ</small>
                        </td>
                        <td>
                            <small class="text-muted">' . $usageCount . '/' . $maxUsage . '</small>
                        </td>
                        <td>
                            <small class="text-muted">
                                ' . date('d/m/Y', strtotime($coupon['start_date'])) . '<br>
                                - ' . date('d/m/Y', strtotime($coupon['end_date'])) . '
                            </small>
                        </td>
                        <td>
                            <span class="status-badge ' . $statusClass . '">
                                <span class="status-dot"></span>
                                ' . $statusText . '
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                ' . (hasPermission('edit_coupon') ? '<button class="btn-action btn-edit" onclick="editCoupon(' . $coupon['id'] . ')" title="Chỉnh sửa">
                                    <i class="fas fa-edit"></i>
                                </button>' : '') . '
                                ' . (hasPermission('delete_coupon') ? '<button class="btn-action btn-delete" onclick="confirmDelete(' . $coupon['id'] . ', \'' . htmlspecialchars($coupon['code']) . '\')" title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>' : '') . '
                            </div>
                        </td>
                    </tr>
                ';
            }
        }
        
        // Tạo HTML cho phân trang
        $paginationHtml = '';
        if ($totalPages > 1) {
            $paginationHtml = '<div class="pagination-container"><div class="pagination">';
            
            if ($page > 1) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . ($page - 1) . ')"><i class="fas fa-chevron-left"></i></a>';
            } else {
                $paginationHtml .= '<div class="page-item disabled"><i class="fas fa-chevron-left"></i></div>';
            }
            
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(1)">1</a>';
                if ($startPage > 2) {
                    $paginationHtml .= '<div class="page-item disabled">...</div>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                $activeClass = $i == $page ? 'active' : '';
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item ' . $activeClass . '" onclick="loadPage(' . $i . ')">' . $i . '</a>';
            }
            
            if ($endPage < $totalPages) {
                if ($endPage < $totalPages - 1) {
                    $paginationHtml .= '<div class="page-item disabled">...</div>';
                }
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . $totalPages . ')">' . $totalPages . '</a>';
            }
            
            if ($page < $totalPages) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . ($page + 1) . ')"><i class="fas fa-chevron-right"></i></a>';
            } else {
                $paginationHtml .= '<div class="page-item disabled"><i class="fas fa-chevron-right"></i></div>';
            }
            
            $paginationHtml .= '</div></div>';
        }
        
        echo json_encode([
            'success' => true,
            'couponsHtml' => $couponsHtml,
            'paginationHtml' => $paginationHtml,
            'totalRecords' => $totalRecords
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý các hành động
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kiểm tra token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Yêu cầu không hợp lệ! Vui lòng thử lại.";
    } else {
        // Xử lý thêm mã giảm giá
        if (isset($_POST['action']) && $_POST['action'] === 'add') {
            $result = handleAddCoupon($pdo);
            $message = $result['message'];
            $success = $result['success'];
            if (!$success) $error = $message;
        }
        
        // Xử lý cập nhật mã giảm giá
        if (isset($_POST['action']) && $_POST['action'] === 'update') {
            if (!hasPermission('edit_coupon')) {
                $_SESSION['error'] = 'Bạn không có quyền sửa mã giảm giá!';
                header('Location: coupons.php');
                exit;
            }
            $result = handleUpdateCoupon($pdo);
            $message = $result['message'];
            $success = $result['success'];
            if (!$success) $error = $message;
        }
        
        // Xử lý xóa nhiều mã giảm giá
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
            if (!hasPermission('delete_coupon')) {
                $_SESSION['error'] = 'Bạn không có quyền xóa mã giảm giá!';
                header('Location: coupons.php');
                exit;
            }
            $result = handleBulkDelete($pdo);
            $message = $result['message'];
            $success = $result['success'];
            if (!$success) $error = $message;
        }
        
        // Xử lý xóa một mã giảm giá
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            if (!hasPermission('delete_coupon')) {
                $_SESSION['error'] = 'Bạn không có quyền xóa mã giảm giá!';
                header('Location: coupons.php');
                exit;
            }
            $result = handleDeleteCoupon($pdo);
            $message = $result['message'];
            $success = $result['success'];
            if (!$success) $error = $message;
        }
    }
}

// Thiết lập phân trang
 $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
 $limit = 20;
 $offset = ($page - 1) * $limit;

// Lấy danh sách mã giảm giá với phân trang
 $search = isset($_GET['search']) ? trim($_GET['search']) : '';
 $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
 $filter_type = isset($_GET['type']) ? $_GET['type'] : '';

// Đếm tổng số bản ghi
 $countResult = getCouponsCount($pdo, $search, $filter_status, $filter_type);
 $totalRecords = $countResult['total'];
 $totalPages = ceil($totalRecords / $limit);

// Lấy dữ liệu cho trang hiện tại
 $coupons = getCouponsList($pdo, $search, $filter_status, $filter_type, $limit, $offset);

// Lấy danh mục và sản phẩm cho form
 $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
 $products = $pdo->query("SELECT * FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Lấy thống kê tổng quan
 $stats = getCouponsStats($pdo);

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Các hàm xử lý
function handleAddCoupon($pdo) {
    try {
        // Validate dữ liệu đầu vào
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $discount_type = $_POST['discount_type'] ?? '';
        $discount_value = floatval($_POST['discount_value'] ?? 0);
        $max_discount = !empty($_POST['max_discount']) ? floatval($_POST['max_discount']) : null;
        $min_order_value = floatval($_POST['min_order_value'] ?? 0);
        $max_usage = !empty($_POST['max_usage']) ? intval($_POST['max_usage']) : null;
        $max_usage_per_user = intval($_POST['max_usage_per_user'] ?? 1);
        $apply_to = $_POST['apply_to'] ?? 'all';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $status = $_POST['status'] ?? 'inactive';

        // Kiểm tra bắt buộc
        if (empty($code) || empty($name) || empty($discount_type) || 
            empty($start_date) || empty($end_date)) {
            throw new Exception("Vui lòng điền đầy đủ các trường bắt buộc!");
        }

        // Kiểm tra giá trị giảm giá
        if ($discount_type === 'percentage' && ($discount_value <= 0 || $discount_value > 100)) {
            throw new Exception("Giá trị giảm giá phần trăm phải từ 0-100%!");
        }

        if ($discount_type === 'fixed' && $discount_value <= 0) {
            throw new Exception("Giá trị giảm giá phải lớn hơn 0!");
        }

        // Kiểm tra ngày hợp lệ
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);

        if ($end <= $start) {
            throw new Exception("Ngày kết thúc phải sau ngày bắt đầu!");
        }

        // Kiểm tra mã trùng lặp
        $checkStmt = $pdo->prepare("SELECT id FROM coupons WHERE code = ?");
        $checkStmt->execute([$code]);
        if ($checkStmt->fetch()) {
            throw new Exception("Mã giảm giá này đã tồn tại!");
        }

        // Xử lý apply_to_ids
        $apply_to_ids = null;
        if ($apply_to !== 'all' && !empty($_POST['apply_to_ids'])) {
            $ids = array_map('intval', $_POST['apply_to_ids']);
            $apply_to_ids = json_encode($ids);
        }

        // Thêm vào database
        $stmt = $pdo->prepare("
            INSERT INTO coupons (code, name, description, discount_type, discount_value, 
                               max_discount, min_order_value, max_usage, max_usage_per_user, 
                               apply_to, apply_to_ids, start_date, end_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $code, $name, $description, $discount_type, $discount_value,
            $max_discount, $min_order_value, $max_usage, $max_usage_per_user,
            $apply_to, $apply_to_ids, $start_date, $end_date, $status
        ]);

        return [
            'success' => true,
            'message' => "Thêm mã giảm giá thành công!"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Lỗi: " . $e->getMessage()
        ];
    }
}

function handleUpdateCoupon($pdo) {
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception("ID không hợp lệ!");
        }

        // Validate dữ liệu đầu vào
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $discount_type = $_POST['discount_type'] ?? '';
        $discount_value = floatval($_POST['discount_value'] ?? 0);
        $max_discount = !empty($_POST['max_discount']) ? floatval($_POST['max_discount']) : null;
        $min_order_value = floatval($_POST['min_order_value'] ?? 0);
        $max_usage = !empty($_POST['max_usage']) ? intval($_POST['max_usage']) : null;
        $max_usage_per_user = intval($_POST['max_usage_per_user'] ?? 1);
        $apply_to = $_POST['apply_to'] ?? 'all';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $status = $_POST['status'] ?? 'inactive';

        // Kiểm tra bắt buộc
        if (empty($name) || empty($discount_type) || 
            empty($start_date) || empty($end_date)) {
            throw new Exception("Vui lòng điền đầy đủ các trường bắt buộc!");
        }

        // Kiểm tra giá trị giảm giá
        if ($discount_type === 'percentage' && ($discount_value <= 0 || $discount_value > 100)) {
            throw new Exception("Giá trị giảm giá phần trăm phải từ 0-100%!");
        }

        if ($discount_type === 'fixed' && $discount_value <= 0) {
            throw new Exception("Giá trị giảm giá phải lớn hơn 0!");
        }

        // Kiểm tra ngày hợp lệ
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);

        if ($end <= $start) {
            throw new Exception("Ngày kết thúc phải sau ngày bắt đầu!");
        }

        // Xử lý apply_to_ids
        $apply_to_ids = null;
        if ($apply_to !== 'all' && !empty($_POST['apply_to_ids'])) {
            $ids = array_map('intval', $_POST['apply_to_ids']);
            $apply_to_ids = json_encode($ids);
        }

        // Cập nhật database
        $stmt = $pdo->prepare("
            UPDATE coupons 
            SET name = ?, description = ?, discount_type = ?, discount_value = ?,
                max_discount = ?, min_order_value = ?, max_usage = ?, max_usage_per_user = ?,
                apply_to = ?, apply_to_ids = ?, start_date = ?, end_date = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $name, $description, $discount_type, $discount_value,
            $max_discount, $min_order_value, $max_usage, $max_usage_per_user,
            $apply_to, $apply_to_ids, $start_date, $end_date, $status, $id
        ]);

        return [
            'success' => true,
            'message' => "Cập nhật mã giảm giá thành công!"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Lỗi: " . $e->getMessage()
        ];
    }
}

function handleBulkDelete($pdo) {
    try {
        if (empty($_POST['selected_ids'])) {
            throw new Exception("Vui lòng chọn ít nhất một mã giảm giá để xóa!");
        }

        $ids = array_map('intval', $_POST['selected_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        
        return [
            'success' => true,
            'message' => "Đã xóa " . count($ids) . " mã giảm giá thành công!"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Lỗi: " . $e->getMessage()
        ];
    }
}

function handleDeleteCoupon($pdo) {
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception("ID không hợp lệ!");
        }

        // Kiểm tra xem mã có đang được sử dụng không
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM coupon_usage WHERE coupon_id = ?");
        $checkStmt->execute([$id]);
        $usage = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($usage['count'] > 0) {
            // Thay vì xóa, chỉ vô hiệu hóa
            $stmt = $pdo->prepare("UPDATE coupons SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            return [
                'success' => true,
                'message' => "Mã giảm giá đã được vô hiệu hóa vì đang có đơn hàng sử dụng!"
            ];
        } else {
            $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
            $stmt->execute([$id]);
            return [
                'success' => true,
                'message' => "Xóa mã giảm giá thành công!"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Lỗi: " . $e->getMessage()
        ];
    }
}

function getCouponsCount($pdo, $search, $filter_status, $filter_type) {
    $now = date('Y-m-d H:i:s');
    $countSql = "SELECT COUNT(*) as total FROM coupons WHERE 1=1";
    $countParams = [];

    if ($search) {
        $countSql .= " AND (code LIKE ? OR name LIKE ?)";
        $countParams[] = "%$search%";
        $countParams[] = "%$search%";
    }

    if ($filter_status === 'expired') {
        // Lọc theo mã hết hạn
        $countSql .= " AND end_date < ? AND status != 'inactive'";
        $countParams[] = $now;
    } elseif ($filter_status) {
        $countSql .= " AND status = ?";
        $countParams[] = $filter_status;
    }

    if ($filter_type) {
        $countSql .= " AND discount_type = ?";
        $countParams[] = $filter_type;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    return $countStmt->fetch(PDO::FETCH_ASSOC);
}

function getCouponsList($pdo, $search, $filter_status, $filter_type, $limit, $offset) {
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT * FROM coupons WHERE 1=1";
    $params = [];

    if ($search) {
        $sql .= " AND (code LIKE ? OR name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter_status === 'expired') {
        // Lọc theo mã hết hạn
        $sql .= " AND end_date < ? AND status != 'inactive'";
        $params[] = $now;
    } elseif ($filter_status) {
        $sql .= " AND status = ?";
        $params[] = $filter_status;
    }

    if ($filter_type) {
        $sql .= " AND discount_type = ?";
        $params[] = $filter_type;
    }

    $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCouponsStats($pdo) {
    return $pdo->query("
        SELECT 
            COUNT(*) as total_coupons,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_coupons,
            (SELECT COUNT(*) FROM coupon_usage) as total_usage,
            (SELECT COALESCE(SUM(discount_amount), 0) FROM coupon_usage) as total_discount
        FROM coupons
    ")->fetch(PDO::FETCH_ASSOC);
}

// Hàm tạo URL phân trang
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Mã Giảm Giá - Cafe Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/cssad/coupons.css">
</head>
<body>
    <!-- Loading Spinner -->
    <div class="spinner-container" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="layout-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header-section">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div>
                        <h1>Quản Lý Mã Giảm Giá</h1>
                        <p>Tạo và quản lý các chương trình khuyến mãi</p>
                    </div>
                </div>
                <?php if (hasPermission('create_coupon')): ?>
                <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCouponModal">
                    <i class="fas fa-plus"></i>
                    Tạo mã mới
                </button>
                <?php endif; ?>
            </div>

            <!-- Alert -->
            <div id="alertContainer">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?>" id="messageAlert">
                        <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_coupons']); ?></div>
                            <div class="stat-label">Tổng mã giảm giá</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['active_coupons']); ?></div>
                            <div class="stat-label">Đang hoạt động</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_usage']); ?></div>
                            <div class="stat-label">Lượt sử dụng</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_discount'], 0, ',', '.'); ?></div>
                            <div class="stat-label">Tổng giảm giá (đ)</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filter -->
            <div class="search-filter-section">
                <form id="filterForm">
                    <div class="search-container">
                        <div class="search-box">
                            <i class="fas fa-search search-icon-input"></i>
                            <input type="text" class="search-input" id="searchInput" placeholder="Tìm kiếm theo mã hoặc tên chương trình..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <select class="filter-select" id="statusFilter">
                            <option value="">Tất cả trạng thái</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Đang hoạt động</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Tạm ngưng</option>
                            <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Hết hạn</option>
                        </select>
                        <select class="filter-select" id="typeFilter">
                            <option value="">Loại giảm giá</option>
                            <option value="percentage" <?php echo $filter_type === 'percentage' ? 'selected' : ''; ?>>Phần trăm</option>
                            <option value="fixed" <?php echo $filter_type === 'fixed' ? 'selected' : ''; ?>>Số tiền cố định</option>
                        </select>
                        <button type="button" class="btn-modal btn-primary-modal" onclick="applyFilters()">
                            <i class="fas fa-filter"></i>
                            Lọc
                        </button>
                        <button type="button" class="btn-modal btn-secondary-modal" onclick="resetFilters()">
                            <i class="fas fa-redo"></i>
                            Đặt lại
                        </button>
                        <?php if (hasPermission('manage_roles')): ?>
                        <button type="button" class="btn-modal btn-primary-modal" onclick="showMaintenanceModal()" style="margin-left: 10px; background-color: #fbbf24;">
                            <i class="fas fa-tools"></i>
                            Bảo Trì
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="table-container">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>MÃ GIẢM GIÁ</th>
                                <th>CHƯƠNG TRÌNH</th>
                                <th>GIẢM GIÁ</th>
                                <th>ĐIỀU KIỆN</th>
                                <th>SỬ DỤNG</th>
                                <th>THỜI HẠN</th>
                                <th>TRẠNG THÁI</th>
                                <th>THAO TÁC</th>
                            </tr>
                        </thead>
                        <tbody id="couponsTableBody">
                            <?php if (empty($coupons)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-ticket-alt"></i>
                                            </div>
                                            <h3>Không có mã giảm giá</h3>
                                            <p>Chưa có mã giảm giá nào được tạo. Hãy tạo mã giảm giá mới!</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($coupons as $coupon): ?>
                                    <?php
                                    // Determine status
                                    $now = new DateTime();
                                    $start = new DateTime($coupon['start_date']);
                                    $end = new DateTime($coupon['end_date']);
                                    
                                    if ($coupon['status'] === 'inactive') {
                                        $statusClass = 'inactive';
                                        $statusText = 'Tạm ngưng';
                                    } elseif ($now < $start) {
                                        $statusClass = 'inactive';
                                        $statusText = 'Sắp diễn ra';
                                    } elseif ($now > $end) {
                                        $statusClass = 'expired';
                                        $statusText = 'Hết hạn';
                                    } else {
                                        $statusClass = 'active';
                                        $statusText = 'Hoạt động';
                                    }
                                    
                                    // Format discount
                                    if ($coupon['discount_type'] === 'percentage') {
                                        $discountText = $coupon['discount_value'] . '%';
                                    } else {
                                        $discountText = number_format($coupon['discount_value']) . 'đ';
                                    }
                                    
                                    // Get usage count
                                    $usageStmt = $pdo->prepare("SELECT COUNT(*) as count FROM coupon_usage WHERE coupon_id = ?");
                                    $usageStmt->execute([$coupon['id']]);
                                    $usageCount = $usageStmt->fetch(PDO::FETCH_ASSOC)['count'];
                                    $maxUsage = $coupon['max_usage'] ? $coupon['max_usage'] : '∞';
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="coupon-checkbox" value="<?php echo $coupon['id']; ?>"></td>
                                        <td>
                                            <div class="coupon-code"><?php echo htmlspecialchars($coupon['code']); ?></div>
                                        </td>
                                        <td>
                                            <div class="coupon-name"><?php echo htmlspecialchars($coupon['name']); ?></div>
                                            <div class="coupon-desc"><?php echo htmlspecialchars($coupon['description']); ?></div>
                                        </td>
                                        <td>
                                            <span class="discount-badge"><?php echo $discountText; ?></span>
                                        </td>
                                        <td>
                                            <small class="text-muted">Tối thiểu: <?php echo number_format($coupon['min_order_value']); ?>đ</small>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo $usageCount; ?>/<?php echo $maxUsage; ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y', strtotime($coupon['start_date'])); ?><br>
                                                - <?php echo date('d/m/Y', strtotime($coupon['end_date'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <span class="status-dot"></span>
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if (hasPermission('edit_coupon')): ?>
                                                <button class="btn-action btn-edit" onclick="editCoupon(<?php echo $coupon['id']; ?>)" title="Chỉnh sửa">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (hasPermission('delete_coupon')): ?>
                                                <button class="btn-action btn-delete" onclick="confirmDelete(<?php echo $coupon['id']; ?>, '<?php echo htmlspecialchars($coupon['code']); ?>')" title="Xóa">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div id="paginationContainer">
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="javascript:void(0)" class="page-item" onclick="loadPage(<?php echo $page - 1; ?>)">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <div class="page-item disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </div>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<a href="javascript:void(0)" class="page-item" onclick="loadPage(1)">1</a>';
                                if ($startPage > 2) {
                                    echo '<div class="page-item disabled">...</div>';
                                }
                            }

                            for ($i = $startPage; $i <= $endPage; $i++) {
                                $activeClass = $i == $page ? 'active' : '';
                                echo '<a href="javascript:void(0)" class="page-item ' . $activeClass . '" onclick="loadPage(' . $i . ')">' . $i . '</a>';
                            }

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<div class="page-item disabled">...</div>';
                                }
                                echo '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . $totalPages . ')">' . $totalPages . '</a>';
                            }
                            ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="javascript:void(0)" class="page-item" onclick="loadPage(<?php echo $page + 1; ?>)">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <div class="page-item disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions" style="margin-top: 20px; display: none;">
                <div class="d-flex gap-2">
                    <button class="btn-modal btn-primary-modal" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i>
                        Xóa đã chọn
                    </button>
                    <button class="btn-modal btn-secondary-modal" onclick="deselectAll()">
                        <i class="fas fa-times"></i>
                        Bỏ chọn
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Thêm mã giảm giá -->
    <div class="modal fade" id="addCouponModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i>
                        Tạo mã giảm giá mới
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addCouponForm" method="POST" action="">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Mã giảm giá <span style="color: var(--danger-color);">*</span></label>
                                <input type="text" class="form-control" name="code" placeholder="VD: SUMMER2024" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tên chương trình <span style="color: var(--danger-color);">*</span></label>
                                <input type="text" class="form-control" name="name" placeholder="VD: Khuyến mãi mùa hè" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Mô tả</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Mô tả chi tiết về chương trình..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Loại giảm giá <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="discount_type" id="discount_type" required>
                                    <option value="">-- Chọn loại --</option>
                                    <option value="percentage">Phần trăm (%)</option>
                                    <option value="fixed">Số tiền cố định (đ)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Giá trị giảm <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="discount_value" placeholder="VD: 20 hoặc 50000" required>
                            </div>
                            <div class="col-md-6" id="max_discount_field" style="display: none;">
                                <label class="form-label">Giảm tối đa (cho % giảm)</label>
                                <input type="number" class="form-control" name="max_discount" placeholder="VD: 100000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Giá trị đơn tối thiểu <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="min_order_value" placeholder="VD: 200000" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Số lần sử dụng tối đa</label>
                                <input type="number" class="form-control" name="max_usage" placeholder="Để trống = không giới hạn">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Số lần/người <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="max_usage_per_user" value="1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Áp dụng cho <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="apply_to" id="apply_to" required>
                                    <option value="all">Tất cả sản phẩm</option>
                                    <option value="category">Danh mục cụ thể</option>
                                    <option value="product">Sản phẩm cụ thể</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="apply_to_ids_field" style="display: none;">
                                <label class="form-label">Chọn mục áp dụng</label>
                                <select class="form-select" name="apply_to_ids[]" id="apply_to_ids" multiple>
                                    <!-- Will be populated by JavaScript -->
                                </select>
                                <small class="text-muted">Giữ Ctrl/Cmd để chọn nhiều mục</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ngày bắt đầu <span style="color: var(--danger-color);">*</span></label>
                                <input type="datetime-local" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ngày kết thúc <span style="color: var(--danger-color);">*</span></label>
                                <input type="datetime-local" class="form-control" name="end_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Trạng thái <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="active">Hoạt động</option>
                                    <option value="inactive">Tạm ngưng</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-secondary-modal" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                        Hủy
                    </button>
                    <button type="submit" form="addCouponForm" class="btn-modal btn-primary-modal">
                        <i class="fas fa-save"></i>
                        Tạo mã giảm giá
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sửa mã giảm giá -->
    <div class="modal fade" id="editCouponModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i>
                        Chỉnh sửa mã giảm giá
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editCouponForm" method="POST" action="">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" id="edit_coupon_id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Mã giảm giá</label>
                                <input type="text" class="form-control" id="edit_coupon_code" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tên chương trình <span style="color: var(--danger-color);">*</span></label>
                                <input type="text" class="form-control" name="name" id="edit_coupon_name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Mô tả</label>
                                <textarea class="form-control" name="description" id="edit_coupon_description" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Loại giảm giá <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="discount_type" id="edit_discount_type" required>
                                    <option value="percentage">Phần trăm (%)</option>
                                    <option value="fixed">Số tiền cố định (đ)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Giá trị giảm <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="discount_value" id="edit_discount_value" required>
                            </div>
                            <div class="col-md-6" id="edit_max_discount_field" style="display: none;">
                                <label class="form-label">Giảm tối đa</label>
                                <input type="number" class="form-control" name="max_discount" id="edit_max_discount">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Giá trị đơn tối thiểu <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="min_order_value" id="edit_min_order_value" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Số lần sử dụng tối đa</label>
                                <input type="number" class="form-control" name="max_usage" id="edit_max_usage">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Số lần/người <span style="color: var(--danger-color);">*</span></label>
                                <input type="number" class="form-control" name="max_usage_per_user" id="edit_max_usage_per_user" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Áp dụng cho <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="apply_to" id="edit_apply_to" required>
                                    <option value="all">Tất cả sản phẩm</option>
                                    <option value="category">Danh mục cụ thể</option>
                                    <option value="product">Sản phẩm cụ thể</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="edit_apply_to_ids_field" style="display: none;">
                                <label class="form-label">Chọn mục áp dụng</label>
                                <select class="form-select" name="apply_to_ids[]" id="edit_apply_to_ids" multiple>
                                    <!-- Will be populated by JavaScript -->
                                </select>
                                <small class="text-muted">Giữ Ctrl/Cmd để chọn nhiều mục</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ngày bắt đầu <span style="color: var(--danger-color);">*</span></label>
                                <input type="datetime-local" class="form-control" name="start_date" id="edit_start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ngày kết thúc <span style="color: var(--danger-color);">*</span></label>
                                <input type="datetime-local" class="form-control" name="end_date" id="edit_end_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Trạng thái <span style="color: var(--danger-color);">*</span></label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <option value="active">Hoạt động</option>
                                    <option value="inactive">Tạm ngưng</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-secondary-modal" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                        Hủy
                    </button>
                    <button type="submit" form="editCouponForm" class="btn-modal btn-primary-modal">
                        <i class="fas fa-save"></i>
                        Cập nhật
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Xóa -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Xác nhận xóa
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Bạn có chắc chắn muốn xóa mã giảm giá <strong id="delete_coupon_code"></strong> không?</p>
                    <p class="text-muted">Lưu ý: Nếu mã giảm giá đã được sử dụng, nó sẽ chỉ bị vô hiệu hóa thay vì bị xóa.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-secondary-modal" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                        Hủy
                    </button>
                    <form id="deleteForm" method="POST" action="">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" id="delete_coupon_id">
                        <button type="submit" class="btn-modal btn-primary-modal" style="background-color: #fee2e2; color: #991b1b;">
                            <i class="fas fa-trash"></i>
                            Xóa
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Bảo Trì Coupon -->
    <div class="modal fade" id="maintenanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-tools"></i>
                        Bảo Trì Mã Giảm Giá
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="maintenanceContent">
                        <!-- Stats Cards -->
                        <div class="stats-grid" id="maintenanceStatsContainer">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Đang tải...</span>
                            </div>
                        </div>

                        <!-- Maintenance Actions -->
                        <div style="margin-top: 30px; text-align: center;">
                            <p style="color: #6b7280; margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i>
                                Nhấn nút dưới để dọn dẹp các mã giảm giá hết hạn
                            </p>
                            <button type="button" class="btn-modal btn-primary-modal" onclick="cleanCoupons()" id="cleanButton" style="padding: 12px 24px; font-size: 16px;">
                                <i class="fas fa-broom"></i>
                                Dọn Dẹp Coupon Hết Hạn
                            </button>
                        </div>

                        <!-- Result Display -->
                        <div id="maintenanceResult" style="margin-top: 20px; display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>// Categories and products data from PHP
const categories = <?php echo json_encode($categories); ?>;
const products = <?php echo json_encode($products); ?>;

// Current filter values
let currentFilters = {
    search: '<?php echo htmlspecialchars($search); ?>',
    status: '<?php echo $filter_status; ?>',
    type: '<?php echo $filter_type; ?>',
    page: <?php echo $page; ?>
};

// Show loading spinner
function showLoading() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

// Hide loading spinner
function hideLoading() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

// Show alert message
function showAlert(message, type = 'success') {
    const alertContainer = document.getElementById('alertContainer');
    const alertElement = document.createElement('div');
    alertElement.className = `alert alert-${type}`;
    alertElement.id = 'dynamicAlert';
    
    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    alertElement.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
    `;
    
    alertContainer.appendChild(alertElement);
    
    // Auto hide after 2 seconds
    setTimeout(() => {
        alertElement.style.opacity = '0';
        setTimeout(() => {
            alertElement.remove();
        }, 300);
    }, 2000);
}

// Handle discount type change
document.getElementById('discount_type').addEventListener('change', function() {
    const maxDiscountField = document.getElementById('max_discount_field');
    if (this.value === 'percentage') {
        maxDiscountField.style.display = 'block';
    } else {
        maxDiscountField.style.display = 'none';
    }
});

// Handle edit discount type change
document.getElementById('edit_discount_type').addEventListener('change', function() {
    const maxDiscountField = document.getElementById('edit_max_discount_field');
    if (this.value === 'percentage') {
        maxDiscountField.style.display = 'block';
    } else {
        maxDiscountField.style.display = 'none';
    }
});

// Handle apply to change
document.getElementById('apply_to').addEventListener('change', function() {
    const applyToIdsField = document.getElementById('apply_to_ids_field');
    const applyToIdsSelect = document.getElementById('apply_to_ids');
    
    if (this.value === 'all') {
        applyToIdsField.style.display = 'none';
    } else {
        applyToIdsField.style.display = 'block';
        
        // Clear existing options
        applyToIdsSelect.innerHTML = '';
        
        // Add new options based on selection
        if (this.value === 'category') {
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                applyToIdsSelect.appendChild(option);
            });
        } else if (this.value === 'product') {
            products.forEach(product => {
                const option = document.createElement('option');
                option.value = product.id;
                option.textContent = product.name;
                applyToIdsSelect.appendChild(option);
            });
        }
    }
});

// Handle edit apply to change
document.getElementById('edit_apply_to').addEventListener('change', function() {
    const applyToIdsField = document.getElementById('edit_apply_to_ids_field');
    const applyToIdsSelect = document.getElementById('edit_apply_to_ids');
    
    if (this.value === 'all') {
        applyToIdsField.style.display = 'none';
    } else {
        applyToIdsField.style.display = 'block';
        
        // Clear existing options
        applyToIdsSelect.innerHTML = '';
        
        // Add new options based on selection
        if (this.value === 'category') {
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                applyToIdsSelect.appendChild(option);
            });
        } else if (this.value === 'product') {
            products.forEach(product => {
                const option = document.createElement('option');
                option.value = product.id;
                option.textContent = product.name;
                applyToIdsSelect.appendChild(option);
            });
        }
    }
});

// Apply filters using AJAX
function applyFilters() {
    currentFilters.search = document.getElementById('searchInput').value;
    currentFilters.status = document.getElementById('statusFilter').value;
    currentFilters.type = document.getElementById('typeFilter').value;
    currentFilters.page = 1; // Reset to first page when applying new filters
    
    loadCoupons();
}

// Reset filters
function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('typeFilter').value = '';
    
    currentFilters = {
        search: '',
        status: '',
        type: '',
        page: 1
    };
    
    loadCoupons();
}

// Load coupons with current filters
function loadCoupons() {
    showLoading();
    
    const params = new URLSearchParams({
        action: 'filter_coupons',
        search: currentFilters.search,
        status: currentFilters.status,
        type: currentFilters.type,
        page: currentFilters.page
    });
    
    fetch(`coupons.php?${params.toString()}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Update table body
            document.getElementById('couponsTableBody').innerHTML = data.couponsHtml;
            
            // Update pagination
            document.getElementById('paginationContainer').innerHTML = data.paginationHtml;
            
            // Re-attach event listeners to new checkboxes
            attachCheckboxListeners();
        } else {
            showAlert(data.message, 'danger');
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Đã xảy ra lỗi khi tải dữ liệu. Vui lòng thử lại.', 'danger');
        hideLoading();
    });
}

// Load specific page
function loadPage(page) {
    currentFilters.page = page;
    loadCoupons();
}

// Attach event listeners to checkboxes
function attachCheckboxListeners() {
    // Select all checkboxes
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.coupon-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        
        toggleBulkActions();
    });

    // Handle individual checkbox change
    document.querySelectorAll('.coupon-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', toggleBulkActions);
    });
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Initial attachment of checkbox listeners
    attachCheckboxListeners();
    
    // Auto-hide initial alert after 2 seconds
    const initialAlert = document.getElementById('messageAlert');
    if (initialAlert) {
        setTimeout(() => {
            initialAlert.style.opacity = '0';
            setTimeout(() => {
                initialAlert.remove();
            }, 300);
        }, 2000);
    }
    
    // Add search on Enter key
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyFilters();
        }
    });
});

// Edit coupon function
function editCoupon(id) {
    showLoading();
    
    // Fetch coupon data via AJAX
    fetch(`coupons.php?action=get_coupon&id=${id}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Populate form fields
            document.getElementById('edit_coupon_id').value = data.coupon.id;
            document.getElementById('edit_coupon_code').value = data.coupon.code;
            document.getElementById('edit_coupon_name').value = data.coupon.name;
            document.getElementById('edit_coupon_description').value = data.coupon.description;
            document.getElementById('edit_discount_type').value = data.coupon.discount_type;
            document.getElementById('edit_discount_value').value = data.coupon.discount_value;
            document.getElementById('edit_max_discount').value = data.coupon.max_discount || '';
            document.getElementById('edit_min_order_value').value = data.coupon.min_order_value;
            document.getElementById('edit_max_usage').value = data.coupon.max_usage || '';
            document.getElementById('edit_max_usage_per_user').value = data.coupon.max_usage_per_user;
            document.getElementById('edit_apply_to').value = data.coupon.apply_to;
            
            // Format dates for datetime-local input
            const startDate = new Date(data.coupon.start_date);
            const endDate = new Date(data.coupon.end_date);
            
            // Format as YYYY-MM-DDTHH:MM
            const formattedStartDate = startDate.toISOString().slice(0, 16);
            const formattedEndDate = endDate.toISOString().slice(0, 16);
            
            document.getElementById('edit_start_date').value = formattedStartDate;
            document.getElementById('edit_end_date').value = formattedEndDate;
            document.getElementById('edit_status').value = data.coupon.status;
            
            // Trigger change events to update UI
            document.getElementById('edit_discount_type').dispatchEvent(new Event('change'));
            document.getElementById('edit_apply_to').dispatchEvent(new Event('change'));
            
            // If there are apply_to_ids, select them
            if (data.coupon.apply_to_ids) {
                setTimeout(() => {
                    const ids = JSON.parse(data.coupon.apply_to_ids);
                    const selectElement = document.getElementById('edit_apply_to_ids');
                    
                    Array.from(selectElement.options).forEach(option => {
                        option.selected = ids.includes(parseInt(option.value));
                    });
                }, 100);
            }
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editCouponModal'));
            modal.show();
        } else {
            showAlert('Lỗi: ' + data.message, 'danger');
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Đã xảy ra lỗi khi tải dữ liệu. Vui lòng thử lại.', 'danger');
        hideLoading();
    });
}

// Confirm delete function
function confirmDelete(id, code) {
    document.getElementById('delete_coupon_id').value = id;
    document.getElementById('delete_coupon_code').textContent = code;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Toggle bulk actions visibility
function toggleBulkActions() {
    const checkedBoxes = document.querySelectorAll('.coupon-checkbox:checked');
    const bulkActions = document.querySelector('.bulk-actions');
    
    if (checkedBoxes.length > 0) {
        bulkActions.style.display = 'block';
    } else {
        bulkActions.style.display = 'none';
    }
}

// Deselect all
function deselectAll() {
    document.getElementById('selectAll').checked = false;
    document.querySelectorAll('.coupon-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    toggleBulkActions();
}

// Bulk delete
function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.coupon-checkbox:checked');
    
    if (checkedBoxes.length === 0) {
        showAlert('Vui lòng chọn ít nhất một mã giảm giá để xóa!', 'danger');
        return;
    }
    
    if (confirm(`Bạn có chắc chắn muốn xóa ${checkedBoxes.length} mã giảm giá đã chọn không?`)) {
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_delete';
        form.appendChild(actionInput);
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfInput);
        
        ids.forEach(id => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'selected_ids[]';
            idInput.value = id;
            form.appendChild(idInput);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Handle form submissions with loading spinner
document.getElementById('addCouponForm').addEventListener('submit', function() {
    showLoading();
});

document.getElementById('editCouponForm').addEventListener('submit', function() {
    showLoading();
});

document.getElementById('deleteForm').addEventListener('submit', function() {
    showLoading();
});

// Fix aria-hidden issue by removing focus when modal is hidden
document.getElementById('deleteModal').addEventListener('hidden.bs.modal', function () {
    document.activeElement.blur();
});

document.getElementById('editCouponModal').addEventListener('hidden.bs.modal', function () {
    document.activeElement.blur();
});

document.getElementById('addCouponModal').addEventListener('hidden.bs.modal', function () {
    document.activeElement.blur();
});

// Mobile menu toggle
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('active');
}

// Show maintenance modal and load stats
function showMaintenanceModal() {
    const modal = new bootstrap.Modal(document.getElementById('maintenanceModal'));
    modal.show();
    
    loadMaintenanceStats();
}

// Load maintenance statistics
function loadMaintenanceStats() {
    const container = document.getElementById('maintenanceStatsContainer');
    
    fetch('coupons.php?action=get_maintenance_stats', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const stats = data.stats;
            container.innerHTML = `
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">${stats.total}</div>
                            <div class="stat-label">Tổng mã</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">${stats.active}</div>
                            <div class="stat-label">Đang hoạt động</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">${stats.expiring}</div>
                            <div class="stat-label">Hết hạn trong 7 ngày</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">${stats.expired}</div>
                            <div class="stat-label">Đã hết hạn</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        container.innerHTML = '<p style="color: #dc2626;">Lỗi tải dữ liệu</p>';
    });
}

// Clean expired coupons
function cleanCoupons() {
    const button = document.getElementById('cleanButton');
    const resultDiv = document.getElementById('maintenanceResult');
    
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang dọn dẹp...';
    resultDiv.style.display = 'none';
    
    fetch('coupons.php?action=clean_coupons', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-broom"></i> Dọn Dẹp Coupon Hết Hạn';
        
        if (data.success) {
            resultDiv.className = 'alert alert-success';
            resultDiv.innerHTML = `
                <div style="text-align: center;">
                    <i class="fas fa-check-circle" style="font-size: 32px; color: #059669; margin-bottom: 10px;"></i>
                    <h5 style="margin: 10px 0;">Dọn dẹp thành công!</h5>
                    <p><strong>${data.update_count}</strong> mã được cập nhật trạng thái</p>
                    <p><strong>${data.delete_count}</strong> mã đã hết hạn được xóa</p>
                </div>
            `;
            
            // Reload stats after 1 second
            setTimeout(() => {
                loadMaintenanceStats();
                loadCoupons(); // Reload main table
            }, 1000);
        } else {
            resultDiv.className = 'alert alert-danger';
            resultDiv.innerHTML = `
                <i class="fas fa-exclamation-circle"></i>
                <strong>Lỗi:</strong> ${data.message}
            `;
        }
        
        resultDiv.style.display = 'block';
    })
    .catch(error => {
        console.error('Error:', error);
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-broom"></i> Dọn Dẹp Coupon Hết Hạn';
        resultDiv.className = 'alert alert-danger';
        resultDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Đã xảy ra lỗi. Vui lòng thử lại.';
        resultDiv.style.display = 'block';
    });
}</script>
</body>
</html>