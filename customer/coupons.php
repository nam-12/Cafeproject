<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// Giả định bạn đã có session user_id sau khi đăng nhập
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header("Location: login.php");
    exit;
}

// 1. LẤY DANH SÁCH TẤT CẢ COUPON ĐANG ACTIVE (Bao gồm cả mã chưa đến giờ bắt đầu)
$sql_all = "SELECT * FROM coupon_statistics 
            WHERE status COLLATE utf8mb4_unicode_ci = 'active' 
            AND (display_status COLLATE utf8mb4_unicode_ci = 'Đang hoạt động' 
                 OR display_status COLLATE utf8mb4_unicode_ci = 'Chưa bắt đầu')
            AND NOW() <= end_date
            ORDER BY start_date ASC";
$stmt_all = $pdo->query($sql_all);
$all_coupons = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

// 2. LẤY TOÀN BỘ ID MÃ MÀ USER NÀY ĐÃ TỪNG THU THẬP
$sql_collected = "SELECT coupon_id FROM user_coupons WHERE user_id = ?";
$stmt_collected = $pdo->prepare($sql_collected);
$stmt_collected->execute([$user_id]);
$collected_history_ids = $stmt_collected->fetchAll(PDO::FETCH_COLUMN);

// 3. LẤY DANH SÁCH COUPON USER ĐÃ THU THẬP VÀ CÒN DÙNG ĐƯỢC
$sql_my = "SELECT cs.* FROM user_coupons uc 
           JOIN coupon_statistics cs ON uc.coupon_id = cs.id 
           WHERE uc.user_id = ? 
           AND uc.is_used = 0 
           AND cs.status COLLATE utf8mb4_unicode_ci = 'active'
           AND NOW() <= cs.end_date";
$stmt_my = $pdo->prepare($sql_my);
$stmt_my->execute([$user_id]);
$my_coupons = $stmt_my->fetchAll(PDO::FETCH_ASSOC);

$navbar_type = 'coupons';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ưu đãi & Mã giảm giá - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/coupons.css">
    <style>
        .nav-tabs-premium { border: none; justify-content: center; gap: 20px; }
        .nav-tabs-premium .nav-link { 
            border: none; color: #666; font-weight: 600; padding: 10px 25px; 
            border-radius: 30px; background: #eee;
        }
        .nav-tabs-premium .nav-link.active { 
            background: var(--primary-color, #6F4E37); color: white !important; 
        }
        .btn-collect { width: 100%; border-radius: 8px; font-weight: bold; transition: all 0.3s ease; }
        
        /* Style cho mã sắp diễn ra */
        .btn-future { border: 2px dashed #ffc107; color: #856404; background: #fff3cd; }
        .btn-future:hover { background: #ffeeba; color: #856404; border-style: solid; }

        #btnCollectAll { transition: all 0.3s ease; }
        #btnCollectAll:hover { transform: scale(1.05); }
        
        .coupon-card { transition: transform 0.3s ease; }
        .coupon-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>

    <?php include '../templates/navbar.php'; ?>

    <div class="container my-5">
        <div class="header-section text-center mb-5">
            <h1 class="display-5 fw-bold text-brown">Kho Voucher Ưu Đãi</h1>
            <p class="text-muted">Nhận ngay các mã giảm giá đặc quyền dành riêng cho bạn</p>
            <button id="btnCollectAll" class="btn btn-warning fw-bold px-5 py-2 shadow-sm" onclick="collectAllCoupons()">
                <i class="fas fa-layer-group me-2"></i>NHẬN TẤT CẢ ƯU ĐÃI
            </button>
        </div>

        <ul class="nav nav-tabs nav-tabs-premium mb-5" id="couponTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-content">
                    <i class="fas fa-store me-2"></i>Kho Ưu Đãi
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="my-tab" data-bs-toggle="tab" data-bs-target="#my-content">
                    <i class="fas fa-ticket-alt me-2"></i>Ưu đãi của tôi (<span id="myCount"><?= count($my_coupons) ?></span>)
                </button>
            </li>
        </ul>

        <div class="tab-content" id="couponTabsContent">
            <div class="tab-pane fade show active" id="all-content">
                <div class="row g-4">
                    <?php foreach ($all_coupons as $cp): 
                        $now = new DateTime();
                        $start = new DateTime($cp['start_date']);
                        $isCollected = in_array($cp['id'], $collected_history_ids);
                        $isFuture = $now < $start; // Kiểm tra mã chưa diễn ra
                    ?>
                        <div class="col-lg-6 col-xl-4">
                            <div class="coupon-card">
                                <div class="coupon-left <?= $isFuture ? 'bg-secondary opacity-75' : '' ?>">
                                    <div class="coupon-icon"><i class="fas <?= $isFuture ? 'fa-clock' : 'fa-gift' ?>"></i></div>
                                    <div class="coupon-type"><?= $cp['discount_type'] == 'percentage' ? 'GIẢM %' : 'GIẢM TIỀN' ?></div>
                                </div>
                                <div class="coupon-right">
                                    <h5 class="coupon-title"><?= htmlspecialchars($cp['name']) ?></h5>
                                    <p class="coupon-desc mb-2"><?= htmlspecialchars($cp['description']) ?></p>
                                    
                                    <div class="coupon-action">
                                        <?php if ($isCollected): ?>
                                            <button class="btn btn-secondary btn-collect" disabled>Đã thu thập</button>
                                        <?php elseif ($isFuture): ?>
                                            <button class="btn btn-future btn-collect" 
                                                    onclick="collectCoupon(<?= $cp['id'] ?>, this, true)">
                                                <small>SẮP DIỄN RA: <?= $start->format('d/m H:i') ?></small>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-primary btn-collect" 
                                                    onclick="collectCoupon(<?= $cp['id'] ?>, this, false)">
                                                NHẬN MÃ
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="my-content">
                <div class="row g-4" id="myCouponsList">
                    <?php if (empty($my_coupons)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Bạn không có mã giảm giá nào khả dụng.</p>
                        </div>
                    <?php else: foreach ($my_coupons as $cp): ?>
                        <div class="col-lg-6 col-xl-4">
                            <div class="coupon-card">
                                <div class="coupon-left" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                                    <div class="coupon-icon"><i class="fas fa-check-circle"></i></div>
                                    <div class="coupon-type">SẴN SÀNG</div>
                                </div>
                                <div class="coupon-right">
                                    <h5 class="coupon-title"><?= htmlspecialchars($cp['name']) ?></h5>
                                    <div class="code-box">
                                        <span class="code-text"><?= $cp['code'] ?></span>
                                        <button class="btn-copy" onclick="copyCoupon('<?= $cp['code'] ?>', this)">SAO CHÉP</button>
                                    </div>
                                    <div class="coupon-expiry mt-2 small text-danger">
                                        <i class="far fa-clock me-1"></i>HSD: <?= date('d/m/Y', strtotime($cp['end_date'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
        <div id="statusToast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/couponsu.js"></script>
</body>
</html>