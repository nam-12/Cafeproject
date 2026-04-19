<?php
require_once 'config/init.php';
require_once 'config/helpers.php';

/* ===============================
   OPTIMIZATION: Use prepared statements and fetch all at once
================================ */

// Load products with caching consideration
try {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'active'
        ORDER BY p.is_featured DESC, p.created_at DESC
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading products: " . $e->getMessage());
    $products = [];
}

// Load reviews with limit (no need to load ALL reviews)
try {
    $stmt = $pdo->prepare("
    SELECT 
        pr.*,
        p.name AS product_name,
        u.avatar
    FROM product_reviews pr
    INNER JOIN products p ON pr.product_id = p.id
    LEFT JOIN users u
        ON (
            pr.user_id IS NOT NULL AND u.id = pr.user_id
        )
        OR (
            pr.user_id IS NULL AND u.username = pr.customer_name
        )
    WHERE pr.status = 'approved'
    ORDER BY pr.created_at DESC
    LIMIT 20
");
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading reviews: " . $e->getMessage());
    $reviews = [];
}

/* ===============================
   HELPER FUNCTION: Safe image path
================================ */
function getProductImagePath($image) {
    if (empty($image)) {
        return 'https://via.placeholder.com/400x300?text=Coffee+House';
    }
    
    $cleanImage = str_replace(['uploads/', '/uploads/'], '', $image);
    $fullPath = 'admin/uploads/' . $cleanImage;
    
    return file_exists($fullPath) ? $fullPath : 'https://via.placeholder.com/400x300?text=Coffee+House';
}

/* ===============================
   OPTIMIZATION: Encode data once for JS
================================ */
$productsJson = json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP);
$initialDisplay = 8;
$hasMoreProducts = count($products) > $initialDisplay;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Coffee House – Hương Vị Gặp Nghệ Thuật</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Trải nghiệm hương vị cà phê đẳng cấp thế giới tại Coffee House">
    
    <!-- Preconnect to external resources -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    
    <link href="/cafe/lib/Bootstrap/css/bootstrap.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <link rel="stylesheet" href="./assets/css/home.css">
</head>
<body>

<!-- Scroll to top button -->
<button class="scroll-to-top" id="scrollToTop" aria-label="Scroll to top">
    <i class="fas fa-chevron-up"></i>
</button>

<!-- Background layers -->
<div class="bg-layer bg-day"></div>
<div class="bg-layer bg-night"></div>
<div class="bg-darkness"></div>
<div class="sparkles"></div>

<!-- Floating coffee beans -->
<div class="floating-beans">
    <?php for ($i = 0; $i < 5; $i++): 
        $positions = [10, 25, 45, 65, 80];
        $depths = [0.15, 0.35, 0.55, 0.75, 0.95];
        $delays = [0, 3, 6, 1, 4];
    ?>
    <div class="coffee-bean" 
         data-depth="<?= $depths[$i] ?>" 
         style="--x:<?= $positions[$i] ?>%; --d:<?= $delays[$i] ?>s;">
    </div>
    <?php endfor; ?>
</div>

<?php include './templates/navbarhome.php'; ?>

<!-- Hero Section -->
<section class="hero-premium hero-cinematic" id="home">
    <div class="hero-bg"></div>
    <div class="hero-content reveal">
        <h1>Coffee House</h1>
        <div class="hero-divider"></div>
        <p>Trải nghiệm hương vị cà phê đẳng cấp thế giới trong không gian nghệ thuật.</p>
        <a href="#menu" class="btn btn-outline-light btn-lg mt-3">Khám phá ngay</a>
    </div>
</section>

<!-- Products Section -->
<section id="menu" class="products-premium">
    <div class="container">
        <div class="section-title-premium reveal">
            <h2>Menu Đặc Biệt</h2>
            <p class="text-muted">Khám phá những hương vị độc bản của chúng tôi</p>
        </div>
        
        <div class="row g-4" id="productsContainer">
            <?php foreach ($products as $index => $product): 
                $isHidden = $index >= $initialDisplay ? 'd-none' : '';
                $imgSrc = getProductImagePath($product['image']);
                $now = time();
                
                // Kiểm tra thời hạn giảm giá
                $startDate = $product['discount_start_date'] ? strtotime($product['discount_start_date']) : 0;
                $endDate = $product['discount_end_date'] ? strtotime($product['discount_end_date']) : 2147483647; // Max timestamp
                
                $isPromoActive = ($product['discount_type'] !== 'none' && $now >= $startDate && $now <= $endDate);
                $hasSalePrice = (!empty($product['sale_price']) && $product['sale_price'] < $product['price']);
                $showDiscount = ($isPromoActive && $hasSalePrice);
                
                $isFeatured = ($product['is_featured'] == 1);
            ?>
            <div class="col-md-6 col-lg-4 col-xl-3 product-item reveal <?= $isHidden ?>" data-index="<?= $index ?>">
                <div class="product-card-premium tilt-card" onclick="showProductDetail(<?= $product['id'] ?>)">
                    
                    <div class="product-badges" style="position: absolute; top: 15px; left: 15px; z-index: 10; display: flex; flex-direction: column; gap: 5px;">
                        <?php if ($isFeatured): ?>
                            <span class="badge bg-warning text-dark"><i class="fas fa-crown"></i> Nổi bật</span>
                        <?php endif; ?>
                        
                        <?php if ($showDiscount): ?>
                            <?php 
                                // Tính % giảm thực tế từ sale_price
                                $percent = round((1 - ($product['sale_price'] / $product['price'])) * 100);
                            ?>
                            <span class="badge bg-danger">GIẢM <?= $percent ?>%</span>
                        <?php endif; ?>
                    </div>

                    <div class="product-image-premium">
                        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy">
                    </div>
                    
                    <div class="product-body-premium">
                        <div class="product-category-premium">
                            <?= htmlspecialchars($product['category_name'] ?? 'Cà phê') ?>
                        </div>
                        <h3 class="product-title-premium"><?= htmlspecialchars($product['name']) ?></h3>
                        
                        <div class="product-price-premium">
                            <?php if ($showDiscount): ?>
                                <span class="text-decoration-line-through text-muted small" style="font-size: 0.8em; opacity: 0.7;">
                                    <?= number_format($product['price'], 0, ',', '.') ?>₫
                                </span>
                                <span class="text-danger ms-2 fw-bold">
                                    <?= number_format($product['sale_price'], 0, ',', '.') ?>₫
                                </span>
                            <?php else: ?>
                                <span class="fw-bold"><?= number_format($product['price'], 0, ',', '.') ?>₫</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($hasMoreProducts): ?>
        <div class="text-center mt-5 reveal">
            <button id="toggleProductsBtn" class="btn btn-outline-primary btn-lg px-5">
                <i class="fas fa-plus me-2"></i>Xem thêm sản phẩm
            </button>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Product Detail Modal -->
<div class="modal fade" id="productDetailModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content cinematic-card" 
             style="background: rgba(20, 15, 10, 0.95); color: #fff; border: 1px solid rgba(193, 155, 118, 0.3);">
            <div class="modal-header" style="border-bottom: 1px solid rgba(193, 155, 118, 0.2);">
                <h5 class="modal-title" id="modalTitle">Thông tin sản phẩm</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-5">
                        <img id="detailImage" src="" alt="" class="img-fluid rounded shadow-lg mb-3">
                    </div>
                    <div class="col-md-7">
                        <h3 id="detailName" style="color: #c19b76;"></h3>
                        <p id="detailCategory" class="badge bg-secondary mb-3"></p>
                        <h4 id="detailPrice" class="mb-3"></h4>
                        <div class="mb-3" style="font-size: 0.9rem; border-left: 3px solid #c19b76; padding-left: 15px;">
                            <p class="mb-1"><strong>Thành phần:</strong> <span id="detailIngredients"></span></p>
                            <p class="mb-0"><strong>Năng lượng:</strong> <span id="detailCalories"></span> kcal</p>
                        </div>
                        <p id="detailDescription" class="text-light-50"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reviews Section -->
<section id="reviews" class="reviews-premium">
    <div class="container">
        <div class="section-title-premium reveal">
            <h2>Đánh Giá Từ Khách Hàng</h2>
            <p class="text-muted">Những trải nghiệm chân thực nhất</p>
        </div>
        
        <?php if (count($reviews) > 0): ?>
        <div class="swiper reviewsSwiper reveal">
            <div class="swiper-wrapper">
                <?php foreach ($reviews as $review): ?>
                <div class="swiper-slide">
                    <div class="review-card cinematic-card">
                        <div class="review-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?= ($i <= $review['rating']) ? 'fas' : 'far' ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <h4><?= htmlspecialchars($review['product_name']) ?></h4>
                        <p class="text-muted">"<?= nl2br(htmlspecialchars($review['comment'] ?: 'Tuyệt vời!')) ?>"</p>
                        
                        <?php if (!empty($review['admin_reply'])): ?>
                        <div class="admin-reply mt-3" 
                             style="background: rgba(193, 155, 118, 0.1); padding: 10px; border-radius: 5px; font-size: 0.85rem;">
                            <strong style="color: #c19b76;">
                                <i class="fas fa-reply me-1"></i> Coffee House:
                            </strong>
                            <p class="mb-0 mt-1"><?= htmlspecialchars($review['admin_reply']) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex align-items-center mt-4">
                            <?php
                                $avatarSrc = getUserAvatarPath($review['avatar']);
                                ?>

                                <img 
                                    src="<?= htmlspecialchars($avatarSrc) ?>"
                                    class="rounded-circle me-3"
                                    width="45"
                                    height="45"
                                    alt="Avatar">
                            <div>
                                <h6 class="mb-0"><?= htmlspecialchars($review['customer_name']) ?></h6>
                                <small class="text-muted"><?= date('d/m/Y', strtotime($review['created_at'])) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="swiper-pagination mt-4"></div>
            <div class="swiper-button-next d-none d-md-flex"></div>
            <div class="swiper-button-prev d-none d-md-flex"></div>
        </div>
        <?php else: ?>
        <p class="text-center text-muted">Chưa có đánh giá nào.</p>
        <?php endif; ?>
    </div>
</section>

<!-- Features Section -->
<section id="features" class="features-section pb-5 reveal">
    <div class="container">
        <div class="section-title-premium">
            <h2>Vì Sao Chọn Chúng Tôi?</h2>
        </div>
        <div class="row g-4 text-center">
            <?php 
            $features = [
                ['icon' => '🌟', 'title' => 'Chất lượng', 'desc' => '100% hạt Arabica thượng hạng'],
                ['icon' => '⚡', 'title' => 'Nhanh chóng', 'desc' => 'Phục vụ trong 5 phút'],
                ['icon' => '💝', 'title' => 'Không gian', 'desc' => 'Sang trọng và yên tĩnh'],
                ['icon' => '🎁', 'title' => 'Ưu đãi', 'desc' => 'Tích điểm mỗi ngày']
            ];
            foreach ($features as $feature): 
            ?>
            <div class="col-md-3">
                <div class="feature-card tilt-card p-4">
                    <div class="fs-1 mb-3"><?= $feature['icon'] ?></div>
                    <h4><?= $feature['title'] ?></h4>
                    <p class="small text-muted"><?= $feature['desc'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php include './templates/footer.php'; ?>

<!-- Scripts -->
<script src="/cafe/lib/Bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>

<script>
// Pass PHP data to JavaScript (XSS-safe)
const products = <?= $productsJson ?>;
const initialDisplay = <?= $initialDisplay ?>;

// Product detail modal
async function showProductDetail(productId) {
    let product = products.find(p => p.id == productId);

    if (!product) {
        try {
            const response = await fetch(`customer/get_product.php?id=${productId}`);
            if (response.ok) {
                product = await response.json();
            }
        } catch (err) {
            console.error('Lỗi khi lấy chi tiết sản phẩm:', err);
        }
    }

    if (!product) {
        alert('Không tìm thấy sản phẩm. Vui lòng thử lại.');
        return;
    }

    populateProductModal(product);
}

function populateProductModal(product) {
    const imgSrc = product.image
        ? 'admin/uploads/' + product.image.replace(/^(uploads\/|\/)/, '')
        : 'https://via.placeholder.com/400x300?text=Coffee+House';

    document.getElementById('detailImage').src = imgSrc;
    document.getElementById('detailName').textContent = product.name;
    document.getElementById('detailCategory').textContent = product.category_name || 'Cà phê';

    const priceContainer = document.getElementById('detailPrice');
    const now = new Date();
    const startDate = product.discount_start_date ? new Date(product.discount_start_date) : null;
    const endDate = product.discount_end_date ? new Date(product.discount_end_date) : null;
    const isPromoActive = product.discount_type !== 'none' && 
                          (!startDate || now >= startDate) && 
                          (!endDate || now <= endDate);

    if (isPromoActive && product.sale_price && Number(product.sale_price) < Number(product.price)) {
        priceContainer.innerHTML = `
            <span class="text-decoration-line-through text-muted small" style="font-size: 0.7em;">${Number(product.price).toLocaleString('vi-VN')} ₫</span>
            <span class="text-danger ms-2">${Number(product.sale_price).toLocaleString('vi-VN')} ₫</span>
        `;
    } else {
        priceContainer.textContent = Number(product.price).toLocaleString('vi-VN') + ' ₫';
    }

    document.getElementById('detailIngredients').textContent = product.ingredients || 'Hạt cà phê nguyên chất';
    document.getElementById('detailCalories').textContent = product.calories || '150';
    document.getElementById('detailDescription').textContent = product.description || '';

    const modalEl = document.getElementById('productDetailModal');
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
}

// Swiper initialization
const swiper = new Swiper('.reviewsSwiper', {
    slidesPerView: 1,
    spaceBetween: 30,
    loop: <?= count($reviews) > 3 ? 'true' : 'false' ?>,
    autoplay: { delay: 4000, disableOnInteraction: false },
    pagination: { el: '.swiper-pagination', clickable: true },
    navigation: { 
        nextEl: '.swiper-button-next', 
        prevEl: '.swiper-button-prev' 
    },
    breakpoints: {
        768: { slidesPerView: 2 },
        1024: { slidesPerView: 3 }
    }
});

// Toggle products visibility
const toggleBtn = document.getElementById('toggleProductsBtn');
let isExpanded = false;

if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        const items = document.querySelectorAll('.product-item');
        
        if (!isExpanded) {
            items.forEach(el => el.classList.remove('d-none'));
            this.innerHTML = '<i class="fas fa-minus me-2"></i>Hiển thị bớt';
            isExpanded = true;
        } else {
            items.forEach((el, idx) => {
                if (idx >= initialDisplay) el.classList.add('d-none');
            });
            this.innerHTML = '<i class="fas fa-plus me-2"></i>Xem thêm sản phẩm';
            isExpanded = false;
            document.getElementById('menu').scrollIntoView({ behavior: 'smooth' });
        }
    });
}
</script>

<script src="./assets/js/home.js"></script>
</body>
</html>