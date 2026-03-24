<?php
require_once '../config/init.php';

// Kiểm tra quyền
requirePermission('manage_roles');

$message = '';
$messageType = '';

// ================== XỬ LÝ FORM ==================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ===== THÊM ROLE =====
    if ($action == 'add_role') {
        $name = sanitizeInput($_POST['name'] ?? '');
        $display_name = sanitizeInput($_POST['display_name'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');

        if ($name && $display_name) {

            $check = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
            $check->execute([$name]);

            if ($check->fetch()) {
                $message = 'Tên role đã tồn tại!';
                $messageType = 'warning';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO roles (name, display_name, description)
                    VALUES (?, ?, ?)
                ");

                if ($stmt->execute([$name, $display_name, $description])) {
                    $message = 'Thêm vai trò thành công!';
                    $messageType = 'success';
                    logActivity('add_role', 'roles', "Thêm role: $display_name");
                } else {
                    $message = 'Lỗi khi thêm vai trò!';
                    $messageType = 'danger';
                }
            }
        } else {
            $message = 'Vui lòng điền đầy đủ thông tin!';
            $messageType = 'warning';
        }
    }

    // ===== CẬP NHẬT PERMISSIONS =====
    elseif ($action == 'update_role_permissions') {
        $role_id = (int) ($_POST['role_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];

        if ($role_id > 0) {
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);

            $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($permissions as $perm_id) {
                $stmt->execute([$role_id, (int) $perm_id]);
            }

            $message = 'Cập nhật quyền hạn thành công!';
            $messageType = 'success';
            logActivity('update_role_permissions', 'roles', "Role ID: $role_id");
        }
    }

    // ===== XÓA ROLE =====
    elseif ($action == 'delete_role') {
        $role_id = (int) ($_POST['role_id'] ?? 0);

        if ($role_id > 0) {

            // Không cho xóa admin
            $stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $stmt->execute([$role_id]);
            $role = $stmt->fetch();

            if ($role && $role['name'] == 'admin') {
                $message = 'Không thể xóa role admin!';
                $messageType = 'danger';
            } else {

                // Check role đang dùng
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
                $stmt->execute([$role_id]);

                if ($stmt->fetchColumn() > 0) {
                    $message = 'Role đang được sử dụng, không thể xóa!';
                    $messageType = 'warning';
                } else {

                    // Xóa permissions
                    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")
                        ->execute([$role_id]);

                    // Xóa role
                    $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
                    if ($stmt->execute([$role_id])) {
                        $message = 'Xóa vai trò thành công!';
                        $messageType = 'success';
                        logActivity('delete_role', 'roles', "Role ID: $role_id");
                    } else {
                        $message = 'Lỗi khi xóa vai trò!';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

// ================== DATA ==================
$roles = getAllRoles();

$selected_role = null;
$selected_role_permissions = [];

if (isset($_GET['role_id'])) {
    $role_id = (int) $_GET['role_id'];

    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $selected_role = $stmt->fetch();

    if ($selected_role) {
        $stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$role_id]);
        $selected_role_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$all_permissions = getAllPermissions();

$permissions_by_module = [];
foreach ($all_permissions as $perm) {
    $module = $perm['module'] ?? 'khác';
    $permissions_by_module[$module][] = $perm;
}

$module_names = [
    'dashboard' => 'Bảng điều khiển',
    'products' => 'Sản phẩm',
    'categories' => 'Danh mục',
    'orders' => 'Đơn hàng',
    'inventory' => 'Kho hàng',
    'coupons' => 'Mã giảm giá',
    'reviews' => 'Đánh giá',
    'reports' => 'Báo cáo',
    'users' => 'Người dùng',
    'settings' => 'Cài đặt'
];
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Roles & Permissions - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/index.css">
    <style>
        .permission-group {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .permission-group h6 {
            color: #6f4e37;
            font-weight: 600;
            margin-bottom: 12px;
            border-left: 3px solid #6f4e37;
            padding-left: 10px;
        }

        .permission-check {
            margin-bottom: 8px;
        }

        .permission-check label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .role-item {
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid #6f4e37;
            background: #fff;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .role-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .role-item.active {
            background: #e8dcc8;
        }

        .btn-role {
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
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
                    <i class="fas fa-shield-alt me-2"></i>Quản lý Roles & Permissions
                </h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                    <i class="fas fa-plus me-2"></i>Thêm Vai Trò
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
                    <!-- Danh sách Roles -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="m-0">
                                    <i class="fas fa-list me-2"></i>Danh Sách Vai Trò
                                </h5>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                <?php if (empty($roles)): ?>
                                    <p class="text-muted">Chưa có vai trò nào.</p>
                                <?php else: ?>
                                    <?php foreach ($roles as $role): ?>
                                        <div
                                            class="role-item <?php echo (isset($_GET['role_id']) && $_GET['role_id'] == $role['id']) ? 'active' : ''; ?>">

                                            <div class="d-flex justify-content-between align-items-start">

                                                <!-- CLICK CHỌN ROLE -->
                                                <a href="?role_id=<?php echo $role['id']; ?>"
                                                    class="text-decoration-none flex-grow-1">
                                                    <h6 class="mb-1"><?php echo e($role['display_name']); ?></h6>
                                                    <small class="text-muted"><?php echo e($role['name']); ?></small>

                                                    <?php if ($role['description']): ?>
                                                        <p class="mb-0 small mt-1 text-muted">
                                                            <?php echo e($role['description']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </a>

                                                <!-- NÚT XÓA -->
                                                <?php if ($role['name'] != 'admin'): ?>
                                                    <form method="POST"
                                                        onsubmit="return confirm('Bạn có chắc muốn xóa vai trò này?');">
                                                        <input type="hidden" name="action" value="delete_role">
                                                        <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                                                        <button class="btn btn-sm btn-danger btn-role">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Chỉnh sửa Permissions -->
                    <div class="col-lg-8">
                        <?php if ($selected_role): ?>
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="m-0">
                                        <i class="fas fa-lock me-2"></i>Quyền Hạn cho:
                                        <?php echo e($selected_role['display_name']); ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_role_permissions">
                                        <input type="hidden" name="role_id" value="<?php echo $selected_role['id']; ?>">

                                        <!-- Group permissions by module -->
                                        <?php foreach ($permissions_by_module as $module => $perms): ?>
                                            <div class="permission-group">
                                                <h6><?php echo $module_names[$module] ?? ucfirst($module); ?></h6>
                                                <div class="row">
                                                    <?php foreach ($perms as $perm): ?>
                                                        <div class="col-md-6 permission-check">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="permissions[]"
                                                                    value="<?php echo $perm['id']; ?>"
                                                                    id="perm_<?php echo $perm['id']; ?>" <?php echo in_array($perm['id'], $selected_role_permissions) ? 'checked' : ''; ?>
                                                                       
                                                                       ?>
                                                                <label class="form-check-label"
                                                                    for="perm_<?php echo $perm['id']; ?>">
                                                                    <?php echo e($perm['display_name']); ?>
                                                                </label>
                                                            </div>
                                                            <small
                                                                class="text-muted d-block ms-3"><?php echo e($perm['description'] ?? ''); ?></small>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="mt-4">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-save me-2"></i>Lưu Quyền Hạn
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-hand-point-left fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Chọn một vai trò từ danh sách bên trái để quản lý quyền hạn</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Thêm Role -->
    <div class="modal fade" id="addRoleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Vai Trò Mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_role">

                        <div class="mb-3">
                            <label class="form-label">Mã Vai Trò *</label>
                            <input type="text" name="name" class="form-control" placeholder="VD: supervisor" required>
                            <small class="text-muted">Mã dùng trong code, không có khoảng trắng</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Tên Vai Trò *</label>
                            <input type="text" name="display_name" class="form-control" placeholder="VD: Giám sát viên"
                                required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Mô Tả</label>
                            <textarea name="description" class="form-control" rows="3"
                                placeholder="Mô tả chi tiết về vai trò này"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary">Thêm Vai Trò</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tự động ẩn alert sau 2 giây
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert.alert-dismissible');
            alerts.forEach(alertBox => {
                alertBox.classList.remove('show');
                alertBox.classList.add('hide');
                setTimeout(() => {
                    alertBox.remove();
                }, 500);
            });
        }, 2000);
    </script>
</body>

</html>