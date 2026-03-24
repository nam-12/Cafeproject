<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// 1. Lấy tham số từ AJAX request
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$limit = 8; // Số lượng sản phẩm mỗi trang
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// 2. Đếm tổng số sản phẩm để tính toán phân trang
$count_sql = "SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.status = 'active'";
if ($search) {
    $count_sql .= " AND p.name LIKE :search";
}
if ($category_filter) {
    $count_sql .= " AND p.category_id = :category";
}

$count_stmt = $pdo->prepare($count_sql);
if ($search) $count_stmt->bindValue(':search', '%' . $search . '%');
if ($category_filter) $count_stmt->bindValue(':category', $category_filter);
$count_stmt->execute();
$total_products = $count_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// 3. Truy vấn danh sách sản phẩm theo trang
$sql = "SELECT p.*, c.name as category_name, i.quantity as stock,
        COUNT(DISTINCT pr.id) as review_count,
        ROUND(AVG(pr.rating), 1) as avg_rating
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id
        LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
        WHERE p.status = 'active'";

if ($search) {
    $sql .= " AND p.name LIKE :search";
}
if ($category_filter) {
    $sql .= " AND p.category_id = :category";
}

$sql .= " GROUP BY p.id, c.name, i.quantity 
          ORDER BY p.is_featured DESC, p.id DESC 
          LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
if ($search) $stmt->bindValue(':search', '%' . $search . '%');
if ($category_filter) $stmt->bindValue(':category', $category_filter);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Hiển thị HTML cho sản phẩm
if (count($products) > 0) {
    echo '<div class="row g-4">';
    foreach ($products as $product) {
        // --- Logic xử lý giảm giá thời gian thực ---
        $now = time();
        $startDate = $product['discount_start_date'] ? strtotime($product['discount_start_date']) : 0;
        $endDate = $product['discount_end_date'] ? strtotime($product['discount_end_date']) : 2147483647;
        
        $isPromoActive = ($product['discount_type'] !== 'none' && $now >= $startDate && $now <= $endDate);
        $showDiscount = ($isPromoActive && !empty($product['sale_price']) && $product['sale_price'] < $product['price']);
        
        $finalPrice = $showDiscount ? $product['sale_price'] : $product['price'];
        $isFeatured = ($product['is_featured'] == 1);

        // --- Logic xử lý ảnh ---
        $image = str_replace(['uploads/', '/uploads/'], '', $product['image'] ?? '');
        $webImagePath = (empty($image) || !file_exists(__DIR__ . "/../admin/uploads/" . $image)) 
                        ? "../admin/uploads/no-image.jpg" 
                        : "../admin/uploads/" . $image;

        echo '<div class="col-lg-3 col-md-4 col-sm-6">';
        echo '<div class="product-card-premium" onclick="showProductDetail(' . $product['id'] . ')">';
        
        // Nhãn Badge
        echo '<div class="product-badges" style="position: absolute; top: 10px; left: 10px; z-index: 5; display:flex; flex-direction:column; gap:5px;">';
        if ($isFeatured) {
            echo '<span class="badge bg-warning text-dark shadow-sm"><i class="fas fa-crown"></i> Nổi bật</span>';
        }
        if ($showDiscount) {
            $percent = round((1 - ($product['sale_price'] / $product['price'])) * 100);
            echo '<span class="badge bg-danger shadow-sm">-' . $percent . '%</span>';
        }
        echo '</div>';

        echo '<div class="product-image-premium">';
        echo '<img src="' . htmlspecialchars($webImagePath) . '" alt="' . htmlspecialchars($product['name']) . '">';
        if ($product['stock'] <= 5 && $product['stock'] > 0) {
            echo '<span class="product-badge-premium"><i class="fas fa-fire me-1"></i>Sắp hết</span>';
        }
        echo '</div>';

        echo '<div class="product-body-premium">';
        echo '<div class="product-category-premium">' . htmlspecialchars($product['category_name']) . '</div>';
        echo '<h3 class="product-title-premium">' . htmlspecialchars($product['name']) . '</h3>';

        // Rating
        if ($product['avg_rating'] > 0) {
            echo '<div class="product-rating-premium mb-2"><span class="text-warning small">' . number_format($product['avg_rating'], 1) . ' <i class="fas fa-star"></i></span>';
            echo '<span class="rating-count-premium ms-1">(' . $product['review_count'] . ')</span></div>';
        }

        // Giá
        echo '<div class="product-price-premium">';
        if ($showDiscount) {
            echo '<span class="price-old">' . number_format($product['price'], 0, ',', '.') . '₫</span>';
            echo '<span class="price-new">' . number_format($product['sale_price'], 0, ',', '.') . '₫</span>';
        } else {
            echo '<span class="price-new">' . number_format($product['price'], 0, ',', '.') . '₫</span>';
        }
        echo '</div>';

        echo '<div class="product-stock-premium ' . ($product['stock'] <= 10 ? 'low' : '') . '">';
        echo '<i class="fas fa-box-open"></i><span>Còn ' . $product['stock'] . ' ly</span></div>';

        if ($product['stock'] > 0) {
            echo '<button class="btn-add-cart-premium" onclick="addToCart(' . $product['id'] . ', \'' . addslashes($product['name']) . '\', ' . $finalPrice . ', ' . $product['stock'] . '); event.stopPropagation();">';
            echo '<i class="fas fa-cart-plus"></i> Thêm vào giỏ</button>';
        } else {
            echo '<button class="btn-add-cart-premium" disabled><i class="fas fa-times-circle"></i> Hết hàng</button>';
        }
        
        echo '</div>'; 
        echo '</div>'; 
        echo '</div>'; 
    }
    echo '</div>';

    // 5. Hiển thị thanh phân trang bằng AJAX
    if ($total_pages > 1) {
        echo '<div class="pagination-container mt-5 d-flex justify-content-center">';
        echo '<nav><ul class="pagination pagination-premium">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $activeClass = ($i == $page) ? 'active' : '';
            echo '<li class="page-item ' . $activeClass . '">';
            echo '<a class="page-link" href="javascript:void(0)" onclick="changePage(' . $i . ')">' . $i . '</a>';
            echo '</li>';
        }
        echo '</ul></nav></div>';
    }

    // 6. QUAN TRỌNG: Cập nhật biến dữ liệu JS để hàm showProductDetail hoạt động ở trang mới
    echo '<textarea id="ajax_product_data" style="display:none;">' . json_encode($products) . '</textarea>';

} else {
    echo '<div class="empty-state-premium text-center p-5">';
    echo '<i class="fas fa-search fa-3x mb-3" style="color: #ccc;"></i><h3>Không tìm thấy sản phẩm</h3>';
    echo '<p>Vui lòng thử tìm kiếm với từ khóa khác hoặc chọn danh mục khác.</p>';
    echo '</div>';
}
?>