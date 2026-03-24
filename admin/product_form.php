<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền
$edit_mode = isset($_GET['id']);
if ($edit_mode) {
    requirePermission('edit_product');
} else {
    requirePermission('create_product');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check') {
    header('Content-Type: application/json');
    
    $name = isset($_GET['name']) ? trim($_GET['name']) : '';
    $category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
    
    if (empty($name)) {
        echo json_encode(['exists' => false]);
        exit;
    }
    
    try {
        $sql = "SELECT id, name FROM products WHERE LOWER(name) = LOWER(?)";
        $params = [$name];
        
        if ($category > 0) {
            $sql .= " AND category_id = ?";
            $params[] = $category;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $product = $stmt->fetch();
        
        echo json_encode([
            'exists' => $product ? true : false,
            'product' => $product ?: null
        ]);
    } catch (PDOException $e) {
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

 $edit_mode = false;
 $product = [
    'name' => '',
    'category_id' => '',
    'description' => '',
    'ingredients' => '', 
    'calories' => '',
    'price' => '',
    'image' => '',
    'status' => 'active'
];

// Chế độ chỉnh sửa
if (isset($_GET['id'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error'] = "Không tìm thấy sản phẩm!";
        header('Location: products.php');
        exit;
    }
}

// Xử lý form submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $description = trim($_POST['description']);
    $ingredients = trim($_POST['ingredients']); 
    $calories = !empty($_POST['calories']) ? (int)$_POST['calories'] : null; 
    $price = (float)$_POST['price'];
    $status = $_POST['status'];
    $initial_quantity = isset($_POST['initial_quantity']) ? (int)$_POST['initial_quantity'] : 0;
    $min_quantity = isset($_POST['min_quantity']) ? (int)$_POST['min_quantity'] : 10;

    // Xử lý upload ảnh
    $image = '';
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (in_array($_FILES['image_file']['type'], $allowed_types)) {
            $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
            $new_name = uniqid('product_') . "." . $ext;
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $target_file = $upload_dir . $new_name;
            move_uploaded_file($_FILES['image_file']['tmp_name'], $target_file);
            $image = $target_file;
        } else {
            $_SESSION['error'] = "Chỉ cho phép file JPG, PNG, GIF!";
        }
    } else {
        if ($edit_mode) {
            $image = $product['image'];
        } else {
            $image = '';
        }
    }

    try {
        if ($edit_mode) {
            // Cập nhật sản phẩm
            $stmt = $pdo->prepare("UPDATE products 
                SET name=?, category_id=?, description=?, ingredients=?, calories=?, price=?, image=?, status=? 
                WHERE id=?");
            $stmt->execute([
                $name, $category_id, $description, $ingredients, $calories, $price, $image, $status, $_GET['id']
            ]);
            $_SESSION['success'] = "Đã cập nhật sản phẩm thành công!";
        } else {
            // Kiểm tra sản phẩm đã tồn tại chưa
            $stmt = $pdo->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND category_id = ?");
            $stmt->execute([$name, $category_id]);
            $existing_product = $stmt->fetch();
            
            if ($existing_product) {
                // Sản phẩm đã tồn tại - cập nhật số lượng trong kho
                $product_id = $existing_product['id'];
                
                // Kiểm tra xem sản phẩm đã có trong kho chưa
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id = ?");
                $stmt->execute([$product_id]);
                $inventory = $stmt->fetch();
                
                if ($inventory) {
                    // Cập nhật số lượng
                    $new_quantity = $inventory['quantity'] + $initial_quantity;
                    $stmt = $pdo->prepare("UPDATE inventory SET quantity = ?, min_quantity = ? WHERE product_id = ?");
                    $stmt->execute([$new_quantity, $min_quantity, $product_id]);
                } else {
                    // Thêm mới vào kho
                    $stmt = $pdo->prepare("INSERT INTO inventory (product_id, quantity, min_quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$product_id, $initial_quantity, $min_quantity]);
                }
                
                $_SESSION['warning'] = "Sản phẩm '{$name}' đã tồn tại! Đã tăng số lượng trong kho thêm {$initial_quantity} sản phẩm.";
            } else {
                // Thêm sản phẩm mới
                $stmt = $pdo->prepare("INSERT INTO products 
                    (name, category_id, description, ingredients, calories, price, image, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $name, $category_id, $description, $ingredients, $calories, $price, $image, $status
                ]);
                $product_id = $pdo->lastInsertId();
                
                // Thêm vào kho
                $stmt = $pdo->prepare("INSERT INTO inventory (product_id, quantity, min_quantity) VALUES (?, ?, ?)");
                $stmt->execute([$product_id, $initial_quantity, $min_quantity]);
                
                $_SESSION['success'] = "Đã thêm sản phẩm mới thành công!";
            }
        }
        
        header('Location: products.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }
}

// Lấy danh mục
 $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Chỉnh sửa' : 'Thêm'; ?> Sản phẩm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/cssad/product_form.css">
</head>
<body>
    <div class="admin-layout">
        <!-- Mobile Menu Button -->
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Sidebar -->
        
            <?php include 'sidebar.php'; ?>
       

        <!-- Main Content -->
        <div class="content-wrapper">
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas <?php echo $edit_mode ? 'fa-edit' : 'fa-plus'; ?> me-2"></i>
                        <?php echo $edit_mode ? 'Chỉnh sửa' : 'Thêm'; ?> Sản phẩm
                    </h1>
                    <p class="page-subtitle">
                        <?php echo $edit_mode ? 'Cập nhật thông tin sản phẩm' : 'Thêm sản phẩm mới vào hệ thống'; ?>
                    </p>
                </div>
                <a href="products.php" class="btn btn-secondary btn-action">
                    <i class="fas fa-arrow-left me-2"></i> Quay lại
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="content-card">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="productForm">
                        <div class="form-section">
                            <h5 class="form-section-title">
                                <i class="fas fa-info-circle me-2"></i>Thông tin cơ bản
                            </h5>
                            
                            <!-- Hàng 1: Tên sản phẩm và Danh mục -->
                            <div class="form-row">
                                <div class="form-col">
                                    <label for="product_name" class="form-label required-field">Tên sản phẩm</label>
                                    <input type="text" name="name" id="product_name" class="form-control" 
                                        value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>" required>
                                    <div id="name_warning" class="warning-message">
                                        <i class="fas fa-exclamation-triangle"></i> Sản phẩm này có thể đã tồn tại. Nếu thêm, số lượng sẽ được cộng dồn.
                                    </div>
                                </div>
                                <div class="form-col">
                                    <label for="product_category" class="form-label required-field">Danh mục</label>
                                    <select name="category_id" id="product_category" class="form-select" required>
                                        <option value="">-- Chọn danh mục --</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name'] ?? ''); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Hàng 2: Mô tả và Thành phần -->
                            <div class="form-row">
                                <div class="form-col">
                                    <label for="product_description" class="form-label">Mô tả</label>
                                    <textarea name="description" id="product_description" class="form-control" rows="3"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-col">
                                    <label for="product_ingredients" class="form-label">Thành phần</label>
                                    <textarea name="ingredients" id="product_ingredients" class="form-control" rows="3"><?php echo htmlspecialchars($product['ingredients'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5 class="form-section-title">
                                <i class="fas fa-tag me-2"></i>Giá và Số lượng
                            </h5>
                            
                            <!-- Hàng 3: Giá bán, Calo, Số lượng ban đầu -->
                            <div class="form-row three-columns">
                                <div class="form-col">
                                    <label for="product_price" class="form-label required-field">Giá bán (VNĐ)</label>
                                    <input type="number" name="price" id="product_price" class="form-control" 
                                        step="1000" value="<?php echo htmlspecialchars($product['price'] ?? ''); ?>" required>
                                </div>

                                <div class="form-col">
                                    <label for="product_calories" class="form-label">Calo (kcal)</label>
                                    <input type="number" name="calories" id="product_calories" class="form-control" 
                                        value="<?php echo htmlspecialchars($product['calories'] ?? ''); ?>" min="0">
                                </div>

                                <?php if (!$edit_mode): ?>
                                <div class="form-col">
                                    <label for="initial_quantity" class="form-label">Số lượng ban đầu</label>
                                    <input type="number" name="initial_quantity" id="initial_quantity" class="form-control" value="0" min="0">
                                </div>
                                <div class="form-col">
                                    <label for="min_quantity" class="form-label">Ngưỡng tối thiểu</label>
                                    <input type="number" name="min_quantity" id="min_quantity" class="form-control" value="10" min="1">
                                    <small class="text-muted">Khi tồn kho ≤ mức này, sản phẩm sẽ được đánh dấu cần nhập hàng</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5 class="form-section-title">
                                <i class="fas fa-image me-2"></i>Hình ảnh
                            </h5>
                            
                            <div class="mb-3">
                                <div class="file-upload-container">
                                    <input type="file" name="image_file" id="image_file" accept="image/jpeg,image/png,image/gif">
                                    <label for="image_file" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt me-2"></i>
                                        Chọn file ảnh (JPG, PNG, GIF)
                                    </label>
                                </div>
                                <small class="text-muted">Kích thước tối đa: 5MB</small>
                            </div>

                            <?php if (!empty($product['image'])): ?>
                            <div class="mb-3">
                                <label class="form-label">Ảnh hiện tại</label>
                                <div class="image-preview-wrapper">
                                    <img src="<?php echo htmlspecialchars($product['image'] ?? ''); ?>" alt="Ảnh sản phẩm" class="image-preview">
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-section">
                            <h5 class="form-section-title">
                                <i class="fas fa-cog me-2"></i>Cài đặt
                            </h5>
                            
                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <div class="status-switch">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="status" id="status_active" 
                                            value="active" <?php echo ($product['status'] ?? '') == 'active' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status_active">
                                            <i class="fas fa-check-circle text-success me-1"></i> Hoạt động
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="status" id="status_inactive" 
                                            value="inactive" <?php echo ($product['status'] ?? '') == 'inactive' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status_inactive">
                                            <i class="fas fa-pause-circle text-warning me-1"></i> Tạm ngưng
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group-custom">
                            <a href="products.php" class="btn btn-secondary btn-action">
                                <i class="fas fa-times me-2"></i> Hủy
                            </a>
                            <button type="submit" class="btn btn-primary btn-action">
                                <i class="fas fa-save me-2"></i> <?php echo $edit_mode ? 'Cập nhật' : 'Thêm mới'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebarWrapper').classList.toggle('show');
        });
        
        // Hiển thị tên file đã chọn
        document.getElementById('image_file').addEventListener('change', function() {
            const fileName = this.files[0]?.name || '';
            const label = document.querySelector('.file-upload-label');
            if (fileName) {
                label.innerHTML = `<i class="fas fa-image me-2"></i> ${fileName}`;
            } else {
                label.innerHTML = `<i class="fas fa-cloud-upload-alt me-2"></i> Chọn file ảnh (JPG, PNG, GIF)`;
            }
        });

        <?php if (!$edit_mode): ?>
        let checkTimeout;
        const productNameInput = document.getElementById('product_name');
        const productCategorySelect = document.getElementById('product_category');

        if (productNameInput) {
            productNameInput.addEventListener('input', checkProductExists);
        }

        if (productCategorySelect) {
            productCategorySelect.addEventListener('change', checkProductExists);
        }

        function checkProductExists() {
            clearTimeout(checkTimeout);
            const name = productNameInput.value.trim();
            const categoryId = productCategorySelect.value;
            
            if (name.length < 2) {
                document.getElementById('name_warning').style.display = 'none';
                return;
            }
            
            checkTimeout = setTimeout(() => {
                fetch('?ajax=check&name=' + encodeURIComponent(name) + '&category=' + categoryId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            document.getElementById('name_warning').style.display = 'block';
                        } else {
                            document.getElementById('name_warning').style.display = 'none';
                        }
                    })
                    .catch(error => console.error('Error:', error));
            }, 500);
        }
        <?php endif; ?>

        // Tự động ẩn thông báo sau 3 giây
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 3000);
    </script>
</body>
</html>