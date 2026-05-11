<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// Khởi tạo giỏ hàng
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Cấu hình phân trang ban đầu
$limit = 8; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Lấy tham số tìm kiếm
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// 1. Đếm tổng số sản phẩm
$count_sql = "SELECT COUNT(*) FROM products WHERE status = 'active'";
if ($search) $count_sql .= " AND (name LIKE :search1 OR description LIKE :search2 OR ai_metadata LIKE :search3)";
if ($category_filter) $count_sql .= " AND category_id = :category";

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

// 2. Lấy danh sách sản phẩm (Lần tải đầu tiên)
$sql = "SELECT p.*, c.name as category_name, i.quantity as stock,
        COUNT(DISTINCT pr.id) as review_count,
        ROUND(AVG(pr.rating), 1) as avg_rating,
        (SELECT SUM(quantity) FROM order_items oi2 JOIN orders o2 ON oi2.order_id = o2.id WHERE oi2.product_id = p.id AND o2.status != 'cancelled') as sold_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id
        LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
        WHERE p.status = 'active'";

if ($search) $sql .= " AND (p.name LIKE :search1 OR p.description LIKE :search2 OR p.ai_metadata LIKE :search3)";
if ($category_filter) $sql .= " AND p.category_id = :category";

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

// Lấy danh mục cho thanh lọc
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coffee House - The Premium Experience</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/index.css">
    <link rel="stylesheet" href="../assets/css/ai_components.css">
    
    <style>
        .pagination-container { margin-top: 50px; display: flex; justify-content: center; }
        .info-item { display: block !important; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .info-item .label { font-weight: 700; color: #6f4e37; display: block; margin-bottom: 3px; text-transform: uppercase; font-size: 0.8rem; }
        .info-item .value { display: block; line-height: 1.6; color: #333; }
        .empty-state-premium { text-align: center; padding: 50px; color: #999; }
        .empty-state-premium i { font-size: 3rem; margin-bottom: 15px; }

        /* Load More Button */
        .load-more-container { text-align: center; margin-top: 40px; }
        .btn-load-more {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 14px 40px; border: 2px solid #6f4e37; background: transparent;
            color: #6f4e37; border-radius: 50px; font-weight: 600; font-size: 1rem;
            cursor: pointer; transition: all 0.35s ease; letter-spacing: 0.02em;
        }
        .btn-load-more:hover {
            background: #6f4e37; color: #fff;
            box-shadow: 0 6px 20px rgba(111,78,55,0.3); transform: translateY(-2px);
        }
        .btn-load-more:disabled {
            opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none;
        }
        .btn-load-more .spinner-border {
            width: 18px; height: 18px; border-width: 2px;
        }
        .load-more-count {
            display: block; margin-top: 10px; font-size: 0.85rem; color: #8D6E63;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .product-card-premium.fade-in {
            animation: fadeInUp 0.4s ease forwards;
        }
    </style>
</head>

<body>
    <?php include '../templates/navbar.php'; ?>

    <section class="hero-premium">
        <div class="hero-content-premium">
            <h1><i class="fas fa-coffee"></i> Coffee House</h1>
            <div class="hero-divider"></div>
            <p>Trải nghiệm hương vị cafe đặc biệt từ những hạt cafe tuyển chọn hảo hạng</p>
        </div>
    </section>

    <section class="search-premium">
        <div class="container">
            <div class="search-container-premium">
                <form id="searchForm" onsubmit="handleProductFilter(event)">
                    <input type="text" id="searchInput" class="search-input-premium"
                        placeholder="Tìm kiếm sản phẩm ..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn-premium">
                        <i class="fas fa-search me-2"></i>Tìm kiếm
                    </button>
                    <div id="ai-search-reason" class="ai-reason-text" style="display:none; margin-top: 8px;"></div>
                    <div id="ai-search-results" class="ai-results-dropdown" style="display:none;"></div>
                </form>
            </div>
        </div>
    </section>

    <section class="categories-premium">
        <div class="container">
            <div class="category-scroll">
                <a href="javascript:void(0)" data-category-id=""
                    class="category-item-premium <?php echo empty($category_filter) ? 'active' : ''; ?>"
                    onclick="handleCategoryClick(this)">
                    <i class="fas fa-th-large me-2"></i>Tất cả
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="javascript:void(0)" data-category-id="<?php echo $cat['id']; ?>"
                        class="category-item-premium <?php echo $category_filter == $cat['id'] ? 'active' : ''; ?>"
                        onclick="handleCategoryClick(this)">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="products-premium">
        <div class="container">
            <div class="section-title-premium">
                <h2>Thực Đơn Đặc Biệt</h2>
            </div>

            <div id="productListing">
                <?php if (count($products) > 0): ?>
                    <div class="row g-4" id="productGrid">
                        <?php foreach ($products as $product): 
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
                            ?>
                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card-premium" onclick="showProductDetail(<?= $product['id']; ?>)">
                                    <div class="product-badges" style="position: absolute; top: 10px; left: 10px; z-index: 5; display:flex; flex-direction:column; gap:5px;">
                                        <?php if ($isFeatured): ?>
                                            <span class="badge bg-warning text-dark shadow-sm"><i class="fas fa-crown"></i> Nổi bật</span>
                                        <?php endif; ?>
                                        <?php if ($showDiscount): ?>
                                            <span class="badge bg-danger shadow-sm">
                                                -<?= round((1 - ($product['sale_price'] / $product['price'])) * 100) ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-image-premium">
                                        <img src="<?= htmlspecialchars($webImagePath); ?>" alt="<?= htmlspecialchars($product['name']); ?>">
                                        <?php if ($product['stock'] <= 5 && $product['stock'] > 0): ?>
                                            <span class="product-badge-premium"><i class="fas fa-fire me-1"></i>Sắp hết</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-body-premium">
                                        <div class="product-category-premium"><?= htmlspecialchars($product['category_name']); ?></div>
                                        <h3 class="product-title-premium"><?= htmlspecialchars($product['name']); ?></h3>

                                        <div class="product-stats-premium" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; border-bottom: 1px dashed #eee; padding-bottom: 0.5rem;">
                                            <div class="product-rating-premium" style="display: flex; align-items: center; gap: 5px; font-size: 0.9rem; font-weight: 600; color: #444;">
                                                <i class="fas fa-star text-warning"></i>
                                                <span><?= $product['avg_rating'] ?: '0'; ?></span>
                                                <small class="text-muted" style="font-weight: 400;">(<?= $product['review_count']; ?>)</small>
                                            </div>
                                            <div class="product-sold-premium" style="font-size: 0.85rem; color: #777; font-weight: 500;">
                                                Đã bán <?= $product['sold_count'] ?: '0'; ?>
                                            </div>
                                        </div>

                                        <div class="product-price-premium">
                                            <?php if ($showDiscount): ?>
                                                <span class="price-old"><?= number_format($product['price'], 0, ',', '.') ?>₫</span>
                                                <span class="price-new"><?= number_format($product['sale_price'], 0, ',', '.') ?>₫</span>
                                            <?php else: ?>
                                                <span class="price-new"><?= number_format($product['price'], 0, ',', '.') ?>₫</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="product-stock-premium <?= $product['stock'] <= 10 ? 'low' : ''; ?>">
                                            <i class="fas fa-box-open"></i> <span>Còn <?= $product['stock']; ?> ly</span>
                                        </div>

                                        <button class="btn-add-cart-premium" 
                                            onclick="addToCart(<?= $product['id']; ?>, '<?= addslashes($product['name']); ?>', <?= $finalPrice; ?>, <?= $product['stock']; ?>); event.stopPropagation();"
                                            <?= $product['stock'] <= 0 ? 'disabled' : '' ?>>
                                            <i class="fas <?= $product['stock'] > 0 ? 'fa-cart-plus' : 'fa-times-circle' ?>"></i>
                                            <?= $product['stock'] > 0 ? 'Thêm vào giỏ' : 'Hết hàng' ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="load-more-container" id="loadMoreContainer">
                        <button class="btn-load-more" id="btnLoadMore" onclick="loadMoreProducts()">
                            <i class="fas fa-plus-circle"></i>
                            <span>Xem thêm sản phẩm</span>
                        </button>
                        <span class="load-more-count" id="loadMoreCount">
                            Đang hiển thị <?= count($products) ?> / <?= $total_products ?> sản phẩm
                        </span>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state-premium">
                        <i class="fas fa-search"></i>
                        <h3>Không tìm thấy sản phẩm</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php include '../templates/footer.php'; ?>
    <?php include 'chatbot_widget.php'; ?>

    <div class="modal fade" id="productDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content product-modal-premium">
                <div class="modal-body p-0">
                    <button type="button" class="btn-close-premium" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="row g-0">
                        <div class="col-md-6">
                            <div class="modal-image-container">
                                <img id="detailImage" src="" alt="" class="img-fluid" style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="product-body-premium h-100 p-4 d-flex flex-column">
                                <div class="product-category-premium" id="detailCategory"></div>
                                <h2 class="product-title-premium mb-3" id="detailName" style="font-size: 1.8rem;"></h2>
                                <div class="product-price-premium mb-4" id="detailPrice"></div>
                                
                                <div class="product-info-grid mb-4">
                                    <div class="info-item">
                                        <span class="label">Thành phần:</span>
                                        <span id="detailIngredients" class="value"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Năng lượng:</span>
                                        <span class="value"><span id="detailCalories"></span> kcal</span>
                                    </div>
                                </div>
                                <div class="product-description-premium mb-4" id="detailDescription" style="font-style: italic; color: #666;"></div>
                                
                                <!-- Reviews Section -->
                                <div class="product-reviews-section mt-4 pt-4 border-top">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Đánh giá (<span id="reviewCount">0</span>)</h5>
                                    </div>
                                    <div id="reviewsList" class="reviews-scroll-area">
                                        <!-- Reviews will be loaded here -->
                                        <div class="empty-reviews text-center py-4 text-muted">
                                            <i class="fas fa-comment-slash d-block mb-2 fa-2x"></i>
                                            Chưa có đánh giá nào cho sản phẩm này.
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-auto pt-4">
                                    <button type="button" class="btn-add-cart-premium w-100" id="modalAddCartBtn">
                                        <i class="fas fa-cart-plus"></i> Thêm vào giỏ hàng
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
        <div id="cartToast" class="toast toast-premium" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header toast-header-premium">
                <i class="fas fa-check-circle me-2" style="color: #2ECC71;"></i>
                <strong class="me-auto">Thành công!</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" style="color: #333; font-weight: 500;">
                Đã thêm sản phẩm vào giỏ hàng!
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/ai_search.js"></script>
    <script>
        let currentProducts = <?php echo json_encode($products); ?>;
        // Danh sách toàn bộ sản phẩm để tìm kiếm tức thì
        const allProductsMetadata = <?php 
            $allStmt = $pdo->query("SELECT id, name, price, image FROM products WHERE status = 'active'");
            $allProducts = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Hàm loại bỏ dấu tiếng Việt an toàn hơn iconv
            function remove_accents($str) {
                $accents = array(
                    'a'=>'á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ',
                    'd'=>'đ',
                    'e'=>'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
                    'i'=>'í|ì|ỉ|ĩ|ị',
                    'o'=>'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
                    'u'=>'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
                    'y'=>'ý|ỳ|ỷ|ỹ|ỵ',
                );
                foreach($accents as $non_accent => $accent_regex) {
                    $str = preg_replace("/($accent_regex)/i", $non_accent, $str);
                }
                return $str;
            }

            foreach ($allProducts as &$p) {
                $p['search_name'] = strtolower(remove_accents($p['name']));
            }
            echo json_encode($allProducts);
        ?>;
        let loadMorePage = 1;
        let totalProductsCount = <?= $total_products ?>;
        const INITIAL_SEARCH = '<?php echo addslashes($search); ?>';
        const INITIAL_CATEGORY_ID = '<?php echo addslashes($category_filter); ?>';

        async function showProductDetail(productId) {
            // Hiển thị loading nếu cần (tùy chọn)
            const card = document.querySelector(`.product-card-premium[onclick*="${productId}"]`);
            if (card) card.style.opacity = '0.7';

            try {
                const response = await fetch(`get_product.php?id=${productId}`);
                if (response.ok) {
                    const product = await response.json();
                    if (card) card.style.opacity = '1';
                    renderProductDetail(product);
                } else {
                    throw new Error('Fetch failed');
                }
            } catch (err) {
                console.error('Lỗi khi lấy chi tiết sản phẩm:', err);
                alert('Không tìm thấy sản phẩm. Vui lòng thử lại.');
                if (card) card.style.opacity = '1';
            }
        }

        function renderProductDetail(product) {
            // Xử lý ảnh
            let imageName = (product.image || '').replace('uploads/', '').replace('/uploads/', '');
            document.getElementById('detailImage').src = imageName ? "../admin/uploads/" + imageName : "../admin/uploads/no-image.jpg";

            // Điền thông tin text
            document.getElementById('detailName').textContent = product.name;
            document.getElementById('detailCategory').textContent = product.category_name || 'Đồ uống';
            document.getElementById('detailIngredients').textContent = product.ingredients || 'Thành phần tự nhiên, an toàn cho sức khỏe.';
            document.getElementById('detailCalories').textContent = product.calories || 0;
            document.getElementById('detailDescription').textContent = product.description || 'Hương vị tuyệt hảo chỉ có tại Coffee House.';

            // Xử lý giá & Khuyến mãi trong Modal
            const now = new Date();
            const startDate = product.discount_start_date ? new Date(product.discount_start_date) : null;
            const endDate = product.discount_end_date ? new Date(product.discount_end_date) : null;
            const isPromo = product.discount_type !== 'none' && (!startDate || now >= startDate) && (!endDate || now <= endDate);
            
            const priceContainer = document.getElementById('detailPrice');
            const finalPrice = (isPromo && product.sale_price && Number(product.sale_price) < Number(product.price)) 
                               ? product.sale_price 
                               : product.price;

            if (isPromo && product.sale_price && Number(product.sale_price) < Number(product.price)) {
                priceContainer.innerHTML = `
                    <span class="price-old" style="text-decoration: line-through; color: #999; margin-right: 10px; font-size: 1.1rem;">
                        ${Number(product.price).toLocaleString('vi-VN')}₫
                    </span> 
                    <span class="price-new" style="color: #e74c3c; font-weight: bold; font-size: 1.6rem;">
                        ${Number(product.sale_price).toLocaleString('vi-VN')}₫
                    </span>`;
            } else {
                priceContainer.innerHTML = `<span class="price-new" style="font-weight: bold; font-size: 1.6rem;">${Number(product.price).toLocaleString('vi-VN')}₫</span>`;
            }

            // Cập nhật nút thêm vào giỏ trong modal
            const modalAddBtn = document.getElementById('modalAddCartBtn');
            if (product.stock > 0) {
                modalAddBtn.disabled = false;
                modalAddBtn.innerHTML = '<i class="fas fa-cart-plus"></i> Thêm vào giỏ hàng';
                modalAddBtn.onclick = function() {
                    addToCart(product.id, product.name, finalPrice, product.stock);
                };
            } else {
                modalAddBtn.disabled = true;
                modalAddBtn.innerHTML = '<i class="fas fa-times-circle"></i> Tạm hết hàng';
            }

            // Hiển thị đánh giá
            const reviewsList = document.getElementById('reviewsList');
            const reviewCount = document.getElementById('reviewCount');
            const reviews = product.reviews || [];
            
            reviewCount.textContent = reviews.length;
            
            if (reviews.length > 0) {
                reviewsList.innerHTML = reviews.map(rev => `
                    <div class="review-item mb-3 pb-3 border-bottom">
                        <div class="d-flex align-items-center mb-2">
                            <div class="reviewer-avatar me-2">
                                <img src="${rev.profile_image ? '../admin/' + rev.profile_image : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(rev.customer_name || rev.user_full_name) + '&background=f5e6d3&color=6f4e37'}" 
                                     alt="avatar" class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover;">
                            </div>
                            <div class="reviewer-info">
                                <div class="reviewer-name fw-bold" style="font-size: 0.9rem; color: #333;">
                                    ${rev.customer_name || rev.user_full_name || 'Khách hàng'}
                                </div>
                                <div class="review-stars" style="font-size: 0.75rem; color: #ffc107;">
                                    ${renderStars(rev.rating)}
                                </div>
                            </div>
                            <div class="review-date ms-auto text-muted" style="font-size: 0.75rem;">
                                ${new Date(rev.created_at).toLocaleDateString('vi-VN')}
                            </div>
                        </div>
                        <div class="review-comment" style="font-size: 0.9rem; color: #555; padding-left: 40px;">
                            ${rev.comment || 'Không có bình luận.'}
                        </div>
                        ${rev.admin_reply ? `
                        <div class="admin-reply mt-2 p-2 rounded" style="background: #f8f9fa; border-left: 3px solid #6f4e37; margin-left: 40px; font-size: 0.85rem;">
                            <div class="fw-bold mb-1" style="color: #6f4e37;"><i class="fas fa-reply me-1"></i>Phản hồi từ cửa hàng:</div>
                            <div class="text-muted">${rev.admin_reply}</div>
                        </div>
                        ` : ''}
                    </div>
                `).join('');
            } else {
                reviewsList.innerHTML = `
                    <div class="empty-reviews text-center py-4 text-muted">
                        <i class="fas fa-comment-slash d-block mb-2 fa-2x"></i>
                        Chưa có đánh giá nào cho sản phẩm này.
                    </div>
                `;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('productDetailModal'));
            modal.show();
        }

        function renderStars(rating) {
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                stars += `<i class="fa${i <= rating ? 's' : 'r'} fa-star"></i>`;
            }
            return stars;
        }
    </script>
    <script src="../assets/js/index.js"></script>
</body>
</html>