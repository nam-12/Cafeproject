<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// 1. Kiểm tra giỏ hàng
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit;
}

// 2. Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// 3. Lấy danh sách phương thức thanh toán
$db = getDB();
$stmtPayments = $db->query("SELECT * FROM payment_methods WHERE active = 1 ORDER BY id");
$paymentMethods = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

// 4. Lấy danh sách mã giảm giá và SẮP XẾP
$subtotal = getCartTotal();

$stmtCoupons = $pdo->prepare("
    SELECT cs.* FROM user_coupons uc 
    JOIN coupon_statistics cs ON uc.coupon_id = cs.id 
    WHERE uc.user_id = ? AND uc.is_used = 0 
    AND cs.status = 'active' AND NOW() BETWEEN cs.start_date AND cs.end_date
    ORDER BY (CASE WHEN cs.min_order_value <= ? THEN 0 ELSE 1 END) ASC
");
$stmtCoupons->execute([$user_id, $subtotal]);
$availableCoupons = $stmtCoupons->fetchAll(PDO::FETCH_ASSOC);

$cart = $_SESSION['cart'];
$selectedItems = [];
if (!empty($_GET['selected_items'])) {
    $selectedItems = array_filter(array_map('intval', explode(',', $_GET['selected_items'])));
    if (!empty($selectedItems)) {
        $cart = array_filter($cart, function ($item) use ($selectedItems) {
            return in_array((int)$item['id'], $selectedItems, true);
        });
    }
}

if (empty($cart)) {
    $_SESSION['error'] = 'Vui lòng chọn ít nhất 1 sản phẩm trong giỏ hàng để thanh toán.';
    header('Location: cart.php');
    exit;
}

$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

$shippingFee = calculateShippingFee($subtotal);
$total = $subtotal + $shippingFee;

$checkout_data = $_SESSION['checkout_data'] ?? [];
unset($_SESSION['checkout_data']); 

$cart_count = array_sum(array_column($cart, 'quantity'));
$navbar_type = 'checkout';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thanh toán - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/navbar.css">
    <link rel="stylesheet" href="../assets/css/checkout.css">
    <style>
        /* Tùy chỉnh màu sắc Input - Chữ đen trên nền trắng */
        .coupon-section .input-group .form-control {
            background: #ffffff !important;
            color: #000000 !important;
            font-weight: bold;
            border: 2px solid #a2836e;
        }

        /* Danh sách Voucher */
        #voucherList { display: none; margin-top: 15px; }
        .coupon-item { 
            background: #ffffff; /* Nền trắng để chữ đen nổi bật */
            border-radius: 8px; 
            padding: 12px; 
            margin-bottom: 10px;
            cursor: pointer; 
            border: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #000000; /* Chữ màu đen toàn bộ */
        }
        .coupon-item:hover { border-color: #a2836e; background: #fdfaf8; }
        
        .coupon-item.disabled {
            background: #f0f0f0;
            opacity: 0.7;
            cursor: not-allowed;
            border-style: dashed;
        }

        .coupon-icon { color: #a2836e; font-size: 1.4rem; }
        .coupon-info { flex-grow: 1; }
        .coupon-code-text { font-weight: 800; color: #a2836e; font-size: 1.1rem; }
        .coupon-desc { font-size: 0.85rem; color: #333; display: block; margin-bottom: 4px; }
        
        .status-tag { 
            font-size: 0.7rem; 
            padding: 2px 8px; 
            border-radius: 20px; 
            font-weight: bold; 
            text-transform: uppercase;
        }
        .tag-available { background: #d4edda; color: #155724; }
        .tag-locked { background: #fff3cd; color: #856404; }

        .btn-show-vouchers {
            background: transparent;
            color: #a2836e;
            border: 1px solid #a2836e;
            font-size: 0.8rem;
            margin-top: 5px;
            transition: 0.3s;
        }
        .btn-show-vouchers:hover { background: #a2836e; color: #fff; }
    </style>
</head>
<body>
<?php include '../templates/navbar.php'; ?>

    <div class="container mb-5">
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="checkout-container">
            <h2 class="mb-4"><i class="fas fa-credit-card me-3"></i>Thanh toán đơn hàng</h2>

            <form method="POST" action="create_order.php" id="checkoutForm">
                <input type="hidden" name="selected_items" value="<?php echo htmlspecialchars($_GET['selected_items'] ?? ''); ?>">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="section-header">
                            <h5><i class="fas fa-user me-2"></i>Thông tin khách hàng</h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ và tên <span class="text-danger">*</span></label>
                                <input type="text" name="customer_name" class="form-control" required 
                                       placeholder="Nguyễn Văn A"
                                       value="<?php echo htmlspecialchars($checkout_data['customer_name'] ?? $user['username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Số điện thoại <span class="text-danger">*</span></label>
                                <input type="tel" name="customer_phone" class="form-control" required 
                                       placeholder="0123456789"
                                       value="<?php echo htmlspecialchars($checkout_data['customer_phone'] ?? $user['phone'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="customer_email" class="form-control" required 
                                   placeholder="email@example.com"
                                   value="<?php echo htmlspecialchars($checkout_data['customer_email'] ?? $user['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Địa chỉ giao hàng <span class="text-danger">*</span></label>
                            <textarea name="shipping_address" class="form-control" rows="2" required 
                                      placeholder="Số nhà, đường..."><?php echo htmlspecialchars($checkout_data['shipping_address'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Ghi chú đơn hàng</label>
                            <textarea name="note" class="form-control" rows="3" 
                                      placeholder="Ghi chú thêm (tùy chọn)"><?php echo htmlspecialchars($checkout_data['note'] ?? ''); ?></textarea>
                        </div>

                        <div class="section-header">
                            <h5><i class="fas fa-wallet me-2"></i>Phương thức thanh toán</h5>
                        </div>

                        <?php 
                        $selected_method = $checkout_data['payment_method'] ?? ($paymentMethods[0]['code'] ?? 'COD'); 
                        foreach ($paymentMethods as $method): 
                        ?>
                        <div class="payment-option">
                            <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($method['code']); ?>" 
                                   id="method-<?php echo $method['id']; ?>" <?php echo $method['code'] === $selected_method ? 'checked' : ''; ?>>
                            <label for="method-<?php echo $method['id']; ?>" class="w-100 cursor-pointer">
                                <div class="d-flex align-items-center">
                                    <div class="payment-icon me-3">
                                        <i class="fas <?php echo ($method['code'] === 'COD') ? 'fa-truck' : 'fa-university'; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($method['name']); ?></div>
                                        <small class="text-muted">
                                            <?php echo ($method['code'] === 'COD') ? 'Thanh toán khi nhận hàng' : 'Thanh toán qua ngân hàng/QR'; ?>
                                        </small>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-lg-5">
                        <div class="order-summary">
                            <h5 class="mb-4"><i class="fas fa-receipt me-2"></i>Đơn hàng (<?php echo $cart_count; ?>)</h5>
                            
                            <div class="order-items-list mb-4" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($cart as $item): ?>
                                <div class="order-item d-flex justify-content-between mb-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                        <small class="text-muted">x<?php echo $item['quantity']; ?> - <?php echo formatCurrency($item['price']); ?></small>
                                    </div>
                                    <div class="text-end fw-bold"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="coupon-section">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label small fw-bold text-uppercase mb-0" style="color: #a2836e;">Mã giảm giá</label>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="toggleVoucherList()" style="color: #a2836e;">
                                        <i class="fas fa-ticket-alt me-1"></i>Voucher của bạn
                                    </button>
                                </div>

                                <div class="input-group">
                                    <input type="text" id="coupon_input" name="applied_coupon_code" class="form-control" placeholder="NHẬP MÃ TẠI ĐÂY">
                                    <button class="btn" type="button" id="btn_apply_coupon" style="background:#a2836e; color:white; border:none;">ÁP DỤNG</button>
                                </div>
                                
                                <div id="voucherList">
                                    <?php if (empty($availableCoupons)): ?>
                                        <div class="text-center p-3 small text-muted">Bạn chưa có mã giảm giá nào.</div>
                                    <?php else: ?>
                                        <?php foreach ($availableCoupons as $cp): 
                                            $is_eligible = ($subtotal >= $cp['min_order_value']);
                                        ?>
                                        <div class="coupon-item <?php echo $is_eligible ? '' : 'disabled'; ?>" 
                                             onclick="<?php echo $is_eligible ? "selectVoucher('".$cp['code']."')" : "alert('Đơn hàng cần tối thiểu ".formatCurrency($cp['min_order_value'])."')"; ?>">
                                            <div class="coupon-icon"><i class="fas fa-tag"></i></div>
                                            <div class="coupon-info">
                                                <div class="coupon-code-text"><?php echo $cp['code']; ?></div>
                                                <div class="coupon-desc">
                                                    Giảm <?php echo $cp['discount_type'] == 'fixed' ? formatCurrency($cp['discount_value']) : $cp['discount_value'].'%'; ?>
                                                </div>
                                                <div class="coupon-desc small">Đơn tối thiểu: <?php echo formatCurrency($cp['min_order_value']); ?></div>
                                                <span class="status-tag <?php echo $is_eligible ? 'tag-available' : 'tag-locked'; ?>">
                                                    <?php echo $is_eligible ? 'Có thể dùng' : 'Không đủ điều kiện'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="order-total border-top mt-4 pt-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tạm tính:</span>
                                    <strong><?php echo formatCurrency($subtotal); ?></strong> 
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Phí vận chuyển:</span>
                                    <strong><?php echo $shippingFee > 0 ? formatCurrency($shippingFee) : 'Miễn phí'; ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2 text-danger d-none" id="row_discount">
                                    <span>Giảm giá:</span>
                                    <strong id="val_discount">-0đ</strong>
                                </div>
                                <hr style="border-color: rgba(255,255,255,0.1);">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Tổng cộng:</h5>
                                    <div>
                                        <h4 class="mb-0 text-white" id="final_total_display"><?php echo formatCurrency($total); ?></h4>
                                        <input type="hidden" name="final_total_amount" id="input_final_total" value="<?php echo $total; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-place-order mt-4 w-100">
                                <i class="fas fa-check-circle me-2"></i> ĐẶT HÀNG NGAY
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const cartSubtotal = <?php echo $subtotal; ?>;
        const shippingFee = <?php echo $shippingFee; ?>;

        function toggleVoucherList() {
            const list = document.getElementById('voucherList');
            list.style.display = (list.style.display === 'none' || list.style.display === '') ? 'block' : 'none';
        }

        function selectVoucher(code) {
            document.getElementById('coupon_input').value = code;
            document.getElementById('btn_apply_coupon').click();
            toggleVoucherList(); // Đóng danh sách sau khi chọn
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/checkout.js"></script>
</body>
</html>