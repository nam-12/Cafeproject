<?php
require_once '../config/init.php';

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? $order['customer_name'];

// ========== BƯỚC 1: LẤY VÀ VALIDATE THAM SỐ ==========
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// Kiểm tra order_id có hợp lệ không
if ($order_id <= 0) {
    $_SESSION['error'] = "Đơn hàng không hợp lệ!";
    header('Location: track_order.php');
    exit;
}

// ========== BƯỚC 2: LẤY THÔNG TIN ĐƠN HÀNG ==========
try {
    $order_stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = ? AND status = 'completed'
    ");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Kiểm tra đơn hàng có tồn tại và đã hoàn thành chưa
    if (!$order) {
        $_SESSION['error'] = "Không tìm thấy đơn hàng hoặc đơn hàng chưa hoàn thành!";
        header('Location: track_order.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Lỗi khi lấy thông tin đơn hàng: " . $e->getMessage();
    header('Location: track_order.php');
    exit;
}

// ========== BƯỚC 3: LẤY DANH SÁCH SẢN PHẨM TRONG ĐƠN HÀNG ==========
try {
    $items_stmt = $pdo->prepare("
        SELECT 
            oi.*,
            p.name as product_name,
            p.image as product_image
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $items_stmt->execute([$order_id]);
    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($order_items) == 0) {
        $_SESSION['error'] = "Không tìm thấy sản phẩm trong đơn hàng!";
        header('Location: track_order.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Lỗi khi lấy danh sách sản phẩm: " . $e->getMessage();
    header('Location: track_order.php');
    exit;
}

// ========== BƯỚC 4: XỬ LÝ CHỌN SẢN PHẨM ĐỂ ĐÁNH GIÁ ==========
$selected_product = null;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Nếu chưa chọn sản phẩm, hiển thị danh sách để chọn
if ($product_id <= 0) {
    // Sẽ hiển thị form chọn sản phẩm ở dưới
} else {
    // Kiểm tra sản phẩm có trong đơn hàng không
    foreach ($order_items as $item) {
        if ($item['product_id'] == $product_id) {
            $selected_product = $item;
            break;
        }
    }
    
    if (!$selected_product) {
        $_SESSION['error'] = "Sản phẩm không có trong đơn hàng này!";
        header('Location: review.php?order_id=' . $order_id);
        exit;
    }
}

// ========== BƯỚC 5: KIỂM TRA ĐÃ ĐÁNH GIÁ CHƯA ==========
$existing_review = null;

if ($selected_product) {
    try {
        $check_stmt = $pdo->prepare("
            SELECT *
            FROM product_reviews
            WHERE product_id = ?
            AND (
                    (user_id IS NOT NULL AND user_id = ?)
                OR (user_id IS NULL AND customer_name = ?)
            )
            LIMIT 1
        ");

        $check_stmt->execute([
            $selected_product['product_id'],
            $user_id,
            $username
        ]);
        $existing_review = $check_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Lỗi kiểm tra review: " . $e->getMessage());
    }
}

// ========== BƯỚC 6: XỬ LÝ SUBMIT ĐÁNH GIÁ ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_product) {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $_SESSION['error'] = "Vui lòng chọn số sao từ 1 đến 5!";
    } else {
        try {
            if ($existing_review) {
                // ===== CẬP NHẬT ĐÁNH GIÁ CŨ =====
                $update_stmt = $pdo->prepare("
                    UPDATE product_reviews 
                    SET rating = ?, comment = ?, status = 'approved', updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $rating, 
                    $comment, 
                    $existing_review['id']
                ]);
                $_SESSION['success'] = "Đã cập nhật đánh giá của bạn!";
            } else {
                // ===== TẠO ĐÁNH GIÁ MỚI =====
                $insert_stmt = $pdo->prepare("
                    INSERT INTO product_reviews 
                    (product_id, user_id, customer_name, rating, comment, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'approved', NOW())
                ");

                $insert_stmt->execute([
                    $selected_product['product_id'],
                    $user_id,
                    $username,
                    $rating,
                    $comment
                ]);
                $_SESSION['success'] = "Cảm ơn bạn đã đánh giá sản phẩm!";
            }
            
            // Redirect về trang thành công
            header('Location: review.php?order_id=' . $order_id . '&success=1');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Có lỗi xảy ra khi lưu đánh giá: " . $e->getMessage();
            error_log("Lỗi insert/update review: " . $e->getMessage());
        }
    }
}

// ========== BƯỚC 7: CHUẨN BỊ DỮ LIỆU HIỂN THỊ ==========
$current_rating = $existing_review ? $existing_review['rating'] : 0;
$current_comment = $existing_review ? $existing_review['comment'] : '';
$show_success = isset($_GET['success']) && $_GET['success'] == 1;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đánh giá sản phẩm - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/review.css">
</head>
<body>
    <div class="container">
        <div class="review-container">
            <?php if ($show_success): ?>
            <!-- ========== HIỂN THỊ THÔNG BÁO THÀNH CÔNG ========== -->
            <div class="success-message">
                <div class="success-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h2 style="color: #28a745; margin-bottom: 1rem;">Đánh giá thành công!</h2>
                <p style="color: #6c757d; font-size: 1.1rem; margin-bottom: 2rem;">
                    Cảm ơn bạn đã dành thời gian đánh giá sản phẩm.
                </p>
                <a href="track_order.php?order_id=<?php echo $order_id; ?>" class="btn-submit" style="display: inline-block; width: auto; padding: 12px 30px; text-decoration: none;">
                    <i class="fas fa-arrow-left me-2"></i>
                    Quay lại đơn hàng
                </a>
                <a href="../customer/index.php" class="btn-submit" style="display: inline-block; width: auto; padding: 12px 30px; text-decoration: none; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); margin-left: 10px;">
                    <i class="fas fa-home me-2"></i>
                    Về trang chủ
                </a>
            </div>
            
            <?php elseif (!$selected_product): ?>
            <!-- ========== CHỌN SẢN PHẨM ĐỂ ĐÁNH GIÁ ========== -->
            <div class="review-header">
                <div class="product-icon">
                    <i class="fas fa-star"></i>
                </div>
                <h2>Chọn sản phẩm để đánh giá</h2>
                <p class="text-muted">Đơn hàng: <strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong></p>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>
            
            <div class="mb-4">
                <?php foreach ($order_items as $item): ?>
                <a href="review.php?order_id=<?php echo $order_id; ?>&product_id=<?php echo $item['product_id']; ?>" 
                   class="product-select-card">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($item['product_image'])): ?>
                        <img src="../admin/<?php echo htmlspecialchars($item['product_image']); ?>" 
                             alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                             style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px; margin-right: 1.5rem;">
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <h5 style="margin-bottom: 0.5rem; color: var(--primary-color);">
                                <?php echo htmlspecialchars($item['product_name']); ?>
                            </h5>
                            <p style="margin-bottom: 0; color: #6c757d;">
                                Số lượng: <strong>x<?php echo $item['quantity']; ?></strong> - 
                                Giá: <strong><?php echo number_format($item['price'], 0, ',', '.'); ?>đ</strong>
                            </p>
                        </div>
                        <i class="fas fa-chevron-right" style="color: var(--primary-color); font-size: 1.5rem;"></i>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-3">
                <a href="track_order.php?order_id=<?php echo $order_id; ?>" class="text-muted">
                    <i class="fas fa-arrow-left me-2"></i>
                    Quay lại đơn hàng
                </a>
            </div>
            
            <?php else: ?>
            <!-- ========== FORM ĐÁNH GIÁ SẢN PHẨM ========== -->
            <div class="review-header">
                <div class="product-icon">
                    <i class="fas fa-mug-hot"></i>
                </div>
                <h2>Đánh giá sản phẩm</h2>
                <p class="text-muted">Ý kiến của bạn giúp chúng tôi cải thiện chất lượng dịch vụ</p>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>
            
            <div class="product-info">
                <h5 class="mb-2"><?php echo htmlspecialchars($selected_product['product_name']); ?></h5>
                <p class="text-muted mb-0">
                    <i class="fas fa-receipt me-2"></i>
                    Đơn hàng: <strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong>
                </p>
                <?php if ($existing_review): ?>
                <div class="alert alert-info mt-3 mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>Bạn đã đánh giá sản phẩm này trước đó. Đánh giá mới sẽ thay thế đánh giá cũ.</small>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" id="reviewForm">
                <div class="rating-section">
                    <h5>Bạn đánh giá sản phẩm này như thế nào?</h5>
                    <input type="hidden" name="rating" id="ratingInput" value="<?php echo $current_rating; ?>" required>
                    
                    <div class="star-rating" id="starRating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star star <?php echo $i <= $current_rating ? 'active' : ''; ?>" 
                           data-rating="<?php echo $i; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    
                    <div id="emojiFeedback" class="emoji-feedback"></div>
                    <div id="ratingText" class="rating-text">
                        <?php 
                        if ($current_rating > 0) {
                            $texts = ['', 'Rất tệ', 'Tệ', 'Bình thường', 'Tốt', 'Tuyệt vời'];
                            echo $texts[$current_rating];
                        } else {
                            echo 'Chưa chọn';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="comment-section">
                    <label class="form-label fw-bold">
                        <i class="fas fa-comment-dots me-2"></i>
                        Chia sẻ thêm về trải nghiệm của bạn
                    </label>
                    <textarea name="comment" class="form-control" rows="5" 
                              placeholder="Hãy chia sẻ cảm nhận của bạn về sản phẩm này..."><?php echo htmlspecialchars($current_comment); ?></textarea>
                    <small class="text-muted">Tùy chọn - Giúp người khác hiểu rõ hơn về sản phẩm</small>
                </div>
                
                <button type="submit" class="btn btn-submit" id="submitBtn" <?php echo $current_rating > 0 ? '' : 'disabled'; ?>>
                    <i class="fas fa-paper-plane me-2"></i>
                    <?php echo $existing_review ? 'Cập nhật đánh giá' : 'Gửi đánh giá'; ?>
                </button>
                
                <div class="text-center mt-3">
                    <a href="review.php?order_id=<?php echo $order_id; ?>" class="text-muted">
                        <i class="fas fa-arrow-left me-2"></i>
                        Chọn sản phẩm khác
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($selected_product && !$show_success): ?>
        // JavaScript cho form đánh giá
        const stars = document.querySelectorAll('.star');
        const ratingInput = document.getElementById('ratingInput');
        const ratingText = document.getElementById('ratingText');
        const emojiFeedback = document.getElementById('emojiFeedback');
        const submitBtn = document.getElementById('submitBtn');
        
        const ratingTexts = {
            1: 'Rất tệ',
            2: 'Tệ',
            3: 'Bình thường',
            4: 'Tốt',
            5: 'Tuyệt vời'
        };
        
        const ratingEmojis = {
            1: '😞',
            2: '😕',
            3: '😐',
            4: '😊',
            5: '🤩'
        };
        
        stars.forEach(star => {
            // Click để chọn rating
            star.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                ratingInput.value = rating;
                
                // Update stars
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
                
                // Update text
                ratingText.textContent = ratingTexts[rating];
                
                // Show emoji
                emojiFeedback.textContent = ratingEmojis[rating];
                emojiFeedback.style.display = 'block';
                
                // Enable submit button
                submitBtn.disabled = false;
            });
            
            // Hover effect
            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('hovered');
                    }
                });
            });
            
            star.addEventListener('mouseleave', function() {
                stars.forEach(s => {
                    s.classList.remove('hovered');
                });
            });
        });
        
        // Validate form
        document.getElementById('reviewForm').addEventListener('submit', function(e) {
            const ratingValue = parseInt(ratingInput.value);
            if (!ratingValue || ratingValue < 1 || ratingValue > 5) {
                e.preventDefault();
                alert('Vui lòng chọn số sao đánh giá từ 1 đến 5!');
                return false;
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>