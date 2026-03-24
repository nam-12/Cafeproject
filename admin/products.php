<?php
require_once '../config/init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem sản phẩm
if (!hasPermission('view_products')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

// Handle product deletion
if (isset($_GET['delete_id'])) {
    // Kiểm tra quyền xóa sản phẩm
    if (!hasPermission('delete_product')) {
        $_SESSION['error'] = 'Bạn không có quyền xóa sản phẩm!';
        header('Location: products.php');
        exit;
    }
    $id = (int) $_GET['delete_id'];
    // Delete related records first to maintain referential integrity
    $pdo->prepare("DELETE FROM order_items WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM inventory WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    $_SESSION['success'] = "Đã xóa sản phẩm thành công!";
    header('Location: products.php');
    exit;
}

// Handle discount setting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_discount') {
    // Kiểm tra quyền thiết lập giảm giá
    if (!hasPermission('set_product_discount')) {
        $_SESSION['error'] = 'Bạn không có quyền thiết lập giảm giá!';
        header('Location: products.php');
        exit;
    }
    
    try {
        $product_id = intval($_POST['product_id']);
        $discount_type = $_POST['discount_type'];
        $discount_value = floatval($_POST['discount_value']);
        $discount_start_date = !empty($_POST['discount_start_date']) ? $_POST['discount_start_date'] : null;
        $discount_end_date = !empty($_POST['discount_end_date']) ? $_POST['discount_end_date'] : null;
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;

        // Get original price
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $original_price = $product['price'];
            $sale_price = null;

            if ($discount_type === 'percentage') {
                $sale_price = round($original_price - ($original_price * $discount_value / 100), 0);
            } elseif ($discount_type === 'fixed') {
                $sale_price = max($original_price - $discount_value, 0);
            }

            $stmt = $pdo->prepare("
                UPDATE products 
                SET discount_type = ?, discount_value = ?, sale_price = ?,
                    discount_start_date = ?, discount_end_date = ?, is_featured = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $discount_type,
                $discount_value,
                $sale_price,
                $discount_start_date,
                $discount_end_date,
                $is_featured,
                $product_id
            ]);

            $_SESSION['success'] = "Thiết lập giảm giá thành công!";
        }
    } catch (PDOException $e) {
        $_SESSION['warning'] = "Lỗi: " . $e->getMessage();
    }
    header('Location: products.php');
    exit;
}

// Handle discount removal
if (isset($_GET['remove_discount'])) {
    // Kiểm tra quyền xóa giảm giá
    if (!hasPermission('remove_product_discount')) {
        $_SESSION['error'] = 'Bạn không có quyền xóa giảm giá!';
        header('Location: products.php');
        exit;
    }
    
    try {
        $product_id = intval($_GET['remove_discount']);
        $stmt = $pdo->prepare("
            UPDATE products 
            SET discount_type = 'none', discount_value = 0, sale_price = NULL,
                discount_start_date = NULL, discount_end_date = NULL
            WHERE id = ?
        ");
        $stmt->execute([$product_id]);
        $_SESSION['success'] = "Đã xóa giảm giá thành công!";
    } catch (PDOException $e) {
        $_SESSION['warning'] = "Lỗi: " . $e->getMessage();
    }
    header('Location: products.php');
    exit;
}

// Search and pagination
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$search_category = isset($_GET['search_category']) ? (int) $_GET['search_category'] : 0;
$filter_discount = isset($_GET['filter_discount']) ? $_GET['filter_discount'] : '';

$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1)
    $current_page = 1;

// Build search conditions
$where_conditions = [];
$params = [];

if (!empty($search_name)) {
    $where_conditions[] = "p.name LIKE ?";
    $params[] = "%{$search_name}%";
}

if ($search_category > 0) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $search_category;
}

if ($filter_discount === 'on_sale') {
    $where_conditions[] = "p.discount_type != 'none'";
} elseif ($filter_discount === 'no_sale') {
    $where_conditions[] = "p.discount_type = 'none'";
} elseif ($filter_discount === 'featured') {
    $where_conditions[] = "p.is_featured = 1";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total items
$count_sql = "SELECT COUNT(*) FROM products p $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

$offset = ($current_page - 1) * $items_per_page;

// Get products with discount information
$sql = "SELECT 
    p.*, 
    c.name AS category_name, 
    COALESCE(i.quantity, 0) as quantity,
    CASE 
        WHEN p.sale_price IS NOT NULL THEN 
            ROUND((p.price - p.sale_price) / p.price * 100, 0)
        ELSE 0
    END as discount_percentage
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN inventory i ON p.id = i.product_id
 $where_clause
ORDER BY p.is_featured DESC, p.id ASC
LIMIT $items_per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Thống kê
 $stats = $pdo->query("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN discount_type != 'none' AND 
                     (discount_start_date IS NULL OR discount_start_date <= CURDATE()) AND 
                     (discount_end_date IS NULL OR discount_end_date >= CURDATE()) 
                 THEN 1 ELSE 0 END) as on_sale_count,
        SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_count,
        (SELECT AVG((price - sale_price) / price * 100) 
         FROM products 
         WHERE discount_type != 'none' AND 
               (discount_start_date IS NULL OR discount_start_date <= CURDATE()) AND 
               (discount_end_date IS NULL OR discount_end_date >= CURDATE())) as avg_discount
    FROM products
")->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý sản phẩm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/cssad/products.css">
</head>

<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-mug-hot me-2"></i>Quản lý sản phẩm</h1>
                </div>
                <?php if (hasPermission('create_product')): ?>
                <a href="product_form.php" class="btn btn-primary btn-action">
                    <i class="fas fa-plus"></i> Thêm sản phẩm mới
                </a>
                <?php endif; ?>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i
                        class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['warning'];
                        unset($_SESSION['warning']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-value"><?= number_format($stats['total_products']) ?></div>
                            <div class="stat-label">Tổng sản phẩm</div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="fas fa-box"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-value"><?= number_format($stats['on_sale_count']) ?></div>
                            <div class="stat-label">Đang giảm giá</div>
                        </div>
                        <div class="stat-icon danger">
                            <i class="fas fa-percentage"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-value"><?= number_format($stats['featured_count']) ?></div>
                            <div class="stat-label">Sản phẩm nổi bật</div>
                        </div>
                        <div class="stat-icon warning">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-value"><?= number_format($stats['avg_discount'], 1) ?>%</div>
                            <div class="stat-label">Giảm giá trung bình</div>
                        </div>
                        <div class="stat-icon success">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <div class="filter-header">
                    <i class="fas fa-filter"></i>
                    Bộ lọc sản phẩm
                </div>
                <div class="card-body">
                    <form method="GET" action="products.php">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Tên sản phẩm</label>
                                <input type="text" name="search_name" class="form-control"
                                    placeholder="Nhập tên sản phẩm..."
                                    value="<?php echo htmlspecialchars($search_name); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Danh mục</label>
                                <select name="search_category" class="form-select">
                                    <option value="">Tất cả danh mục</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo $search_category == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="filter_discount" class="form-select">
                                    <option value="">Tất cả</option>
                                    <option value="on_sale" <?= $filter_discount === 'on_sale' ? 'selected' : '' ?>>Đang
                                        giảm giá</option>
                                    <option value="no_sale" <?= $filter_discount === 'no_sale' ? 'selected' : '' ?>>Chưa
                                        giảm giá</option>
                                    <option value="featured" <?= $filter_discount === 'featured' ? 'selected' : '' ?>>Nổi
                                        bật</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary btn-action">
                                    <i class="fas fa-search"></i>
                                </button>
                                <a href="products.php" class="btn btn-secondary btn-action">
                                    <i class="fas fa-redo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Products Table -->
            <div class="content-card table-container">
                <?php if ($products): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sản phẩm</th>
                                <th>Danh mục</th>
                                <th>Giá</th>
                                <th>Giảm giá</th>
                                <th>Trạng thái</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td><?php echo $p['id']; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="product-image-cell me-3">
                                                <?php if (!empty($p['image'])): ?>
                                                    <img src="<?php echo htmlspecialchars($p['image']); ?>" alt="Ảnh sản phẩm"
                                                        class="product-image">
                                                <?php else: ?>
                                                    <img src="https://picsum.photos/seed/product<?php echo $p['id']; ?>/80/80.jpg"
                                                        alt="Ảnh sản phẩm" class="product-image">
                                                <?php endif; ?>

                                                <div class="product-badges">
                                                    <?php if ($p['is_featured']): ?>
                                                        <span class="badge badge-featured">Nổi bật</span>
                                                    <?php endif; ?>

                                                    <?php if ($p['discount_percentage'] > 0): ?>
                                                        <span class="badge badge-discount">-<?= $p['discount_percentage'] ?>%</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <div class="product-category">
                                                    <i class="fas fa-folder"></i>
                                                    <?php echo htmlspecialchars($p['category_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['category_name']); ?></td>
                                    <td>
                                        <div class="price-display">
                                            <?php if ($p['sale_price']): ?>
                                                <span
                                                    class="price-current"><?= number_format($p['sale_price'], 0, ',', '.') ?>đ</span>
                                                <span class="price-original"><?= number_format($p['price'], 0, ',', '.') ?>đ</span>
                                            <?php else: ?>
                                                <span class="price-current"><?= number_format($p['price'], 0, ',', '.') ?>đ</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($p['discount_type'] !== 'none'): ?>
                                            <div class="discount-info">
                                                <span class="discount-value">
                                                    <?= $p['discount_type'] === 'percentage' ? $p['discount_value'] . '%' : number_format($p['discount_value'], 0) . 'đ' ?>
                                                </span>
                                                <?php if ($p['discount_end_date']): ?>
                                                    <span class="discount-expiry">
                                                        <i class="fas fa-clock"></i> Hết hạn:
                                                        <?= date('d/m/Y', strtotime($p['discount_end_date'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Không</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['status'] == 'active'): ?>
                                            <span class="status-badge status-active">Hoạt động</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inactive">Tạm ngưng</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (hasPermission('set_product_discount')): ?>
                                            <button class="btn btn-info btn-sm btn-action"
                                                onclick="openDiscountModal(<?= htmlspecialchars(json_encode($p)) ?>)">
                                                <i class="fas fa-percentage"></i> Giảm giá
                                            </button>
                                            <?php endif; ?>

                                            <?php if ($p['discount_type'] !== 'none' && hasPermission('remove_product_discount')): ?>
                                                <a href="?remove_discount=<?= $p['id'] ?>&page=<?= $current_page ?>"
                                                    class="btn btn-warning btn-sm btn-action"
                                                    onclick="return confirm('Xóa giảm giá cho sản phẩm này?');">
                                                    <i class="fas fa-times"></i> Xóa GG
                                                </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('edit_product')): ?>
                                            <a href="product_form.php?id=<?= $p['id']; ?>"
                                                class="btn btn-primary btn-sm btn-action">
                                                <i class="fas fa-edit"></i> Sửa
                                            </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('delete_product')): ?>
                                            <a href="products.php?delete_id=<?= $p['id']; ?>&page=<?= $current_page; ?>"
                                                class="btn btn-danger btn-sm btn-action"
                                                onclick="return confirm('Bạn có chắc muốn xóa sản phẩm này?');">
                                                <i class="fas fa-trash"></i> Xóa
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h3>Không có sản phẩm</h3>
                        <p>
                            <?php if (!empty($search_name) || $search_category > 0): ?>
                                Không tìm thấy sản phẩm nào phù hợp với điều kiện tìm kiếm
                            <?php else: ?>
                                Chưa có sản phẩm nào trong hệ thống
                            <?php endif; ?>
                        </p>
                        <?php if (hasPermission('create_product')): ?>
                        <a href="product_form.php" class="btn btn-primary btn-action mt-3">
                            <i class="fas fa-plus me-2"></i>Thêm sản phẩm mới
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link"
                                href="?page=<?php echo $current_page - 1; ?>&search_name=<?php echo urlencode($search_name); ?>&search_category=<?php echo $search_category; ?>&filter_discount=<?php echo $filter_discount; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link"
                                    href="?page=<?php echo $i; ?>&search_name=<?php echo urlencode($search_name); ?>&search_category=<?php echo $search_category; ?>&filter_discount=<?php echo $filter_discount; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link"
                                href="?page=<?php echo $current_page + 1; ?>&search_name=<?php echo urlencode($search_name); ?>&search_category=<?php echo $search_category; ?>&filter_discount=<?php echo $filter_discount; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </main>
    </div>

    <!-- Discount Modal -->
    <div class="modal fade" id="discountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-percentage me-2"></i>Thiết lập giảm giá
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="set_discount">
                    <input type="hidden" name="product_id" id="product_id">

                    <div class="modal-body">
                        <div class="product-preview">
                            <img id="preview_image" src="" alt="Ảnh sản phẩm" class="preview-image">
                            <div class="preview-info">
                                <h5 id="preview_name"></h5>
                                <div class="preview-price">Giá gốc: <strong id="preview_price"></strong></div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Loại giảm giá <span class="text-danger">*</span></label>
                                <select name="discount_type" id="discount_type" class="form-select" required
                                    onchange="updatePreview()">
                                    <option value="none">Không giảm giá</option>
                                    <option value="percentage">Phần trăm (%)</option>
                                    <option value="fixed">Số tiền cố định (đ)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Giá trị giảm <span class="text-danger">*</span></label>
                                <input type="number" name="discount_value" id="discount_value" class="form-control"
                                    required min="0" step="0.01" onchange="updatePreview()">
                            </div>

                            <div class="col-12">
                                <div class="price-preview">
                                    <div class="price-box">
                                        <div class="price-box-label">Giá gốc</div>
                                        <div class="price-box-value" id="original_price_display">0đ</div>
                                    </div>
                                    <div class="price-box sale">
                                        <div class="price-box-label">Giá sau giảm</div>
                                        <div class="price-box-value" id="sale_price_display">0đ</div>
                                    </div>
                                </div>
                                <div class="savings-info">
                                    Tiết kiệm: <strong id="savings_display">0đ</strong>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Ngày bắt đầu</label>
                                <input type="datetime-local" name="discount_start_date" id="discount_start_date"
                                    class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ngày kết thúc</label>
                                <input type="datetime-local" name="discount_end_date" id="discount_end_date"
                                    class="form-control">
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" name="is_featured" id="is_featured" class="form-check-input"
                                        value="1">
                                    <label class="form-check-label" for="is_featured">
                                        <i class="fas fa-star text-warning"></i> Đặt làm sản phẩm nổi bật
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary btn-action">
                            <i class="fas fa-save me-2"></i>Lưu thiết lập
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentProduct = null;

        function openDiscountModal(product) {
            currentProduct = product;
            document.getElementById('product_id').value = product.id;
            document.getElementById('preview_name').textContent = product.name;
            document.getElementById('preview_price').textContent = formatNumber(product.price) + 'đ';
            document.getElementById('original_price_display').textContent = formatNumber(product.price) + 'đ';

            // Set preview image
            if (product.image) {
                document.getElementById('preview_image').src = product.image;
            } else {
                document.getElementById('preview_image').src = 'https://picsum.photos/seed/product' + product.id + '/80/80.jpg';
            }

            document.getElementById('discount_type').value = product.discount_type || 'none';
            document.getElementById('discount_value').value = product.discount_value || 0;

            if (product.discount_start_date) {
                document.getElementById('discount_start_date').value = formatDateTimeLocal(new Date(product.discount_start_date));
            }
            if (product.discount_end_date) {
                document.getElementById('discount_end_date').value = formatDateTimeLocal(new Date(product.discount_end_date));
            }
            document.getElementById('is_featured').checked = product.is_featured == 1;

            updatePreview();
            const modal = new bootstrap.Modal(document.getElementById('discountModal'));
            modal.show();
        }

        function updatePreview() {
            if (!currentProduct) return;
            const originalPrice = parseFloat(currentProduct.price);
            const discountType = document.getElementById('discount_type').value;
            const discountValue = parseFloat(document.getElementById('discount_value').value) || 0;

            let salePrice = originalPrice;
            if (discountType === 'percentage') {
                salePrice = Math.round(originalPrice - (originalPrice * discountValue / 100));
            } else if (discountType === 'fixed') {
                salePrice = Math.max(originalPrice - discountValue, 0);
            }

            const savings = originalPrice - salePrice;
            document.getElementById('sale_price_display').textContent = formatNumber(salePrice) + 'đ';
            document.getElementById('savings_display').textContent = formatNumber(savings) + 'đ';
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('vi-VN').format(num);
        }

        function formatDateTimeLocal(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${year}-${month}-${day}T${hours}:${minutes}`;
        }

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