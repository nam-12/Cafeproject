<?php
// customer/api_shipping.php — API tính phí ship realtime cho AJAX

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once '../config/config.php';
require_once '../config/helpers.php';

// Chỉ nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

// Rate limiting: tối đa 10 request/60 giây mỗi session
$now = time();
$_SESSION['ship_calls'] = array_filter(
    $_SESSION['ship_calls'] ?? [],
    fn($t) => ($now - $t) < 60
);
if (count($_SESSION['ship_calls']) >= 10) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'msg' => 'Quá nhiều yêu cầu, vui lòng đợi 1 phút']);
    exit;
}
$_SESSION['ship_calls'][] = $now;

// Đọc địa chỉ từ JSON body hoặc POST form
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$address = trim($input['address'] ?? $_POST['address'] ?? '');

// Validate
if (mb_strlen($address) < 10) {
    echo json_encode(['ok' => false, 'msg' => 'Địa chỉ quá ngắn (tối thiểu 10 ký tự)']);
    exit;
}

$address = htmlspecialchars(strip_tags($address), ENT_QUOTES, 'UTF-8');

// Tính
$result       = getShippingDistance($address);
$distance     = $result['km'];
$shipping_fee = calculateShippingFee($distance);

echo json_encode([
    'ok'           => true,
    'km'           => $distance,
    'shipping_fee' => $shipping_fee,
    'fee_text'     => number_format($shipping_fee, 0, ',', '.') . 'đ',
    'is_estimated' => !$result['success'],
    'note'         => $result['success'] ? '' : '⚠️ Khoảng cách ước lượng',
]);