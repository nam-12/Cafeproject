<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem danh mục
if (!hasPermission('view_categories')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

if (isset($_GET['delete'])) {
    // Kiểm tra quyền xóa danh mục
    if (!hasPermission('delete_category')) {
        $_SESSION['error'] = 'Bạn không có quyền xóa danh mục!';
        header('Location: categories.php');
        exit;
    }
    $id = (int) $_GET['delete'];

    try {
        // Kiểm tra xem danh mục có sản phẩm không
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            $_SESSION['error'] = "Không thể xóa danh mục này vì còn {$result['count']} sản phẩm đang sử dụng!";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Đã xóa danh mục thành công!";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi khi xóa: " . $e->getMessage();
    }

    header('Location: categories.php');
    exit;
}


// Lấy danh sách danh mục với số lượng sản phẩm
 $categories = $pdo->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id
    ORDER BY c.id ASC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Danh mục</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/categories.css">

</head>
<body class="admin-layout">
    <!-- Sidebar -->
   
        <?php include 'sidebar.php'; ?>
    
    
    <!-- Main content -->
    <div class="content-wrapper">
        <div class="page-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-sm mobile-menu-btn me-3" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <h1><i class="fas fa-folder me-2"></i>Danh mục</h1>
            </div>
            <div class="d-flex gap-2">
                <?php if (hasPermission('create_category')): ?>
                <a href="category_form.php" class="btn btn-action btn-primary">
                    <i class="fas fa-plus me-2"></i>Thêm danh mục
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div id="alert-success" class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div id="alert-error" class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">ID</th>
                                <th width="25%">Tên danh mục</th>
                                <th width="40%">Mô tả</th>
                                <th width="10%" class="text-center">Sản phẩm</th>
                                <th width="10%">Ngày tạo</th>
                                <th width="10%" class="text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                        Chưa có danh mục nào
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><?php echo $cat['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $desc = htmlspecialchars($cat['description']);
                                            echo mb_strlen($desc) > 100 ? mb_substr($desc, 0, 100) . '...' : $desc;
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info badge-count">
                                                <?php echo $cat['product_count']; ?> sản phẩm
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y', strtotime($cat['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <div class="table-actions">
                                                <?php if (hasPermission('edit_category')): ?>
                                                <a href="category_form.php?id=<?php echo $cat['id']; ?>"
                                                    class="btn btn-sm btn-outline-primary" title="Chỉnh sửa">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php endif; ?>

                                                <?php if (hasPermission('delete_category') && $cat['product_count'] == 0): ?>
                                                <a href="?delete=<?php echo $cat['id']; ?>"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Bạn có chắc muốn xóa danh mục này?')"
                                                        title="Xóa">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php elseif (hasPermission('delete_category')): ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled
                                                        title="Không thể xóa vì còn sản phẩm">
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

                <?php if (!empty($categories)): ?>
                    <div class="mt-3 text-muted">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            Tổng số: <strong><?php echo count($categories); ?></strong> danh mục
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            
            // Mobile menu toggle
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 992 && 
                    !sidebar.contains(event.target) && 
                    !mobileMenuBtn.contains(event.target) &&
                    sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 992) {
                    sidebar.classList.remove('show');
                }
            });
            
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
        });
    </script>
</body>
</html>