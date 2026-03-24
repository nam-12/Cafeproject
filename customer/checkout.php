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

// 4. Lọc sản phẩm được chọn từ giỏ hàng
$cart = $_SESSION['cart'];
$selectedItems = [];
if (!empty($_GET['selected_items'])) {
    $selectedItems = array_filter(array_map('intval', explode(',', $_GET['selected_items'])));
    if (!empty($selectedItems)) {
        $cart = array_filter($cart, function ($item) use ($selectedItems) {
            return in_array((int) $item['id'], $selectedItems, true);
        });
    }
}

if (empty($cart)) {
    $_SESSION['error'] = 'Vui lòng chọn ít nhất 1 sản phẩm trong giỏ hàng để thanh toán.';
    header('Location: cart.php');
    exit;
}

// 5. Tính subtotal
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

// 6. Phí ship khởi tạo = 0, sẽ tính realtime theo địa chỉ nhập
$shippingFee = 0;
$total = $subtotal;

// 7. Lấy danh sách mã giảm giá hợp lệ
$stmtCoupons = $pdo->prepare("
    SELECT cs.* FROM user_coupons uc 
    JOIN coupon_statistics cs ON uc.coupon_id = cs.id 
    WHERE uc.user_id = ? AND uc.is_used = 0 
    AND cs.display_status COLLATE utf8mb4_unicode_ci = 'Đang hoạt động'
    ORDER BY (CASE WHEN cs.min_order_value <= ? THEN 0 ELSE 1 END) ASC
");
$stmtCoupons->execute([$user_id, $subtotal]);
$availableCoupons = $stmtCoupons->fetchAll(PDO::FETCH_ASSOC);

// 8. Khôi phục dữ liệu nếu có lỗi từ create_order
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
            <div class="checkout-header">
                <h2><i class="fas fa-coffee"></i>Thanh toán đơn hàng</h2>
            </div>
            <form method="POST" action="create_order.php" id="checkoutForm" novalidate>
                <input type="hidden" name="selected_items" value="<?php echo htmlspecialchars($_GET['selected_items'] ?? ''); ?>">
                <input type="hidden" name="shipping_address" id="hidden_shipping_address" value="<?php echo htmlspecialchars($checkout_data['shipping_address'] ?? ''); ?>">
                <input type="hidden" name="client_shipping_fee" id="input_client_shipping_fee" value="0">
                <input type="hidden" name="client_distance" id="input_client_distance" value="0">

                <div class="row">
                    <!-- Left Column: Customer Information -->
                    <div class="col-lg-7">
                        <!-- Customer Information Section -->
                        <div class="section-card">
                            <div class="section-header">
                                <h5><i class="fas fa-user-circle"></i>Thông tin khách hàng</h5>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" name="customer_name" class="form-control" id="customer_name" required
                                            placeholder="Nguyễn Văn A"
                                            value="<?php echo htmlspecialchars($checkout_data['customer_name'] ?? $user['username'] ?? ''); ?>">
                                        <label for="customer_name">Họ và tên <span class="text-danger">*</span></label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-floating">
                                        <input type="tel" name="customer_phone" class="form-control" id="customer_phone" required
                                            placeholder="0123456789"
                                            value="<?php echo htmlspecialchars($checkout_data['customer_phone'] ?? $user['phone'] ?? ''); ?>">
                                        <label for="customer_phone">Số điện thoại <span class="text-danger">*</span></label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-floating">
                                    <input type="email" name="customer_email" class="form-control" id="customer_email" required
                                        placeholder="email@example.com"
                                        value="<?php echo htmlspecialchars($checkout_data['customer_email'] ?? $user['email'] ?? ''); ?>">
                                    <label for="customer_email">Email <span class="text-danger">*</span></label>
                                </div>
                            </div>

                            <!-- Delivery Address -->
                            <div class="mb-3">
                                <div class="form-floating">
                                    <textarea id="delivery_address" name="delivery_address" class="form-control" style="height: 100px;"
                                        placeholder="Số nhà, tên đường, phường/xã, quận/huyện, tỉnh/thành phố"
                                        minlength="10" maxlength="500" required autocomplete="street-address"
                                        value="<?php echo htmlspecialchars($checkout_data['shipping_address'] ?? ''); ?>"></textarea>
                                    <label for="delivery_address">Địa chỉ giao hàng <span class="text-danger">*</span></label>
                                </div>
                                <div class="form-text text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Nhập đầy đủ để tính phí giao hàng chính xác
                                </div>
                                <div id="address-error" class="text-danger small mt-1 d-none"></div>
                            </div>

                            <!-- Shipping Info Box -->
                            <div id="shipping-box" class="card mb-3 d-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="fw-semibold">
                                                <i class="fas fa-motorcycle me-1" style="color:var(--primary-color);"></i>
                                                Phí giao hàng
                                            </span>
                                            <span id="ship-km" class="text-muted small ms-1"></span>
                                            <div id="ship-note" class="text-warning small mt-1"></div>
                                        </div>
                                        <span id="ship-fee" class="fw-bold fs-6">--</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Loading shimmer -->
                            <div id="shipping-loading" class="d-none mb-3 ps-1">
                                <i class="fas fa-circle-notch fa-spin me-1 text-muted"></i>
                                <span class="text-muted small">Đang tính phí vận chuyển...</span>
                            </div>

                            <div class="mb-4">
                                <div class="form-floating">
                                    <textarea name="note" class="form-control" style="height: 80px;" id="order_note"
                                        placeholder="Ghi chú thêm cho người giao hàng (tùy chọn)"><?php echo htmlspecialchars($checkout_data['note'] ?? ''); ?></textarea>
                                    <label for="order_note">Ghi chú đơn hàng</label>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Methods Section -->
                        <div class="section-card">
                            <div class="section-header">
                                <h5><i class="fas fa-wallet"></i>Phương thức thanh toán</h5>
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
                    </div>

                    <!-- Right Column: Order Summary -->
                    <div class="col-lg-5">
                        <div class="order-summary">
                            <h5 class="mb-4">
                                <i class="fas fa-receipt me-2"></i>
                                Đơn hàng (<?php echo $cart_count; ?>)
                            </h5>

                            <!-- Product List -->
                            <div class="order-items-list mb-4" style="max-height:200px; overflow-y:auto;">
                                <?php foreach ($cart as $item): ?>
                                    <div class="order-item d-flex justify-content-between mb-3">
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                            <small class="text-muted">
                                                x<?php echo $item['quantity']; ?> -
                                                <?php echo formatCurrency($item['price']); ?>
                                            </small>
                                        </div>
                                        <div class="text-end fw-bold">
                                            <?php echo formatCurrency($item['price'] * $item['quantity']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Coupon Section -->
                            <div class="coupon-section">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label small fw-bold text-uppercase mb-0" style="color:var(--primary-color);">
                                        Mã giảm giá
                                    </label>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none"
                                        onclick="toggleVoucherList()" style="color:var(--primary-color);">
                                        <i class="fas fa-ticket-alt me-1"></i>Voucher của bạn
                                    </button>
                                </div>

                                <div class="coupon-input-group">
                                    <div class="input-group">
                                        <input type="text" id="coupon_input" name="applied_coupon_code" class="form-control"
                                            placeholder="NHẬP MÃ TẠI ĐÂY">
                                        <button class="btn" type="button" id="btn_apply_coupon"
                                            style="background:var(--primary-color); color:white; border:none;">
                                            ÁP DỤNG
                                        </button>
                                    </div>
                                </div>

                                <div id="voucherList">
                                    <?php if (empty($availableCoupons)): ?>
                                        <div class="text-center p-3 small text-muted">
                                            Bạn chưa có mã giảm giá nào.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($availableCoupons as $cp):
                                            $is_eligible = ($subtotal >= $cp['min_order_value']);
                                        ?>
                                            <div class="coupon-item <?php echo $is_eligible ? '' : 'disabled'; ?>"
                                                onclick="<?php echo $is_eligible
                                                    ? "selectVoucher('" . htmlspecialchars($cp['code']) . "')"
                                                    : "alert('Đơn hàng cần tối thiểu " . formatCurrency($cp['min_order_value']) . "')"; ?>">
                                                <div class="coupon-icon"><i class="fas fa-tag"></i></div>
                                                <div class="coupon-info">
                                                    <div class="coupon-code-text"><?php echo htmlspecialchars($cp['code']); ?></div>
                                                    <div class="coupon-desc">
                                                        Giảm <?php echo $cp['discount_type'] == 'fixed'
                                                            ? formatCurrency($cp['discount_value'])
                                                            : $cp['discount_value'] . '%'; ?>
                                                    </div>
                                                    <div class="coupon-desc small">
                                                        Đơn tối thiểu: <?php echo formatCurrency($cp['min_order_value']); ?>
                                                    </div>
                                                    <span class="status-tag <?php echo $is_eligible ? 'tag-available' : 'tag-locked'; ?>">
                                                        <?php echo $is_eligible ? 'Có thể dùng' : 'Không đủ điều kiện'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Totals Table -->
                            <div class="order-total border-top mt-4 pt-3">
                                <!-- Subtotal -->
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tạm tính:</span>
                                    <strong id="display-subtotal">
                                        <?php echo formatCurrency($subtotal); ?>
                                    </strong>
                                </div>

                                <!-- Shipping Fee -->
                                <div class="d-flex justify-content-between mb-2" id="row-ship-summary">
                                    <span>Phí vận chuyển:</span>
                                    <strong id="display-shipping">
                                        <span id="shipping-summary-val">
                                            <?php if ($shippingFee > 0): ?>
                                                <?php echo formatCurrency($shippingFee); ?>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Nhập địa chỉ để tính</span>
                                            <?php endif; ?>
                                        </span>
                                    </strong>
                                </div>

                                <!-- Discount -->
                                <div class="d-flex justify-content-between mb-2 text-danger d-none" id="row_discount">
                                    <span>Giảm giá:</span>
                                    <strong id="val_discount">-0đ</strong>
                                </div>

                                <hr style="border-color: rgba(255,255,255,0.15);">

                                <!-- Total -->
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Tổng cộng:</h5>
                                    <div>
                                        <h4 class="mb-0 text-white" id="final_total_display">
                                            <?php echo formatCurrency($total); ?>
                                        </h4>
                                        <input type="hidden" name="final_total_amount" id="input_final_total" value="<?php echo $total; ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Place Order Button -->
                            <button type="submit" class="btn btn-place-order mt-4 w-100" id="btn-submit">
                                <i class="fas fa-check-circle me-2"></i> ĐẶT HÀNG NGAY
                            </button>

                            <!-- Address Warning -->
                            <div id="submit-addr-warning" class="text-danger small text-center mt-2 d-none">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Vui lòng nhập địa chỉ giao hàng trước khi đặt hàng!
                            </div>

                            <!-- Security Badge -->
                            <div class="security-badge">
                                <i class="fas fa-shield-alt me-2"></i>
                                Thông tin của bạn được bảo mật an toàn
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        const cartSubtotal = <?php echo (int)$subtotal; ?>;
        let shippingFee = 0;

        // Shipping fee calculation
        (function() {
            'use strict';

            const SUBTOTAL = <?php echo (int)$subtotal; ?>;

            // DOM references
            const addrInput = document.getElementById('delivery_address');
            const hiddenAddr = document.getElementById('hidden_shipping_address');
            const shippingBox = document.getElementById('shipping-box');
            const shipKm = document.getElementById('ship-km');
            const shipFeeLabel = document.getElementById('ship-fee');
            const shipNote = document.getElementById('ship-note');
            const shipLoading = document.getElementById('shipping-loading');
            const shippingSumVal = document.getElementById('shipping-summary-val');
            const finalTotal = document.getElementById('final_total_display');
            const inputFinalTot = document.getElementById('input_final_total');
            const inputClientFee = document.getElementById('input_client_shipping_fee');
            const inputClientKm = document.getElementById('input_client_distance');
            const addrError = document.getElementById('address-error');
            const submitWarning = document.getElementById('submit-addr-warning');

            // Variables
            let currentDiscount = 0;
            let currentShipFee = 0;
            let debounceTimer = null;
            let lastValidAddress = '';

            // Helper function
            function fmtVND(n) {
                return new Intl.NumberFormat('vi-VN').format(n) + 'đ';
            }

            // Set shipping UI
            function setShippingUI(fee, km, note, isEstimated) {
                // Small box on the left
                shipFeeLabel.textContent = fmtVND(fee);
                shipKm.textContent = km > 0 ? `(${km} km)` : '';
                shipNote.textContent = note;
                shippingBox.classList.remove('d-none');

                // Shipping fee in the totals table (right column)
                shippingSumVal.innerHTML = `<span style="color:var(--primary-color); font-weight:700">${fmtVND(fee)}</span>`;

                // Update total
                const newTotal = SUBTOTAL + fee - currentDiscount;
                finalTotal.textContent = fmtVND(newTotal);
                inputFinalTot.value = newTotal;
                inputClientFee.value = fee;
                inputClientKm.value = km;
                currentShipFee = fee;
                shippingFee = fee;  // Update global variable

                // Style address input
                addrInput.classList.remove('is-invalid-addr');
                addrInput.classList.add('is-valid-addr');

                // Show success toast
                showToast('Phí vận chuyển đã được cập nhật', 'success');
            }

            // Reset shipping UI
            function resetShippingUI() {
                shippingBox.classList.add('d-none');
                shippingSumVal.innerHTML = '<span class="text-muted small fst-italic">Nhập địa chỉ để tính</span>';
                finalTotal.textContent = fmtVND(SUBTOTAL - currentDiscount);
                inputFinalTot.value = SUBTOTAL - currentDiscount;
                inputClientFee.value = 0;
                inputClientKm.value = 0;
                currentShipFee = 0;
                shippingFee = 0;  // Update global variable
            }

            // Show/hide loading
            function showLoading(show) {
                shipLoading.classList.toggle('d-none', !show);
                if (show) shippingBox.classList.add('d-none');
            }

            // Fetch shipping fee from server
            async function fetchShippingFee(address) {
                showLoading(true);
                addrError.classList.add('d-none');

                try {
                    const res = await fetch('api_shipping.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ address: address }),
                    });

                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}`);
                    }

                    const data = await res.json();

                    if (!data.ok) {
                        addrError.textContent = data.msg || 'Không tính được phí ship';
                        addrError.classList.remove('d-none');
                        addrInput.classList.add('is-invalid-addr');
                        addrInput.classList.remove('is-valid-addr');
                        resetShippingUI();
                        return;
                    }

                    lastValidAddress = address;
                    setShippingUI(data.shipping_fee, data.km, data.note, data.is_estimated);

                } catch (err) {
                    console.error('[Shipping API Error]', err);
                    addrError.textContent = 'Không kết nối được server tính phí ship. Vui lòng thử lại.';
                    addrError.classList.remove('d-none');
                    resetShippingUI();
                    showToast('Không thể tính phí vận chuyển. Vui lòng thử lại.', 'danger');
                } finally {
                    showLoading(false);
                }
            }

            // Debounce 800ms when typing address
            addrInput.addEventListener('input', function() {
                const val = this.value.trim();

                // Sync to hidden shipping_address
                hiddenAddr.value = val;

                clearTimeout(debounceTimer);
                submitWarning.classList.add('d-none');

                if (val.length < 10) {
                    resetShippingUI();
                    addrInput.classList.remove('is-valid-addr', 'is-invalid-addr');
                    return;
                }

                debounceTimer = setTimeout(() => fetchShippingFee(val), 800);
            });

            // Validate before submit
            document.getElementById('checkoutForm').addEventListener('submit', function(e) {
                const addr = addrInput.value.trim();

                if (addr.length < 10) {
                    e.preventDefault();
                    submitWarning.classList.remove('d-none');
                    addrInput.classList.add('is-invalid-addr');
                    addrInput.focus();
                    // Scroll to address field
                    addrInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                // Update hidden field before sending
                hiddenAddr.value = addr;
            });

            // Listen for coupon applied event
            document.addEventListener('couponApplied', function(e) {
                currentDiscount = e.detail?.discount || 0;
                const newTotal = SUBTOTAL + currentShipFee - currentDiscount;
                finalTotal.textContent = fmtVND(newTotal > 0 ? newTotal : 0);
                inputFinalTot.value = newTotal > 0 ? newTotal : 0;
            });

            // If there's a prefilled address, calculate immediately
            const prefillAddr = addrInput.value.trim();
            if (prefillAddr.length >= 10) {
                fetchShippingFee(prefillAddr);
            }

        })(); // END IIFE

        // Coupon/Voucher helpers
        function toggleVoucherList() {
            const list = document.getElementById('voucherList');
            list.style.display = (list.style.display === 'none' || list.style.display === '') ? 'block' : 'none';
        }

        function selectVoucher(code) {
            document.getElementById('coupon_input').value = code;
            document.getElementById('btn_apply_coupon').click();
            toggleVoucherList();
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            const toastId = 'toast-' + Date.now();
            
            const toastHtml = `
                <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="toast-header">
                        <i class="fas fa-${type === 'success' ? 'check-circle text-success' : type === 'danger' ? 'exclamation-circle text-danger' : 'info-circle text-info'} me-2"></i>
                        <strong class="me-auto">Thông báo</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 3000
            });
            
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        }

        // Auto-dismiss alerts after 3 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert.alert-dismissible').forEach(el => {
                el.classList.remove('show');
                setTimeout(() => el.remove(), 400);
            });
        }, 3000);

        // Apply coupon button
        document.getElementById('btn_apply_coupon').addEventListener('click', function() {
            const couponCode = document.getElementById('coupon_input').value.trim();
            
            if (!couponCode) {
                showToast('Vui lòng nhập mã giảm giá', 'danger');
                return;
            }
            
            // Gọi API check_coupon.php để lấy discount thực tế
            fetch('check_coupon.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code: couponCode,
                    subtotal: cartSubtotal
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const discountAmount = data.discount_amount;
                    document.getElementById('row_discount').classList.remove('d-none');
                    document.getElementById('val_discount').textContent = '-' + new Intl.NumberFormat('vi-VN').format(discountAmount) + 'đ';
                    
                    // Dispatch event to update total
                    document.dispatchEvent(new CustomEvent('couponApplied', { detail: { discount: discountAmount } }));
                    
                    showToast('Áp dụng thành công! Giảm ' + new Intl.NumberFormat('vi-VN').format(discountAmount) + 'đ', 'success');
                } else {
                    showToast(data.message || 'Lỗi áp dụng mã giảm giá', 'danger');
                }
            })
            .catch(err => {
                console.error('Coupon error:', err);
                showToast('Lỗi khi áp dụng mã giảm giá', 'danger');
            });
        });

        // Form validation
        (function() {
            'use strict';
            const forms = document.querySelectorAll('.needs-validation');
            
            Array.prototype.slice.call(forms).forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    
                    form.classList.add('was-validated');
                }, false);
            });
        })();
    </script>
</body>

</html>