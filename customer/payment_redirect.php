<?php
require_once '../config/init.php';

// Lấy payment_id từ URL
$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId) {
    setFlashMessage('error', 'Thông tin thanh toán không hợp lệ!');
    redirect('../customer/checkout.php');
}

try {
    $db = getDB();
    
    // Lấy thông tin payment
    $stmtPayment = $db->prepare("
        SELECT p.*, o.order_number, o.customer_name, pm.code as payment_code, pm.type as payment_type
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        JOIN payment_methods pm ON p.payment_method_id = pm.id
        WHERE p.id = ?
    ");
    $stmtPayment->execute([$paymentId]);
    $payment = $stmtPayment->fetch();
    
    if (!$payment) {
        throw new Exception('Không tìm thấy thông tin thanh toán!');
    }
    
    // Kiểm tra trạng thái
    if ($payment['status'] !== 'pending') {
        setFlashMessage('warning', 'Giao dịch này đã được xử lý!');
        redirect("../customer/order_success.php?payment_id={$paymentId}");
    }
    
    $paymentCode = $payment['payment_code'];
    $amount = (int)$payment['amount'];
    $orderNumber = $payment['order_number'];
    
} catch (Exception $e) {
    logError('Payment Redirect Error: ' . $e->getMessage(), [
        'payment_id' => $paymentId ?? null
    ]);
    
    setFlashMessage('error', 'Có lỗi xảy ra: ' . $e->getMessage());
    redirect('../customer/checkout.php');
}