<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem đánh giá
if (!hasPermission('view_reviews')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

// Khởi tạo biến
 $message = '';
 $error = '';
 $success = false;

// Xử lý AJAX request để lấy dữ liệu một đánh giá
if (isset($_GET['action']) && $_GET['action'] === 'get_review' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $id = intval($_GET['id']);
        if ($id <= 0) {
            throw new Exception("ID không hợp lệ!");
        }
        
        $stmt = $pdo->prepare("
            SELECT pr.*, p.name as product_name 
            FROM product_reviews pr
            INNER JOIN products p ON pr.product_id = p.id
            WHERE pr.id = ?
        ");
        $stmt->execute([$id]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$review) {
            throw new Exception("Không tìm thấy đánh giá!");
        }
        
        echo json_encode([
            'success' => true,
            'review' => $review
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý AJAX request để lọc và tìm kiếm
if (isset($_GET['action']) && $_GET['action'] === 'filter_reviews') {
    header('Content-Type: application/json');
    
    try {
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $filter_rating = isset($_GET['rating']) ? $_GET['rating'] : '';
        $filter_replied = isset($_GET['replied']) ? $_GET['replied'] : '';
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        // Đếm tổng số bản ghi
        $countResult = getReviewsCount($pdo, $search, $filter_rating, $filter_replied);
        $totalRecords = $countResult['total'];
        $totalPages = ceil($totalRecords / $limit);
        
        // Lấy dữ liệu cho trang hiện tại
        $reviews = getReviewsList($pdo, $search, $filter_rating, $filter_replied, $limit, $offset);
        
        // Xử lý dữ liệu để trả về
        $reviewsHtml = '';
        if (empty($reviews)) {
            $reviewsHtml = '
                <div class="text-center py-5">
                    <div class="empty-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3 class="text-muted">Không có đánh giá nào</h3>
                    <p>Không tìm thấy đánh giá nào phù hợp với bộ lọc.</p>
                </div>
            ';
        } else {
            foreach ($reviews as $review) {
                // Generate star rating HTML
                $starsHtml = '';
                for ($i = 1; $i <= 5; $i++) {
                    $starsHtml .= '<i class="fas fa-star ' . ($i <= $review['rating'] ? '' : 'text-muted') . '"></i>';
                }
                
                // Check if review has admin reply
                $hasReply = !empty($review['admin_reply']);
                
                $reviewsHtml .= '
                    <div class="review-card">
                        <div class="review-header">
                            <div class="product-info">
                                <h5 class="product-name">
                                    <i class="fas fa-mug-hot text-primary me-2"></i>
                                    ' . htmlspecialchars($review['product_name']) . '
                                </h5>
                                <div class="star-rating mb-2">
                                    ' . $starsHtml . '
                                    <span class="ms-2">(' . $review['rating'] . '/5)</span>
                                </div>
                            </div>
                            <div class="review-meta">
                                <div class="customer-info">
                                    <i class="fas fa-user me-1"></i>' . htmlspecialchars($review['customer_name']) . '
                                    ' . ($review['customer_email'] ? '<i class="fas fa-envelope ms-3 me-1"></i>' . htmlspecialchars($review['customer_email']) : '') . '
                                </div>
                                <div class="review-date">
                                    <i class="fas fa-clock me-1"></i>' . formatDate($review['created_at']) . '
                                </div>
                            </div>
                        </div>
                        
                        ' . ($review['comment'] ? '<div class="review-comment">' . nl2br(htmlspecialchars($review['comment'])) . '</div>' : '') . '
                        
                        ' . ($hasReply ? '
                            <div class="admin-reply-box">
                                <div class="reply-header">
                                    <strong><i class="fas fa-reply me-2"></i>Phản hồi của bạn:</strong>
                                    <button class="btn-action btn-delete" onclick="confirmDeleteReply(' . $review['id'] . ')" title="Xóa phản hồi">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <div class="reply-content">' . nl2br(htmlspecialchars($review['admin_reply'])) . '</div>
                                <div class="reply-date">
                                    <i class="fas fa-clock me-1"></i>' . formatDate($review['replied_at']) . '
                                </div>
                            </div>
                        ' : '') . '
                        
                        <div class="reply-form-container">
                            <button class="btn-toggle-reply" onclick="toggleReplyForm(' . $review['id'] . ')">
                                <i class="fas fa-comment-dots me-1"></i>
                                ' . ($hasReply ? 'Sửa phản hồi' : 'Thêm phản hồi') . '
                            </button>
                            <div class="reply-form" id="reply-form-' . $review['id'] . '" style="display: none;">
                                <form method="POST" class="reply-form-inner">
                                    <input type="hidden" name="review_id" value="' . $review['id'] . '">
                                    <div class="mb-2">
                                        <textarea name="reply" class="form-control" rows="3" 
                                                  placeholder="Nhập phản hồi của bạn...">' . ($hasReply ? htmlspecialchars($review['admin_reply']) : '') . '</textarea>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="reply_action" class="btn-modal btn-primary-modal">
                                            <i class="fas fa-paper-plane me-1"></i>
                                            ' . ($hasReply ? 'Cập nhật phản hồi' : 'Gửi phản hồi') . '
                                        </button>
                                        <button type="button" class="btn-modal btn-secondary-modal" onclick="toggleReplyForm(' . $review['id'] . ')">
                                            <i class="fas fa-times me-1"></i>
                                            Hủy
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                ';
            }
        }
        
        // Tạo HTML cho phân trang
        $paginationHtml = '';
        if ($totalPages > 1) {
            $paginationHtml = '<div class="pagination-container"><div class="pagination">';
            
            if ($page > 1) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . ($page - 1) . ')"><i class="fas fa-chevron-left"></i></a>';
            } else {
                $paginationHtml .= '<div class="page-item disabled"><i class="fas fa-chevron-left"></i></div>';
            }
            
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(1)">1</a>';
                if ($startPage > 2) {
                    $paginationHtml .= '<div class="page-item disabled">...</div>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                $activeClass = $i == $page ? 'active' : '';
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item ' . $activeClass . '" onclick="loadPage(' . $i . ')">' . $i . '</a>';
            }
            
            if ($endPage < $totalPages) {
                if ($endPage < $totalPages - 1) {
                    $paginationHtml .= '<div class="page-item disabled">...</div>';
                }
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . $totalPages . ')">' . $totalPages . '</a>';
            }
            
            if ($page < $totalPages) {
                $paginationHtml .= '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . ($page + 1) . ')"><i class="fas fa-chevron-right"></i></a>';
            } else {
                $paginationHtml .= '<div class="page-item disabled"><i class="fas fa-chevron-right"></i></div>';
            }
            
            $paginationHtml .= '</div></div>';
        }
        
        echo json_encode([
            'success' => true,
            'reviewsHtml' => $reviewsHtml,
            'paginationHtml' => $paginationHtml,
            'totalRecords' => $totalRecords
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý thêm/sửa phản hồi
if (isset($_POST['reply_action']) && isset($_POST['review_id'])) {
    // Kiểm tra quyền phản hồi đánh giá
    if (!hasPermission('respond_to_review')) {
        $_SESSION['error'] = 'Bạn không có quyền phản hồi đánh giá!';
        header('Location: reviews.php');
        exit;
    }
    
    $review_id = (int) $_POST['review_id'];
    $reply = trim($_POST['reply']);

    if (!empty($reply)) {
        $stmt = $pdo->prepare("UPDATE product_reviews SET admin_reply = ?, replied_at = NOW() WHERE id = ?");
        $stmt->execute([$reply, $review_id]);
        $message = "Đã thêm phản hồi!";
        $success = true;
    } else {
        $error = "Vui lòng nhập nội dung phản hồi!";
    }

    header('Location: reviews.php');
    exit;
}

// Xử lý xóa phản hồi
if (isset($_GET['delete_reply'])) {
    // Kiểm tra quyền
    if (!hasPermission('respond_to_review')) {
        $_SESSION['error'] = 'Bạn không có quyền xóa phản hồi!';
        header('Location: reviews.php');
        exit;
    }
    
    $review_id = (int) $_GET['delete_reply'];
    $stmt = $pdo->prepare("UPDATE product_reviews SET admin_reply = NULL, replied_at = NULL WHERE id = ?");
    $stmt->execute([$review_id]);
    $message = "Đã xóa phản hồi!";
    $success = true;
    header('Location: reviews.php');
    exit;
}

// Thiết lập phân trang
 $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
 $limit = 10;
 $offset = ($page - 1) * $limit;

// Lấy danh sách đánh giá với phân trang
 $search = isset($_GET['search']) ? trim($_GET['search']) : '';
 $filter_rating = isset($_GET['rating']) ? $_GET['rating'] : '';
 $filter_replied = isset($_GET['replied']) ? $_GET['replied'] : '';

// Đếm tổng số bản ghi
 $countResult = getReviewsCount($pdo, $search, $filter_rating, $filter_replied);
 $totalRecords = $countResult['total'];
 $totalPages = ceil($totalRecords / $limit);

// Lấy dữ liệu cho trang hiện tại
 $reviews = getReviewsList($pdo, $search, $filter_rating, $filter_replied, $limit, $offset);

// Lấy thống kê tổng quan
 $stats = getReviewsStats($pdo);

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Các hàm xử lý
function getReviewsCount($pdo, $search, $filter_rating, $filter_replied) {
    $countSql = "SELECT COUNT(*) as total FROM product_reviews pr
                 INNER JOIN products p ON pr.product_id = p.id
                 WHERE 1=1";
    $countParams = [];

    if ($search) {
        $countSql .= " AND (pr.customer_name LIKE ? OR pr.customer_email LIKE ? OR p.name LIKE ?)";
        $countParams[] = "%$search%";
        $countParams[] = "%$search%";
        $countParams[] = "%$search%";
    }

    if ($filter_rating) {
        $countSql .= " AND pr.rating = ?";
        $countParams[] = $filter_rating;
    }

    if ($filter_replied === 'yes') {
        $countSql .= " AND pr.admin_reply IS NOT NULL";
    } elseif ($filter_replied === 'no') {
        $countSql .= " AND pr.admin_reply IS NULL";
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    return $countStmt->fetch(PDO::FETCH_ASSOC);
}

function getReviewsList($pdo, $search, $filter_rating, $filter_replied, $limit, $offset) {
    $sql = "SELECT pr.*, p.name as product_name 
            FROM product_reviews pr
            INNER JOIN products p ON pr.product_id = p.id
            WHERE 1=1";
    $params = [];

    if ($search) {
        $sql .= " AND (pr.customer_name LIKE ? OR pr.customer_email LIKE ? OR p.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter_rating) {
        $sql .= " AND pr.rating = ?";
        $params[] = $filter_rating;
    }

    if ($filter_replied === 'yes') {
        $sql .= " AND pr.admin_reply IS NOT NULL";
    } elseif ($filter_replied === 'no') {
        $sql .= " AND pr.admin_reply IS NULL";
    }

    $sql .= " ORDER BY pr.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getReviewsStats($pdo) {
    $totalReviews = $pdo->query("SELECT COUNT(*) as count FROM product_reviews")->fetch(PDO::FETCH_ASSOC)['count'];
    $repliedCount = $pdo->query("SELECT COUNT(*) as count FROM product_reviews WHERE admin_reply IS NOT NULL")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Calculate average rating
    $avgRating = $pdo->query("SELECT AVG(rating) as avg FROM product_reviews")->fetch(PDO::FETCH_ASSOC)['avg'];
    $avgRating = round($avgRating, 1);
    
    // Get rating distribution
    $ratingDistribution = [];
    for ($i = 1; $i <= 5; $i++) {
        $count = $pdo->prepare("SELECT COUNT(*) as count FROM product_reviews WHERE rating = ?");
        $count->execute([$i]);
        $ratingDistribution[$i] = $count->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    return [
        'total_reviews' => $totalReviews,
        'replied_count' => $repliedCount,
        'avg_rating' => $avgRating,
        'rating_distribution' => $ratingDistribution
    ];
}

// Hàm tạo URL phân trang
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Đánh giá - Cafe Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/cssad/reviews.css">
</head>
<body>
    <!-- Loading Spinner -->
    <div class="spinner-container" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="layout-container">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header-section">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div>
                        <h1>Quản Lý Đánh Giá</h1>
                        <p>Xem và phản hồi các đánh giá từ khách hàng</p>
                    </div>
                </div>
            </div>

            <!-- Alert -->
            <div id="alertContainer">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?>" id="messageAlert">
                        <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_reviews']); ?></div>
                            <div class="stat-label">Tổng đánh giá</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['replied_count']); ?></div>
                            <div class="stat-label">Đã phản hồi</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-reply"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo $stats['avg_rating']; ?>/5</div>
                            <div class="stat-label">Điểm trung bình</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total_reviews'] - $stats['replied_count']); ?></div>
                            <div class="stat-label">Chưa phản hồi</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rating Distribution -->
            <div class="rating-distribution">
                <h3>Phân bố đánh giá</h3>
                <div class="rating-bars">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <div class="rating-bar-item">
                            <div class="rating-label">
                                <?php echo $i; ?> <i class="fas fa-star"></i>
                            </div>
                            <div class="rating-progress">
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $stats['total_reviews'] > 0 ? ($stats['rating_distribution'][$i] / $stats['total_reviews'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            <div class="rating-count">
                                <?php echo $stats['rating_distribution'][$i]; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Search & Filter -->
            <div class="search-filter-section">
                <form id="filterForm">
                    <div class="search-container">
                        <div class="search-box">
                            <i class="fas fa-search search-icon-input"></i>
                            <input type="text" class="search-input" id="searchInput" placeholder="Tìm kiếm theo tên khách hàng, email hoặc sản phẩm..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <select class="filter-select" id="ratingFilter">
                            <option value="">Tất cả đánh giá</option>
                            <option value="5" <?php echo $filter_rating === '5' ? 'selected' : ''; ?>>5 sao</option>
                            <option value="4" <?php echo $filter_rating === '4' ? 'selected' : ''; ?>>4 sao</option>
                            <option value="3" <?php echo $filter_rating === '3' ? 'selected' : ''; ?>>3 sao</option>
                            <option value="2" <?php echo $filter_rating === '2' ? 'selected' : ''; ?>>2 sao</option>
                            <option value="1" <?php echo $filter_rating === '1' ? 'selected' : ''; ?>>1 sao</option>
                        </select>
                        <select class="filter-select" id="repliedFilter">
                            <option value="">Tất cả trạng thái</option>
                            <option value="yes" <?php echo $filter_replied === 'yes' ? 'selected' : ''; ?>>Đã phản hồi</option>
                            <option value="no" <?php echo $filter_replied === 'no' ? 'selected' : ''; ?>>Chưa phản hồi</option>
                        </select>
                        <button type="button" class="btn-modal btn-primary-modal" onclick="applyFilters()">
                            <i class="fas fa-filter"></i>
                            Lọc
                        </button>
                        <button type="button" class="btn-modal btn-secondary-modal" onclick="resetFilters()">
                            <i class="fas fa-redo"></i>
                            Đặt lại
                        </button>
                    </div>
                </form>
            </div>

            <!-- Reviews List -->
            <div class="reviews-container" id="reviewsContainer">
                <?php if (empty($reviews)): ?>
                    <div class="text-center py-5">
                        <div class="empty-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <h3 class="text-muted">Không có đánh giá nào</h3>
                        <p>Chưa có đánh giá nào được tạo.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="product-info">
                                    <h5 class="product-name">
                                        <i class="fas fa-mug-hot text-primary me-2"></i>
                                        <?php echo htmlspecialchars($review['product_name']); ?>
                                    </h5>
                                    <div class="star-rating mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $review['rating'] ? '' : 'text-muted'; ?>"></i>
                                        <?php endfor; ?>
                                        <span class="ms-2">(<?php echo $review['rating']; ?>/5)</span>
                                    </div>
                                </div>
                                <div class="review-meta">
                                    <div class="customer-info">
                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($review['customer_name']); ?>
                                        <?php if ($review['customer_email']): ?>
                                            <i class="fas fa-envelope ms-3 me-1"></i><?php echo htmlspecialchars($review['customer_email']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="review-date">
                                        <i class="fas fa-clock me-1"></i><?php echo formatDate($review['created_at']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($review['comment']): ?>
                                <div class="review-comment">
                                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($review['admin_reply'])): ?>
                                <div class="admin-reply-box">
                                    <div class="reply-header">
                                        <strong><i class="fas fa-reply me-2"></i>Phản hồi của bạn:</strong>
                                        <button class="btn-action btn-delete" onclick="confirmDeleteReply(<?php echo $review['id']; ?>)" title="Xóa phản hồi">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($review['admin_reply'])); ?>
                                    </div>
                                    <div class="reply-date">
                                        <i class="fas fa-clock me-1"></i><?php echo formatDate($review['replied_at']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="reply-form-container">
                                <button class="btn-toggle-reply" onclick="toggleReplyForm(<?php echo $review['id']; ?>)">
                                    <i class="fas fa-comment-dots me-1"></i>
                                    <?php echo !empty($review['admin_reply']) ? 'Sửa phản hồi' : 'Thêm phản hồi'; ?>
                                </button>
                                <div class="reply-form" id="reply-form-<?php echo $review['id']; ?>" style="display: none;">
                                    <form method="POST" class="reply-form-inner">
                                        <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                        <div class="mb-2">
                                            <textarea name="reply" class="form-control" rows="3" 
                                                      placeholder="Nhập phản hồi của bạn..."><?php echo !empty($review['admin_reply']) ? htmlspecialchars($review['admin_reply']) : ''; ?></textarea>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="reply_action" class="btn-modal btn-primary-modal">
                                                <i class="fas fa-paper-plane me-1"></i>
                                                <?php echo !empty($review['admin_reply']) ? 'Cập nhật phản hồi' : 'Gửi phản hồi'; ?>
                                            </button>
                                            <button type="button" class="btn-modal btn-secondary-modal" onclick="toggleReplyForm(<?php echo $review['id']; ?>)">
                                                <i class="fas fa-times me-1"></i>
                                                Hủy
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <div id="paginationContainer">
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="javascript:void(0)" class="page-item" onclick="loadPage(<?php echo $page - 1; ?>)">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <div class="page-item disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </div>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<a href="javascript:void(0)" class="page-item" onclick="loadPage(1)">1</a>';
                                if ($startPage > 2) {
                                    echo '<div class="page-item disabled">...</div>';
                                }
                            }

                            for ($i = $startPage; $i <= $endPage; $i++) {
                                $activeClass = $i == $page ? 'active' : '';
                                echo '<a href="javascript:void(0)" class="page-item ' . $activeClass . '" onclick="loadPage(' . $i . ')">' . $i . '</a>';
                            }

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<div class="page-item disabled">...</div>';
                                }
                                echo '<a href="javascript:void(0)" class="page-item" onclick="loadPage(' . $totalPages . ')">' . $totalPages . '</a>';
                            }
                            ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="javascript:void(0)" class="page-item" onclick="loadPage(<?php echo $page + 1; ?>)">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <div class="page-item disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Xóa phản hồi -->
    <div class="modal fade" id="deleteReplyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Xác nhận xóa phản hồi
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Bạn có chắc chắn muốn xóa phản hồi này không?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal btn-secondary-modal" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                        Hủy
                    </button>
                    <a href="" id="deleteReplyLink" class="btn-modal btn-primary-modal" style="background-color: #fee2e2; color: #991b1b;">
                        <i class="fas fa-trash"></i>
                        Xóa
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script >// Current filter values
let currentFilters = {
    search: '<?php echo htmlspecialchars($search); ?>',
    rating: '<?php echo $filter_rating; ?>',
    replied: '<?php echo $filter_replied; ?>',
    page: <?php echo $page; ?>
};

// Show loading spinner
function showLoading() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

// Hide loading spinner
function hideLoading() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

// Show alert message
function showAlert(message, type = 'success') {
    const alertContainer = document.getElementById('alertContainer');
    const alertElement = document.createElement('div');
    alertElement.className = `alert alert-${type}`;
    alertElement.id = 'dynamicAlert';
    
    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    alertElement.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
    `;
    
    alertContainer.appendChild(alertElement);
    
    // Auto hide after 2 seconds
    setTimeout(() => {
        alertElement.style.opacity = '0';
        setTimeout(() => {
            alertElement.remove();
        }, 300);
    }, 2000);
}

// Apply filters using AJAX
function applyFilters() {
    currentFilters.search = document.getElementById('searchInput').value;
    currentFilters.rating = document.getElementById('ratingFilter').value;
    currentFilters.replied = document.getElementById('repliedFilter').value;
    currentFilters.page = 1; // Reset to first page when applying new filters
    
    loadReviews();
}

// Reset filters
function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('ratingFilter').value = '';
    document.getElementById('repliedFilter').value = '';
    
    currentFilters = {
        search: '',
        rating: '',
        replied: '',
        page: 1
    };
    
    loadReviews();
}

// Load reviews with current filters
function loadReviews() {
    showLoading();
    
    const params = new URLSearchParams({
        action: 'filter_reviews',
        search: currentFilters.search,
        rating: currentFilters.rating,
        replied: currentFilters.replied,
        page: currentFilters.page
    });
    
    fetch(`reviews.php?${params.toString()}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Update reviews container
            document.getElementById('reviewsContainer').innerHTML = data.reviewsHtml;
            
            // Update pagination
            document.getElementById('paginationContainer').innerHTML = data.paginationHtml;
        } else {
            showAlert(data.message, 'danger');
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Đã xảy ra lỗi khi tải dữ liệu. Vui lòng thử lại.', 'danger');
        hideLoading();
    });
}

// Load specific page
function loadPage(page) {
    currentFilters.page = page;
    loadReviews();
}

// Toggle reply form
function toggleReplyForm(reviewId) {
    const form = document.getElementById(`reply-form-${reviewId}`);
    if (form.style.display === 'none') {
        form.style.display = 'block';
    } else {
        form.style.display = 'none';
    }
}

// Confirm delete reply
function confirmDeleteReply(reviewId) {
    document.getElementById('deleteReplyLink').href = `?delete_reply=${reviewId}`;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteReplyModal'));
    modal.show();
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide initial alert after 2 seconds
    const initialAlert = document.getElementById('messageAlert');
    if (initialAlert) {
        setTimeout(() => {
            initialAlert.style.opacity = '0';
            setTimeout(() => {
                initialAlert.remove();
            }, 300);
        }, 2000);
    }
    
    // Add search on Enter key
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyFilters();
        }
    });
    
    // Handle form submissions with loading spinner
    document.querySelectorAll('.reply-form-inner').forEach(form => {
        form.addEventListener('submit', function() {
            showLoading();
        });
    });
});

// Mobile menu toggle
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('active');
}</script>
</body>
</html>