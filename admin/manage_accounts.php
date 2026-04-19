<?php
require_once '../config/init.php';

// Kiểm tra quyền - chỉ admin mới có thể quản lý tài khoản
requirePermission(['manage_roles', 'create_user', 'edit_user']);

$message = '';
$messageType = '';

// Xử lý tài khoản
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Xử lý cập nhật thông tin tài khoản
    if ($action == 'update_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $role = sanitizeInput($_POST['role'] ?? 'customer');

        if ($user_id > 0 && !$username || !$email || !$full_name) {
            $message = 'Vui lòng điền đầy đủ thông tin!';
            $messageType = 'warning';
        } else if (!in_array($role, ['admin', 'staff', 'customer'])) {
            $message = 'Vai trò không hợp lệ!';
            $messageType = 'warning';
        } else {
            // Check if username or email already exists (excluding current user)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$username, $email, $user_id]);

            if ($stmt->fetch()) {
                $message = 'Tên đăng nhập hoặc email đã tồn tại!';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users SET username = ?, email = ?, full_name = ?, role = ? WHERE id = ?
                ");

                if ($stmt->execute([$username, $email, $full_name, $role, $user_id])) {
                    $message = 'Cập nhật tài khoản thành công!';
                    $messageType = 'success';
                    logActivity('update_user', 'users', "Cập nhật tài khoản ID: $user_id");
                } else {
                    $message = 'Lỗi khi cập nhật tài khoản!';
                    $messageType = 'danger';
                }
            }
        }
    }

    // Xử lý khóa/mở tài khoản
    elseif ($action == 'toggle_user_status') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);

        if ($user_id > 0 && $user_id != 1) { // Không thể disable admin đầu tiên
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$is_active, $user_id]);

            $status_text = $is_active ? 'mở' : 'khóa';
            $message = "Đã $status_text tài khoản thành công!";
            $messageType = 'success';
            logActivity('toggle_user_status', 'users', "Cập nhật trạng thái tài khoản ID: $user_id - $status_text");
        } else {
            $message = 'Không thể thay đổi trạng thái tài khoản này!';
            $messageType = 'warning';
        }
    }

    // Xử lý xóa tài khoản
    elseif ($action == 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id > 0 && $user_id != 1) { // Không thể xóa admin đầu tiên
            // Kiểm tra xem user có đơn hàng nào không
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $order_count = $stmt->fetch()['count'];

            if ($order_count > 0) {
                $message = 'Không thể xóa tài khoản này vì có đơn hàng liên quan!';
                $messageType = 'warning';
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                if ($stmt->execute([$user_id])) {
                    $message = 'Xóa tài khoản thành công!';
                    $messageType = 'success';
                    logActivity('delete_user', 'users', "Xóa tài khoản ID: $user_id");
                } else {
                    $message = 'Lỗi khi xóa tài khoản!';
                    $messageType = 'danger';
                }
            }
        } else {
            $message = 'Không thể xóa tài khoản này!';
            $messageType = 'warning';
        }
    }
}

// Lấy danh sách tất cả tài khoản
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$query = "
    SELECT id, username, email, full_name, role, is_active, created_at
    FROM users
    WHERE 1=1
";

$params = [];

if ($role_filter) {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== '') {
    $query .= " AND is_active = ?";
    $params[] = (int)$status_filter;
}

if ($search) {
    $query .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

// Lấy tài khoản được chọn để chỉnh sửa
$selected_account = null;
if (isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $selected_account = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Tài khoản - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/index.css">
    <style>
        .account-item {
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid #6f4e37;
            background: #fff;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .account-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .account-item.active {
            background: #e8dcc8;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85rem;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .role-admin {
            background-color: #dc3545;
            color: white;
        }

        .role-staff {
            background-color: #ffc107;
            color: black;
        }

        .role-customer {
            background-color: #17a2b8;
            color: white;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm mobile-menu-btn me-3" id="mobileMenuBtn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2 class="m-0">
                        <i class="fas fa-users-cog me-2"></i>Quản lý Tài khoản
                    </h2>
                </div>

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

                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Tìm kiếm</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tên đăng nhập, email, họ tên">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Vai trò</label>
                            <select class="form-select" name="role">
                                <option value="">Tất cả</option>
                                <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Nhân viên</option>
                                <option value="customer" <?php echo $role_filter == 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-select" name="status">
                                <option value="">Tất cả</option>
                                <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Khóa</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-outline-primary me-2">
                                <i class="fas fa-search"></i> Lọc
                            </button>
                            <a href="manage_accounts.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Xóa lọc
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Accounts List -->
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Danh sách tài khoản (<?php echo count($accounts); ?>)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($accounts)): ?>
                                <p class="text-muted">Không có tài khoản nào.</p>
                                <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($accounts as $account): ?>
                                    <div class="account-item d-flex justify-content-between align-items-center"
                                         onclick="selectAccount(<?php echo $account['id']; ?>)">
                                        <div>
                                            <strong><?php echo htmlspecialchars($account['username']); ?></strong>
                                            <span class="text-muted">(<?php echo htmlspecialchars($account['email']); ?>)</span><br>
                                            <small><?php echo htmlspecialchars($account['full_name']); ?></small>
                                            <div class="mt-1">
                                                <span class="role-badge role-<?php echo $account['role']; ?>">
                                                    <?php echo ucfirst($account['role']); ?>
                                                </span>
                                                <span class="status-badge status-<?php echo $account['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $account['is_active'] ? 'Hoạt động' : 'Khóa'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($account['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Chi tiết tài khoản</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($selected_account): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?php echo $selected_account['id']; ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Tên đăng nhập</label>
                                        <input type="text" class="form-control" name="username"
                                               value="<?php echo htmlspecialchars($selected_account['username']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="email"
                                               value="<?php echo htmlspecialchars($selected_account['email']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Họ tên</label>
                                        <input type="text" class="form-control" name="full_name"
                                               value="<?php echo htmlspecialchars($selected_account['full_name']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Vai trò</label>
                                        <select class="form-select" name="role" required>
                                            <option value="customer" <?php echo $selected_account['role'] == 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
                                            <option value="staff" <?php echo $selected_account['role'] == 'staff' ? 'selected' : ''; ?>>Nhân viên</option>
                                            <option value="admin" <?php echo $selected_account['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-save me-2"></i>Cập nhật
                                        </button>

                                        <button type="button" class="btn btn-warning"
                                                onclick="toggleStatus(<?php echo $selected_account['id']; ?>, <?php echo $selected_account['is_active'] ? 0 : 1; ?>)">
                                            <i class="fas fa-<?php echo $selected_account['is_active'] ? 'lock' : 'unlock'; ?> me-2"></i>
                                            <?php echo $selected_account['is_active'] ? 'Khóa' : 'Mở'; ?> tài khoản
                                        </button>

                                        <?php if ($selected_account['id'] != 1): ?>
                                        <button type="button" class="btn btn-danger"
                                                onclick="deleteAccount(<?php echo $selected_account['id']; ?>)">
                                            <i class="fas fa-trash me-2"></i>Xóa tài khoản
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                                <?php else: ?>
                                <p class="text-muted">Chọn một tài khoản để xem chi tiết.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectAccount(userId) {
            window.location.href = 'manage_accounts.php?user_id=' + userId;
        }

        function toggleStatus(userId, isActive) {
            if (confirm('Bạn có chắc chắn muốn ' + (isActive ? 'mở' : 'khóa') + ' tài khoản này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_user_status">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="is_active" value="${isActive}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteAccount(userId) {
            if (confirm('Bạn có chắc chắn muốn xóa tài khoản này? Hành động này không thể hoàn tác!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Auto-hide alerts after 2 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 2000);
    </script>
</body>
</html>