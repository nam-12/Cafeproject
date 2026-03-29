<?php
// ================================================================
// customer/api_shipping.php  (phiên bản nâng cấp)
// Endpoint AJAX — tính phí ship realtime + gợi ý địa chỉ
// ================================================================

ini_set('display_errors', 0);
error_reporting(0);

ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'msg' => 'Lỗi nội bộ (shutdown): ' . ($error['message'] ?? 'unknown'),
            'debug' => $error,
        ]);
        exit;
    }
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

session_start();
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/shipping_engine.php';

// Bảo đảm hàm available
if (!function_exists('resolveShippingDistance')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Lỗi nội bộ: chưa khởi tạo engine tính phí ship']);
    exit;
}

// ── Chỉ cho phép POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

// ── Rate limiting: 60 req/60s mỗi session, tối đa 10 req/10s ───────
$now = time();
$_SESSION['_ship_calls'] = array_filter(
    $_SESSION['_ship_calls'] ?? [],
    fn($t) => ($now - $t) < 60
);
$recentCount = count($_SESSION['_ship_calls']);

if ($recentCount >= 60) {
    http_response_code(429);
    header('Retry-After: 30');
    echo json_encode(['ok' => false, 'msg' => 'Quá nhiều yêu cầu trong 1 phút, xin đợi 30 giây']);
    exit;
}

// Giới hạn burst (mỗi 10s tối đa 10 requests)
$shortWindowCalls = count(array_filter($_SESSION['_ship_calls'], fn($t) => ($now - $t) < 10));
if ($shortWindowCalls >= 10) {
    http_response_code(429);
    header('Retry-After: 10');
    echo json_encode(['ok' => false, 'msg' => 'Quá nhiều yêu cầu (tốc độ quá nhanh), xin đợi 10 giây']);
    exit;
}

$_SESSION['_ship_calls'][] = $now;

// ── Đọc action từ body ───────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? 'calculate'; // 'calculate' | 'suggest'

// ════════════════════════════════════════════════════════════════
// ACTION: suggest — gợi ý địa chỉ khi người dùng gõ (autocomplete)
// ════════════════════════════════════════════════════════════════
if ($action === 'suggest') {
    $partial = trim($body['query'] ?? '');

    if (mb_strlen($partial) < 3) {
        echo json_encode(['ok' => true, 'suggestions' => []]);
        exit;
    }
    $partial = htmlspecialchars(strip_tags($partial), ENT_QUOTES, 'UTF-8');

    $suggestions = _getSuggestions($partial);
    echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: calculate — tính khoảng cách và phí ship
// ════════════════════════════════════════════════════════════════
$address = trim($body['address'] ?? '');

// Validate
if (mb_strlen($address) < 10) {
    echo json_encode(['ok' => false, 'msg' => 'Địa chỉ quá ngắn (cần ít nhất 10 ký tự)']);
    exit;
}
if (mb_strlen($address) > 500) {
    echo json_encode(['ok' => false, 'msg' => 'Địa chỉ quá dài']);
    exit;
}
$address = htmlspecialchars(strip_tags($address), ENT_QUOTES, 'UTF-8');

try {
    // Tính khoảng cách qua engine
    $result = resolveShippingDistance($address, $pdo);
    $km     = $result['km'];
    $fee    = calculateShippingFee($km);

    // Build response
    $isFallback = !$result['ok'] && isset($km) && $km > 0;
    $responseOk = $result['ok'] || $isFallback;

    $response = [
        'ok'           => $responseOk,
        'km'           => $km,
        'shipping_fee' => $fee,
        'fee_text'     => number_format($fee, 0, ',', '.') . 'đ',
        'method'       => $result['method'],
        'from_cache'   => $result['from_cache'] ?? false,
        'note'         => '',
        'msg'          => $isFallback ? 'Đang sử dụng dữ liệu ước lượng khi địa chỉ không chính xác.' : '',
        'fallback'     => $isFallback,
    ];

    // Thêm ghi chú tuỳ theo provider
    if ($isFallback) {
        $response['note'] = '⚠️ Khoảng cách ước lượng (fallback, không xác định địa chỉ chính xác)';
    } elseif (!$result['ok']) {
        $response['note'] = '⚠️ Không tính được địa chỉ chính xác, vui lòng kiểm tra lại.';
    } elseif ($result['method'] && str_contains($result['method'], 'haversine')) {
        $response['note'] = '📍 Khoảng cách ước lượng (±15%)';
    } elseif ($result['from_cache'] ?? false) {
        $response['note'] = '✅ Khoảng cách đường thực tế (cache)';
    } else {
        $response['note'] = '✅ Khoảng cách đường thực tế';
    }

    // Thêm bảng phí mô tả
    $response['fee_breakdown'] = _buildFeeBreakdown($km, $fee);

    echo json_encode($response);
} catch (Exception $e) {
    error_log("Shipping API error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi tính phí ship. Vui lòng thử lại.']);
}

// ================================================================
// GỢI Ý ĐỊA CHỈ qua Nominatim OSM (miễn phí)
// ================================================================
function _getSuggestions(string $query): array {
    // Ưu tiên GraphHopper geocode nếu có key
    if (defined('GRAPH_HOPPER_API_KEY') && !empty(GRAPH_HOPPER_API_KEY)) {
        return _suggestGraphHopper($query);
    }
    return _suggestNominatim($query);
}

function _suggestGraphHopper(string $query): array {
    $encoded = urlencode($query . ' Việt Nam');
    $url     = "https://graphhopper.com/api/1/geocode"
             . "?q={$encoded}&locale=vi&countrycodes=VN&limit=5&key=" . GRAPH_HOPPER_API_KEY;

    $ctx  = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: CafeProject/2.0\r\n"]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];

    $data = json_decode($resp, true);
    $hits = $data['hits'] ?? [];

    return array_slice(array_map(fn($h) => [
        'text' => $h['name'] . (isset($h['country']) ? ', ' . $h['country'] : ''),
        'place_id' => '',
    ], $hits), 0, 5);
}

function _suggestNominatim(string $query): array {
    $encoded = urlencode($query . ' Việt Nam');
    $url     = "https://nominatim.openstreetmap.org/search"
             . "?q={$encoded}&format=json&limit=5&countrycodes=vn&addressdetails=0";

    $ctx  = stream_context_create(['http' => [
        'header'  => "User-Agent: CafeProject/2.0\r\n",
        'timeout' => 4,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];

    $results = json_decode($resp, true) ?? [];

    return array_map(fn($r) => [
        'text'     => $r['display_name'],
        'place_id' => '',
    ], $results);
}

// ── Mô tả bảng phí để hiển thị cho user ─────────────────────────
function _buildFeeBreakdown(float $km, int $fee): string {
    if ($km <= 2)  return "≤ 2km: phí cố định 10.000đ";
    if ($km <= 5)  return "≤ 5km: phí cố định 15.000đ";
    if ($km <= 10) {
        $extra = ceil($km - 5);
        return "5–10km: 20.000đ + {$extra}km × 3.000đ = " . number_format($fee, 0, ',', '.') . "đ";
    }
    $extra = ceil($km - 10);
    return "> 10km: 35.000đ + {$extra}km × 2.500đ = " . number_format($fee, 0, ',', '.') . "đ";
}