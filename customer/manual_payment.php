<?php
require_once '../config/init.php';

$pageTitle = "Hướng dẫn thanh toán";

// Lấy order_id
$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if (!$orderId) {
    setFlashMessage('error', 'Thông tin đơn hàng không hợp lệ!');
    redirect('../customer/index.php');
}

try {
    $db = getDB();
    
    // Lấy thông tin order và payment (Bổ sung discount_amount và coupon_code)
    $stmtOrder = $db->prepare("
        SELECT o.*, p.id as payment_id, p.status as payment_status, 
               pm.name as payment_method_name, pm.code as payment_method_code,
               sa.address, sa.city, sa.district, sa.phone as shipping_phone
        FROM orders o
        JOIN payments p ON o.id = p.order_id
        JOIN payment_methods pm ON p.payment_method_id = pm.id
        LEFT JOIN shipping_addresses sa ON o.id = sa.order_id
        WHERE o.id = ?
    ");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch();
    
    if (!$order) {
        throw new Exception('Không tìm thấy đơn hàng!');
    }
    
    // Lấy order items
    $stmtItems = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmtItems->execute([$orderId]);
    $orderItems = $stmtItems->fetchAll();
    
    // Tạo URL QR code động từ VietQR
    $qrCodeUrl = generateBankQRUrl(
        $order['total_amount'], 
        $order['order_number'],
        "DH " . $order['order_number']
    );
    
    // Xử lý xác nhận đã chuyển khoản
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_transfer'])) {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
            setFlashMessage('error', 'Yêu cầu không hợp lệ (CSRF).');
            redirect(BASE_URL . "customer/manual_payment.php?order_id={$orderId}");
        }

        // Cập nhật status order thành 'confirmed' (xác nhận đã chuyển khoản)
        $stmtUpdateOrder = $db->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ?");
        $stmtUpdateOrder->execute([$orderId]);

        // Cập nhật ghi chú tracking
        $stmtUpdateTracking = $db->prepare("
            INSERT INTO order_tracking (order_id, status, note)
            VALUES (?, 'confirmed', 'Khách hàng xác nhận đã chuyển khoản. Đang chờ kiểm tra.')
        ");
        $stmtUpdateTracking->execute([$orderId]);

        setFlashMessage('success', 'Cảm ơn bạn! Chúng tôi sẽ kiểm tra và xác nhận trong thời gian sớm nhất.');
        redirect(BASE_URL . "customer/order_success.php?order_id={$orderId}");
    }
    
} catch (Exception $e) {
    logError('Manual Payment Error: ' . $e->getMessage());
    setFlashMessage('error', 'Có lỗi xảy ra: ' . $e->getMessage());
    redirect(BASE_URL . "customer/index.php");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/manual_payment.css">
    <link rel="stylesheet" href="../assets/css/index.css">
</head>
<body>
<?php include '../templates/navbar.php'; ?>

<div class="manual-payment-page">
    <div class="page-header text-center">
        <h1><i class="fas fa-university"></i> Hướng dẫn thanh toán</h1>
        <p><b>Vui lòng chuyển khoản theo thông tin bên dưới</b></p>
    </div>
    
    <div class="payment-container">
        <div class="payment-instructions">
            <div class="instruction-card">
                <h2><i class="fas fa-info-circle"></i> Thông tin chuyển khoản</h2>
                
                <div class="bank-info">
                    <div class="info-row">
                        <span class="label">Ngân hàng:</span>
                        <strong><?php echo e(BANK_NAME); ?></strong>
                    </div>
                    <div class="info-row">
                        <span class="label">Số tài khoản:</span>
                        <strong class="highlight"><?php echo e(BANK_ACCOUNT_NO); ?></strong>
                        <button class="btn-copy" onclick="copyToClipboard('<?php echo BANK_ACCOUNT_NO; ?>')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <div class="info-row">
                        <span class="label">Chủ tài khoản:</span>
                        <strong><?php echo e(BANK_ACCOUNT_NAME); ?></strong>
                    </div>
                    <div class="info-row amount">
                        <span class="label">Số tiền:</span>
                        <strong class="text-danger"><?php echo formatCurrency($order['total_amount']); ?></strong>
                        <button class="btn-copy" onclick="copyToClipboard('<?php echo $order['total_amount']; ?>')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <div class="info-row">
                        <span class="label">Nội dung:</span>
                        <strong class="highlight">DH <?php echo e($order['order_number']); ?></strong>
                        <button class="btn-copy" onclick="copyToClipboard('DH <?php echo $order['order_number']; ?>')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
                
                <div class="qr-code-section">
                    <h3><i class="fas fa-qrcode"></i> Quét mã QR để thanh toán</h3>
                    <div class="qr-code-container">
                        <img src="<?php echo e($qrCodeUrl); ?>" 
                             alt="QR Code" 
                             class="qr-code-image"
                             onerror="this.style.display='none'; document.getElementById('qr-error').style.display='block';">
                        <div id="qr-error" style="display:none;" class="qr-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Không thể tải QR code. Vui lòng chuyển khoản thủ công.</p>
                        </div>
                    </div>
                    <p class="qr-note">Quét mã QR bằng app ngân hàng để chuyển khoản nhanh</p>
                </div>
                
                <div class="payment-note">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Lưu ý quan trọng:</strong>
                    <ul>
                        <li>Vui lòng chuyển <strong>ĐÚNG SỐ TIỀN</strong> và ghi <strong>ĐÚNG NỘI DUNG</strong> để đơn hàng được xử lý tự động</li>
                        <li>Sau khi chuyển khoản, vui lòng nhấn nút "Tôi đã chuyển khoản" bên dưới</li>
                        <li>Đơn hàng sẽ được xác nhận trong vòng 5-15 phút</li>
                        <li>Nếu có vấn đề, vui lòng liên hệ: <strong><?php echo MAIL_FROM; ?></strong></li>
                    </ul>
                </div>
                
                <form method="POST" class="confirm-form text-center">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="confirm_transfer" class="btn btn-success btn-lg btn-block">
                        <i class="fas fa-check"></i> Tôi đã chuyển khoản
                    </button>
                </form>
            </div>
        </div>
        
        <div class="order-summary-sidebar">
            <h2><i class="fas fa-receipt"></i> Thông tin đơn hàng</h2>
            
            <div class="summary-section">
                <div class="info-row">
                    <span>Mã đơn hàng:</span>
                    <strong><?php echo e($order['order_number']); ?></strong>
                </div>
                <div class="info-row">
                    <span>Khách hàng:</span>
                    <strong><?php echo e($order['customer_name']); ?></strong>
                </div>
                <div class="info-row">
                    <span>Điện thoại:</span>
                    <strong><?php echo e($order['customer_phone']); ?></strong>
                </div>
                <div class="info-row">
                    <span>Phương thức:</span>
                    <strong><?php echo e($order['payment_method_name']); ?></strong>
                </div>
            </div>
            
            <div class="summary-divider"></div>
            
            <h3>Sản phẩm</h3>
            <div class="order-items">
                <?php foreach ($orderItems as $item): ?>
                    <div class="order-item">
                        <div class="item-info">
                            <p class="item-name"><?php echo e($item['product_name']); ?></p>
                            <p class="item-qty">x<?php echo $item['quantity']; ?></p>
                        </div>
                        <div class="item-price">
                            <?php echo formatCurrency($item['subtotal']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="order-item">
                    <div class="item-info">
                        <p class="item-name">Phí vận chuyển</p>
                    </div>
                    <div class="item-price">
                        <?php echo $order['shipping_fee'] > 0 ? formatCurrency($order['shipping_fee']) : 'Miễn phí'; ?>
                    </div>
                </div>

                <?php if ($order['discount_amount'] > 0): ?>
                <div class="order-item discount-row">
                    <div class="item-info">
                        <p class="item-name text-success">Mã giảm giá (<?php echo e($order['coupon_code']); ?>)</p>
                    </div>
                    <div class="item-price text-success">
                        -<?php echo formatCurrency($order['discount_amount']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="summary-divider"></div>
            
            <div class="summary-total">
                <div class="total-row">
                    <span>Tổng thanh toán:</span>
                    <strong class="amount"><?php echo formatCurrency($order['total_amount']); ?></strong>
                </div>
            </div>
            
            <div class="summary-actions action-buttons-vertical">
                <?php if ($order['payment_status'] === 'pending' && $order['status'] !== 'cancelled'): ?>
                    <button onclick="cancelOrder(<?php echo $order['id']; ?>)" 
                            class="btn btn-danger btn-block">
                        <i class="fas fa-times"></i> Hủy đơn hàng
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Hàm copy to clipboard
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Đã copy: ' + text);
        }, function() {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    document.body.appendChild(textArea);
    textArea.select();
    try {
        document.execCommand('copy');
        alert('Đã copy: ' + text);
    } catch (err) {
        alert('Không thể copy. Vui lòng copy thủ công: ' + text);
    }
    document.body.removeChild(textArea);
}

// Hàm hủy đơn hàng
function cancelOrder(orderId) {
    if (!confirm('Bạn có chắc chắn muốn hủy đơn hàng này? Lưu ý: Voucher (nếu có) sẽ được hoàn trả lại tài khoản của bạn.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('order_id', orderId);
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';
    formData.append('csrf_token', csrfToken);
    
    fetch('../customer/cancel_order.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = '../customer/order_success.php?order_id=' + orderId; 
        } else {
            alert('Lỗi: ' + data.message);
        }
    })
    .catch(error => {
        alert('Có lỗi xảy ra: ' + error);
    });
}
</script>

<?php include '../templates/footer.php'; ?>
</body>
</html>