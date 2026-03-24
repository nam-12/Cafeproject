<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền
$edit_mode = isset($_GET['id']);
if ($edit_mode) {
    requirePermission('edit_category');
} else {
    requirePermission('create_category');
}

 $edit_mode = false;
 $category = [
    'name' => '',
    'description' => ''
];

// Chỉnh sửa
if (isset($_GET['id'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $category = $stmt->fetch();
    
    if (!$category) {
        $_SESSION['error'] = "Không tìm thấy danh mục!";
        header('Location: categories.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);

    try {
        if ($edit_mode) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?");
            $stmt->execute([$name, $_GET['id']]);
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = "Tên danh mục đã tồn tại!";
            } else {
                $stmt = $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
                $stmt->execute([$name, $description, $_GET['id']]);
                $_SESSION['success'] = "Đã cập nhật danh mục thành công!";
                header('Location: categories.php');
                exit;
            }
        } else {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$name]);
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = "Tên danh mục đã tồn tại!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
                $_SESSION['success'] = "Đã thêm danh mục mới thành công!";
                header('Location: categories.php');
                exit;
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Chỉnh sửa' : 'Thêm'; ?> Danh mục</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #d8e2f5;
            --primary-light: #eef2fb;
            --primary-dark: #94a3b8;
            --secondary-color: #fef3c7;
            --success-color: #dcfce7;
            --danger-color: #fee2e2;
            --warning-color: #fef3c7;
            --light-bg: #f9fafb;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --border-dark: #94a3b8;
            --text-primary: #334155;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --sidebar-width: 280px;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-bg);
            color: var(--text-primary);
            overflow-x: hidden;
        }

        .admin-layout {
            background: linear-gradient(135deg, var(--light-bg) 0%, var(--primary-light) 100%);
            min-height: 100vh;
            display: flex;
        }

        .sidebar-wrapper {
            width: var(--sidebar-width);
            position: relative;
            z-index: 100;
            flex-shrink: 0;
            border-right: 2px solid var(--border-dark);
            background: var(--card-bg);
            box-shadow: var(--shadow-lg);
        }

        .content-wrapper {
            flex-grow: 1;
            padding: 2rem;
            width: calc(100% - var(--sidebar-width));
            overflow-y: auto;
        }

        .page-header {
            margin-bottom: 2rem;
            background: var(--card-bg);
            padding: 1.5rem 2rem;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 2px solid var(--border-dark);
        }

        .page-header h1 {
            color: var(--text-primary);
            font-weight: 700;
            margin: 0;
            font-size: 1.75rem;
        }

        .content-card {
            border: 2px solid var(--border-dark);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
            background: var(--card-bg);
        }

        .content-card:hover {
            box-shadow: var(--shadow-xl);
        }

        .content-card .card-body {
            padding: 2rem;
            background: var(--card-bg);
        }

        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(216, 226, 245, 0.5);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .btn-action {
            padding: 0.6rem 1.5rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: 2px solid var(--border-dark);
            box-shadow: var(--shadow-md);
        }

        .btn-action:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Responsive cho mobile */
        @media (max-width: 991.98px) {
            .sidebar-wrapper {
                position: fixed;
                height: 100vh;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar-wrapper.show { transform: translateX(0); }
            .content-wrapper { width: 100%; margin-left: 0; padding: 1.5rem; }
            .mobile-menu-btn { display: block; }
        }
        
        @media (min-width: 992px) {
            .mobile-menu-btn { display: none; }
        }
    </style>
</head>
<body class="admin-layout">
    <!-- Sidebar -->
    <div class="sidebar-wrapper" id="sidebar">
        <?php include 'sidebar.php'; ?>
    </div>
    
    <!-- Main content -->
    <div class="content-wrapper">
        <div class="page-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-sm mobile-menu-btn me-3" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <h1>
                    <i class="fas <?php echo $edit_mode ? 'fa-edit' : 'fa-plus'; ?> me-2"></i>
                    <?php echo $edit_mode ? 'Chỉnh sửa' : 'Thêm'; ?> Danh mục
                </h1>
            </div>
            <a href="categories.php" class="btn btn-action btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Quay lại
            </a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
        <div id="alert-message" class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Tên danh mục <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" 
                            value="<?php echo htmlspecialchars($category['name']); ?>" 
                            required maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($category['description']); ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="categories.php" class="btn btn-action btn-secondary">
                            <i class="fas fa-times me-2"></i>Hủy
                        </a>
                        <button type="submit" class="btn btn-action btn-primary">
                            <i class="fas fa-save me-2"></i><?php echo $edit_mode ? 'Cập nhật' : 'Thêm mới'; ?>
                        </button>
                    </div>
                </form>
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
            
            // Auto hide alerts after 3 seconds
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