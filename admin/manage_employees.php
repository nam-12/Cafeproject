<?php
require_once '../config/init.php';

// Kiểm tra quyền - chỉ admin mới có thể quản lý nhân viên
requirePermission(['manage_roles', 'create_user', 'edit_user']);

// Lấy tất cả roles (cần cho AJAX)
$all_roles = getAllRoles();

// Xử lý AJAX request để lấy dữ liệu nhân viên
if (isset($_GET['ajax']) && isset($_GET['user_id'])) {
    $ajax_user_id = (int)$_GET['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('admin', 'staff')");
    $stmt->execute([$ajax_user_id]);
    $ajax_employee = $stmt->fetch();

    if ($ajax_employee) {
        $ajax_roles = getUserRoles($ajax_user_id);
        $roles_html = '';
        foreach ($all_roles as $role) {
            $checked = '';
            foreach ($ajax_roles as $er) {
                if ($er['id'] == $role['id']) {
                    $checked = 'checked';
                    break;
                }
            }
            $roles_html .= '
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="roles[]" value="' . $role['id'] . '" id="role_edit_' . $role['id'] . '" ' . $checked . '>
                    <label class="form-check-label" for="role_edit_' . $role['id'] . '">
                        <strong>' . e($role['display_name']) . '</strong>
                        <br><small class="text-muted">' . e($role['description'] ?: 'Không có mô tả') . '</small>
                    </label>
                </div>';
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'employee' => $ajax_employee,
            'roles_html' => $roles_html
        ]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false]);
        exit;
    }
}

$message = '';
$messageType = '';

// Xử lý các action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'add_employee') {
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        $roles = $_POST['roles'] ?? [];

        // Validation
        if (!$username || !$email || !$full_name || !$password) {
            $message = 'Vui lòng điền đầy đủ thông tin bắt buộc!';
            $messageType = 'warning';
        } elseif (strlen($password) < 6) {
            $message = 'Mật khẩu phải có ít nhất 6 ký tự!';
            $messageType = 'warning';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Email không hợp lệ!';
            $messageType = 'warning';
        } elseif (empty($roles)) {
            $message = 'Vui lòng chọn ít nhất một vai trò!';
            $messageType = 'warning';
        } else {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);

            if ($stmt->fetch()) {
                $message = 'Tên đăng nhập hoặc email đã tồn tại!';
                $messageType = 'danger';
            } else {
                try {
                    // Create user
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password, email, full_name, phone, address, role, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'staff', 1, NOW())
                    ");

                    if ($stmt->execute([$username, $hashed_password, $email, $full_name, $phone, $address])) {
                        $user_id = $pdo->lastInsertId();

                        // Assign roles to user
                        foreach ($roles as $role_id) {
                            assignRoleToUser($user_id, (int)$role_id);
                        }

                        $message = 'Thêm nhân viên thành công!';
                        $messageType = 'success';
                        logActivity('add_employee', 'users', "Thêm nhân viên: $full_name ($username)");
                    } else {
                        $message = 'Lỗi khi thêm nhân viên!';
                        $messageType = 'danger';
                    }
                } catch (Exception $e) {
                    $message = 'Lỗi hệ thống: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    elseif ($action == 'edit_employee') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        $roles = $_POST['roles'] ?? [];

        // Validation
        if (!$user_id || !$username || !$email || !$full_name) {
            $message = 'Vui lòng điền đầy đủ thông tin bắt buộc!';
            $messageType = 'warning';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Email không hợp lệ!';
            $messageType = 'warning';
        } elseif (empty($roles)) {
            $message = 'Vui lòng chọn ít nhất một vai trò!';
            $messageType = 'warning';
        } else {
            // Check if username or email already exists (excluding current user)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$username, $email, $user_id]);

            if ($stmt->fetch()) {
                $message = 'Tên đăng nhập hoặc email đã tồn tại!';
                $messageType = 'danger';
            } else {
                try {
                    // Update user information
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = ?, email = ?, full_name = ?, phone = ?, address = ?
                        WHERE id = ?
                    ");

                    if ($stmt->execute([$username, $email, $full_name, $phone, $address, $user_id])) {
                        // Update roles - delete old roles and add new ones
                        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$user_id]);

                        foreach ($roles as $role_id) {
                            assignRoleToUser($user_id, (int)$role_id);
                        }

                        $message = 'Cập nhật thông tin nhân viên thành công!';
                        $messageType = 'success';
                        logActivity('edit_employee', 'users', "Cập nhật nhân viên ID: $user_id");
                    } else {
                        $message = 'Lỗi khi cập nhật nhân viên!';
                        $messageType = 'danger';
                    }
                } catch (Exception $e) {
                    $message = 'Lỗi hệ thống: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    elseif ($action == 'toggle_status') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);

        if ($user_id > 0 && $user_id != 1) { // Không thể disable admin chính
            try {
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active, $user_id]);

                $status_text = $is_active ? 'kích hoạt' : 'vô hiệu hóa';
                $message = "Đã $status_text tài khoản nhân viên thành công!";
                $messageType = 'success';
                logActivity('toggle_employee_status', 'users', "$status_text nhân viên ID: $user_id");
            } catch (Exception $e) {
                $message = 'Lỗi khi cập nhật trạng thái!';
                $messageType = 'danger';
            }
        } else {
            $message = 'Không thể thay đổi trạng thái tài khoản này!';
            $messageType = 'warning';
        }
    }

    elseif ($action == 'delete_employee') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id > 0 && $user_id != 1) { // Không thể xóa admin chính
            try {
                // Delete user roles first
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$user_id]);

                // Delete user
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                if ($stmt->execute([$user_id])) {
                    $message = 'Xóa nhân viên thành công!';
                    $messageType = 'success';
                    logActivity('delete_employee', 'users', "Xóa nhân viên ID: $user_id");
                } else {
                    $message = 'Lỗi khi xóa nhân viên!';
                    $messageType = 'danger';
                }
            } catch (Exception $e) {
                $message = 'Lỗi khi xóa nhân viên: ' . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = 'Không thể xóa tài khoản này!';
            $messageType = 'warning';
        }
    }
}

// Lấy danh sách tất cả nhân viên
try {
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, u.full_name, u.phone, u.is_active, u.created_at,
               GROUP_CONCAT(r.display_name SEPARATOR ', ') as roles
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.role IN ('admin', 'staff')
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    $employees = $stmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// Lấy nhân viên được chọn để chỉnh sửa
$selected_employee = null;
$selected_employee_roles = [];
if (isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('admin', 'staff')");
        $stmt->execute([$user_id]);
        $selected_employee = $stmt->fetch();

        if ($selected_employee) {
            $selected_employee_roles = getUserRoles($user_id);
        }
    } catch (Exception $e) {
        $selected_employee = null;
    }
}

// Lấy tất cả roles
$all_roles = getAllRoles();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Nhân viên - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/index.css">
    <style>
        .employee-card {
            transition: all 0.3s ease;
            border-left: 4px solid #6f4e37;
        }

        .employee-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .employee-card.active {
            background: linear-gradient(135deg, #e8dcc8 0%, #f5f0e8 100%);
            border-left-color: #8b6b4d;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        .role-tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            background-color: #6f4e37;
            color: white;
            margin-right: 4px;
            margin-bottom: 4px;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .employee-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6f4e37 0%, #8b6b4d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <!-- Header -->
            <div class="header d-flex justify-content-between align-items-center p-4 bg-light border-bottom">
                <h2 class="m-0">
                    <i class="fas fa-users me-2"></i>Quản lý Nhân Viên
                </h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                    <i class="fas fa-user-plus me-2"></i>Thêm Nhân Viên
                </button>
            </div>

            <!-- Content -->
            <div class="p-4">
                <!-- Message -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Danh sách Nhân viên -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="m-0">
                                    <i class="fas fa-list me-2"></i>Danh Sách Nhân Viên
                                    <span class="badge bg-primary ms-2"><?php echo count($employees); ?></span>
                                </h5>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                <?php if (empty($employees)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">Chưa có nhân viên nào.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <a href="?user_id=<?php echo $emp['id']; ?>" class="text-decoration-none">
                                            <div class="employee-card p-3 mb-3 <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $emp['id']) ? 'active' : ''; ?>">
                                                <div class="d-flex align-items-center">
                                                    <div class="employee-avatar me-3">
                                                        <?php echo strtoupper(substr($emp['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1"><?php echo e($emp['full_name']); ?></h6>
                                                        <small class="text-muted d-block"><?php echo e($emp['username']); ?></small>
                                                        <small class="text-muted d-block"><?php echo e($emp['email']); ?></small>
                                                        <div class="mt-2">
                                                            <span class="status-badge <?php echo $emp['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                                <?php echo $emp['is_active'] ? 'Hoạt động' : 'Vô hiệu hóa'; ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($emp['roles']): ?>
                                                            <div class="mt-2">
                                                                <?php
                                                                $roles = explode(', ', $emp['roles']);
                                                                foreach (array_slice($roles, 0, 2) as $role):
                                                                ?>
                                                                    <span class="role-tag"><?php echo e($role); ?></span>
                                                                <?php endforeach; ?>
                                                                <?php if (count($roles) > 2): ?>
                                                                    <span class="role-tag">+<?php echo count($roles) - 2; ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Chi tiết Nhân viên -->
                    <div class="col-lg-8">
                        <?php if ($selected_employee): ?>
                            <div class="card">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h5 class="m-0">
                                        <i class="fas fa-user-edit me-2"></i><?php echo e($selected_employee['full_name']); ?>
                                    </h5>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary me-2" onclick="editEmployee(<?php echo $selected_employee['id']; ?>)">
                                            <i class="fas fa-edit me-1"></i>Sửa
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteEmployee(<?php echo $selected_employee['id']; ?>, '<?php echo e($selected_employee['full_name']); ?>')">
                                            <i class="fas fa-trash me-1"></i>Xóa
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Thông tin cơ bản -->
                                    <div class="form-section">
                                        <h6 class="mb-3">
                                            <i class="fas fa-info-circle me-2"></i>Thông Tin Cơ Bản
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Họ tên:</strong> <?php echo e($selected_employee['full_name']); ?></p>
                                                <p><strong>Tên đăng nhập:</strong> <?php echo e($selected_employee['username']); ?></p>
                                                <p><strong>Email:</strong> <?php echo e($selected_employee['email']); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Số điện thoại:</strong> <?php echo e($selected_employee['phone'] ?: 'Chưa cập nhật'); ?></p>
                                                <p><strong>Địa chỉ:</strong> <?php echo e($selected_employee['address'] ?: 'Chưa cập nhật'); ?></p>
                                                <p><strong>Trạng thái:</strong>
                                                    <span class="status-badge <?php echo $selected_employee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $selected_employee['is_active'] ? 'Hoạt động' : 'Vô hiệu hóa'; ?>
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                        <p><strong>Ngày tạo:</strong> <?php echo date('d/m/Y H:i', strtotime($selected_employee['created_at'])); ?></p>
                                    </div>

                                    <!-- Vai trò -->
                                    <div class="form-section">
                                        <h6 class="mb-3">
                                            <i class="fas fa-user-tag me-2"></i>Vai Trò & Quyền Hạn
                                        </h6>
                                        <?php if (empty($selected_employee_roles)): ?>
                                            <p class="text-muted">Chưa được gán vai trò nào.</p>
                                        <?php else: ?>
                                            <div class="mb-3">
                                                <?php foreach ($selected_employee_roles as $role): ?>
                                                    <span class="badge bg-primary me-2 mb-2">
                                                        <i class="fas fa-tag me-1"></i><?php echo e($role['display_name']); ?>
                                                    </span>
                                                    <small class="text-muted d-block ms-3"><?php echo e($role['description'] ?: 'Không có mô tả'); ?></small>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Hành động -->
                                    <div class="form-section">
                                        <h6 class="mb-3">
                                            <i class="fas fa-cogs me-2"></i>Hành Động
                                        </h6>
                                        <div class="d-flex gap-2">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $selected_employee['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $selected_employee['is_active'] ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-<?php echo $selected_employee['is_active'] ? 'warning' : 'success'; ?>">
                                                    <i class="fas fa-<?php echo $selected_employee['is_active'] ? 'lock' : 'unlock'; ?> me-2"></i>
                                                    <?php echo $selected_employee['is_active'] ? 'Vô hiệu hóa' : 'Kích hoạt'; ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-hand-point-left fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">Chọn nhân viên để xem chi tiết</h5>
                                    <p class="text-muted">Nhấp vào một nhân viên từ danh sách bên trái để xem và chỉnh sửa thông tin</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Thêm Nhân viên -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Thêm Nhân Viên Mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_employee">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Họ tên đầy đủ *</label>
                                    <input type="text" name="full_name" class="form-control" placeholder="VD: Nguyễn Văn A" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tên đăng nhập *</label>
                                    <input type="text" name="username" class="form-control" placeholder="VD: nguyen.van.a" required>
                                    <small class="text-muted">Không chứa khoảng trắng hoặc ký tự đặc biệt</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-control" placeholder="VD: nguyen@cafe.com" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="tel" name="phone" class="form-control" placeholder="VD: 0123456789">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Địa chỉ</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="VD: 123 Đường ABC, Quận 1, TP.HCM"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Mật khẩu *</label>
                            <input type="password" name="password" class="form-control" placeholder="Ít nhất 6 ký tự" required>
                            <small class="text-muted">Mật khẩu sẽ được mã hóa bảo mật</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Vai trò *</label>
                            <div class="border rounded p-3 bg-light">
                                <?php foreach ($all_roles as $role): ?>
                                    <div class="form-check mb-2">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="roles[]"
                                            value="<?php echo $role['id']; ?>"
                                            id="role_add_<?php echo $role['id']; ?>"
                                        >
                                        <label class="form-check-label" for="role_add_<?php echo $role['id']; ?>">
                                            <strong><?php echo e($role['display_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo e($role['description'] ?: 'Không có mô tả'); ?></small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Chọn ít nhất một vai trò cho nhân viên</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Hủy
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Thêm 
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Sửa Nhân viên -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Sửa Thông Tin Nhân Viên
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editEmployeeForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_employee">
                        <input type="hidden" name="user_id" id="edit_user_id">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Họ tên đầy đủ *</label>
                                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tên đăng nhập *</label>
                                    <input type="text" name="username" id="edit_username" class="form-control" required>
                                    <small class="text-muted">Không chứa khoảng trắng hoặc ký tự đặc biệt</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" id="edit_email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="tel" name="phone" id="edit_phone" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Địa chỉ</label>
                            <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Vai trò *</label>
                            <div class="border rounded p-3 bg-light" id="editRolesContainer">
                                <!-- Roles will be loaded here -->
                            </div>
                            <small class="text-muted">Chọn ít nhất một vai trò cho nhân viên</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Hủy
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Lưu Thay Đổi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 2 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 2000);

        function editEmployee(userId) {
            // Fetch employee data and populate edit modal
            fetch(`manage_employees.php?user_id=${userId}&ajax=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const emp = data.employee;
                        document.getElementById('edit_user_id').value = emp.id;
                        document.getElementById('edit_full_name').value = emp.full_name;
                        document.getElementById('edit_username').value = emp.username;
                        document.getElementById('edit_email').value = emp.email;
                        document.getElementById('edit_phone').value = emp.phone || '';
                        document.getElementById('edit_address').value = emp.address || '';

                        // Populate roles
                        const rolesContainer = document.getElementById('editRolesContainer');
                        rolesContainer.innerHTML = data.roles_html;

                        // Show modal
                        new bootstrap.Modal(document.getElementById('editEmployeeModal')).show();
                    } else {
                        alert('Lỗi khi tải dữ liệu nhân viên!');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Lỗi hệ thống!');
                });
        }

        function deleteEmployee(userId, fullName) {
            if (confirm(`Bạn có chắc chắn muốn xóa nhân viên "${fullName}"?\n\nHành động này không thể hoàn tác!`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_employee">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
