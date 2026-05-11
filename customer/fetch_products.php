<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// 1. Lấy tham số từ AJAX request
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'replace'; // 'replace' hoặc 'append'
$limit = 8; // Số lượng sản phẩm mỗi trang
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// 2. Đếm tổng số sản phẩm để tính toán phân trang
$count_sql = "SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.status = 'active'";
if ($search) {
    $count_sql .= " AND (p.name LIKE :search1 OR p.description LIKE :search2 OR p.ai_metadata LIKE :search3)";
}
if ($category_filter) {
    $count_sql .= " AND p.category_id = :category";
}

$count_stmt = $pdo->prepare($count_sql);
if ($search) {
    $count_stmt->bindValue(':search1', '%' . $search . '%');
    $count_stmt->bindValue(':search2', '%' . $search . '%');
    $count_stmt->bindValue(':search3', '%' . $search . '%');
}
if ($category_filter) $count_stmt->bindValue(':category', $category_filter);
$count_stmt->execute();
$total_products = $count_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// 3. Truy vấn danh sách sản phẩm theo trang
$sql = "SELECT p.*, c.name as category_name, i.quantity as stock,
        COUNT(DISTINCT pr.id) as review_count,
        ROUND(AVG(pr.rating), 1) as avg_rating,
        (SELECT SUM(quantity) FROM order_items oi2 JOIN orders o2 ON oi2.order_id = o2.id WHERE oi2.product_id = p.id AND o2.status != 'cancelled') as sold_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id
        LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
        WHERE p.status = 'active'";

if ($search) {
    $sql .= " AND (p.name LIKE :search1 OR p.description LIKE :search2 OR p.ai_metadata LIKE :search3)";
}
if ($category_filter) {
    $sql .= " AND p.category_id = :category";
}

$sql .= " GROUP BY p.id, c.name, i.quantity 
          ORDER BY p.is_featured DESC, p.id DESC 
          LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
if ($search) {
    $stmt->bindValue(':search1', '%' . $search . '%');
    $stmt->bindValue(':search2', '%' . $search . '%');
    $stmt->bindValue(':search3', '%' . $search . '%');
}
if ($category_filter) $stmt->bindValue(':category', $category_filter);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Hiển thị HTML cho sản phẩm
if (count($products) > 0) {

    // Tính số sản phẩm đã hiển thị tính đến trang hiện tại
    $shown_count = min($page * $limit, $total_products);
    $has_more = ($page < $total_pages);

    // Mode append: chỉ trả về các product cards (không wrapper)
    if ($mode === 'append') {
        foreach ($products as $product) {
            renderProductCard($product);
        }

        // Trả metadata qua hidden element
        echo '<script type="application/json" id="append_meta">';
        echo json_encode([
            'products'    => $products,
            'shown'       => $shown_count,
            'total'       => $total_products,
            'has_more'    => $has_more,
            'current_page'=> $page,
        ]);
        echo '</script>';
    } 
    // Mode replace: trả toàn bộ (dùng khi filter/search)
    else {
        echo '<div class="row g-4" id="productGrid">';
        foreach ($products as $product) {
            renderProductCard($product);
        }
        echo '</div>';

        // Load More button
        if ($has_more) {
            echo '<div class="load-more-container" id="loadMoreContainer">';
            echo '<button class="btn-load-more" id="btnLoadMore" onclick="loadMoreProducts()">';
            echo '<i class="fas fa-plus-circle"></i><span>Xem thêm sản phẩm</span></button>';
            echo '<span class="load-more-count" id="loadMoreCount">';
            echo 'Đang hiển thị ' . $shown_count . ' / ' . $total_products . ' sản phẩm</span>';
            echo '</div>';
        } else {
            echo '<div class="load-more-container"><span class="load-more-count">Đã hiển thị tất cả ' . $total_products . ' sản phẩm</span></div>';
        }

        // Cập nhật biến dữ liệu JS
        echo '<textarea id="ajax_product_data" style="display:none;">' . json_encode($products) . '</textarea>';
        echo '<script type="application/json" id="replace_meta">' . json_encode([
            'total' => $total_products,
            'shown' => $shown_count,
            'has_more' => $has_more,
        ]) . '</script>';
    }

} else {
    echo '<div class="empty-state-premium text-center p-5">';
    echo '<i class="fas fa-search fa-3x mb-3" style="color: #ccc;"></i><h3>Không tìm thấy sản phẩm</h3>';
    echo '<p>Vui lòng thử tìm kiếm với từ khóa khác hoặc chọn danh mục khác.</p>';
    echo '</div>';
}

// ── Hàm render 1 product card ──────────────────────────────────
function renderProductCard($product) {
    $now = time();
    $startDate = $product['discount_start_date'] ? strtotime($product['discount_start_date']) : 0;
    $endDate = $product['discount_end_date'] ? strtotime($product['discount_end_date']) : 2147483647;
    $isPromoActive = ($product['discount_type'] !== 'none' && $now >= $startDate && $now <= $endDate);
    $showDiscount = ($isPromoActive && !empty($product['sale_price']) && $product['sale_price'] < $product['price']);
    $finalPrice = $showDiscount ? $product['sale_price'] : $product['price'];
    $isFeatured = ($product['is_featured'] == 1);

    $image = str_replace(['uploads/', '/uploads/'], '', $product['image'] ?? '');
    $webImagePath = (empty($image) || !file_exists(__DIR__ . "/../admin/uploads/" . $image)) 
                    ? "../admin/uploads/no-image.jpg" 
                    : "../admin/uploads/" . $image;

    echo '<div class="col-lg-3 col-md-4 col-sm-6">';
    echo '<div class="product-card-premium fade-in" onclick="showProductDetail(' . $product['id'] . ')">';
    
    // Badges
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

    // Stats
    echo '<div class="product-stats-premium" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; border-bottom: 1px dashed #eee; padding-bottom: 0.5rem;">';
    echo '<div class="product-rating-premium" style="display: flex; align-items: center; gap: 5px; font-size: 0.9rem; font-weight: 600; color: #444;">';
    echo '<i class="fas fa-star text-warning"></i>';
    echo '<span>' . ($product['avg_rating'] ?: '0') . '</span>';
    echo '<small class="text-muted" style="font-weight: 400;">(' . $product['review_count'] . ')</small>';
    echo '</div>';
    echo '<div class="product-sold-premium" style="font-size: 0.85rem; color: #777; font-weight: 500;">';
    echo 'Đã bán ' . ($product['sold_count'] ?: '0');
    echo '</div>';
    echo '</div>';

    // Price
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
?>