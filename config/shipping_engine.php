<?php
// ================================================================
// config/shipping_engine.php
// Engine tính quãng đường — đa nguồn, chính xác, có cache DB
//
// THỨ TỰ ƯU TIÊN:
//   1. GraphHopper API                 (chính xác nhất — đường thực tế)
//   2. Nominatim + Haversine            (fallback — đường chim bay × hệ số)
//
// TỌA ĐỘ QUÁN: lấy từ config/config.php (STORE_LAT, STORE_LNG)
// ================================================================

// ── Hệ số điều chỉnh đường chim bay → đường bộ Việt Nam ─────────
// Đã loại bỏ độ biến động ±15%, giữ chính xác từ ước lượng Haversine
define('HAVERSINE_ROAD_FACTOR', 1.0);

// ── Timeout gọi API (giây) ───────────────────────────────────────
define('SHIPPING_API_TIMEOUT', 6);

// ── Cache TTL trong DB (giây) ────────────────────────────────────
define('SHIPPING_CACHE_TTL', 86400); // 24 giờ

/**
 * Make address text more compatible with geocoding providers
 */
function normalizeShippingAddress(string $address): string {
    $a = trim($address);

    // Thay shorthand, ngắt dấu câu thừa
    $a = preg_replace('/\bđ\.?\s*/iu', 'đường ', $a);
    $a = preg_replace('/\bquan\b/iu', 'quận ', $a);
    $a = preg_replace('/\bphường\b/iu', 'phường ', $a);
    $a = preg_replace('/\bphu\b/iu', 'phú ', $a);
    $a = preg_replace('/\s*,\s*/', ', ', $a);
    $a = preg_replace('/\s+/', ' ', $a);

    return trim($a);
}

/**
 * Hàm chính — gọi từ api_shipping.php và create_order.php
 *
 * @param string $customerAddress  Địa chỉ khách hàng (đã sanitize)
 * @param PDO    $pdo              Kết nối DB (để cache)
 * @return array {
 *   ok: bool, km: float, method: string,
 *   error: string, from_cache: bool
 * }
 */
function resolveShippingDistance(string $customerAddress, PDO $pdo): array {
    // Chuẩn hoá địa chỉ trước khi đi tìm
    $customerAddress = normalizeShippingAddress($customerAddress);

    // 1. Thử đọc cache DB trước
    $cached = _getDistanceCache($customerAddress, $pdo);
    if ($cached !== null) {
        return [
            'ok'         => true,
            'km'         => $cached['km'],
            'method'     => $cached['method'] . ' (cached)',
            'error'      => '',
            'from_cache' => true,
        ];
    }

    // 2. Thử từng provider theo thứ tự
    $result = null;

    // Provider 1: GraphHopper
    if (defined('GRAPH_HOPPER_API_KEY') && !empty(GRAPH_HOPPER_API_KEY)) {
        $result = _distanceGraphHopper($customerAddress);
    }

    // Provider 2: Nominatim geocode + Haversine (luôn là fallback)
    if (!$result || !$result['ok']) {
        $result = _distanceNominatimHaversine($customerAddress);
    }

    // Nếu Nominatim vẫn thất bại lần đầu, thử các dạng rút gọn địa chỉ
    if (!$result || !$result['ok']) {
        $streetParts = explode(',', $customerAddress);
        if (count($streetParts) > 2) {
            for ($i = count($streetParts) - 2; $i >= 0; $i--) {
                $tryAddress = trim(implode(', ', array_slice($streetParts, $i)));
                if (!$tryAddress) continue;
                $result = _distanceNominatimHaversine($tryAddress);
                if ($result['ok']) {
                    $result['method'] .= ' (nominatim_shortcut)';
                    break;
                }
            }
        }
    }

    // 3. Lưu cache DB
    if ($result && $result['ok']) {
        _saveDistanceCache($customerAddress, $result['km'], $result['method'], $pdo);
    }

    return $result ?? [
        'ok'         => false,
        'km'         => 3.0,
        'method'     => 'default_fallback',
        'error'      => 'Tất cả provider thất bại, dùng 3km mặc định',
        'from_cache' => false,
    ];
}

// ================================================================
// PROVIDER 1: GraphHopper API
// Trả về khoảng cách đường bộ thực tế (chính xác)
// ================================================================
function _distanceGraphHopper(string $address): array {
    // Bước 1: Geocode địa chỉ khách → tọa độ
    $customerCoords = _geocodeGraphHopper($address);
    if (!$customerCoords) {
        // Debug: ghi log địa chỉ không geocode được
        error_log("GraphHopper geocode failed for address: " . $address);
        return ['ok' => false, 'km' => 0, 'method' => 'graphhopper',
                'error' => 'GraphHopper geocode thất bại cho địa chỉ: ' . $address];
    }

    // Bước 2: Lấy tọa độ quán
    $storeLat = defined('STORE_LAT') ? (float)STORE_LAT : 0;
    $storeLng = defined('STORE_LNG') ? (float)STORE_LNG : 0;

    if ($storeLat === 0.0 || $storeLng === 0.0) {
        return ['ok' => false, 'km' => 0, 'method' => 'graphhopper',
                'error' => 'STORE_LAT/STORE_LNG chưa được cấu hình'];
    }

    // Bước 3: Gọi Routing API
    $url = "https://graphhopper.com/api/1/route"
         . "?point={$storeLat},{$storeLng}"
         . "&point={$customerCoords['lat']},{$customerCoords['lng']}"
         . "&vehicle=car"
         . "&locale=vi"
         . "&key=" . GRAPH_HOPPER_API_KEY;

    $resp = _httpGet($url);
    if ($resp === false) {
        error_log("GraphHopper routing timeout for address: " . $address);
        return ['ok' => false, 'km' => 0, 'method' => 'graphhopper', 'error' => 'GraphHopper routing timeout'];
    }

    $data = json_decode($resp, true);
    $dist = $data['paths'][0]['distance'] ?? null;

    if ($dist === null) {
        error_log("GraphHopper no distance returned for address: " . $address . ", response: " . substr($resp, 0, 500));
        return ['ok' => false, 'km' => 0, 'method' => 'graphhopper', 'error' => 'GraphHopper không trả về distance'];
    }

    return [
        'ok'         => true,
        'km'         => round($dist / 1000, 2),
        'method'     => 'graphhopper',
        'error'      => '',
        'from_cache' => false,
    ];
}

// ── Chuẩn hoá địa chỉ cho geocoding
function _normalizeAddressForGraphHopper(string $address): string {
    $a = trim($address);
    $a = preg_replace('/\bđ\.?\b/iu', 'đường', $a);
    $a = preg_replace('/\bph\.?\b/iu', 'phường', $a);
    $a = preg_replace('/\bq\.?\b/iu', 'quận', $a);
    $a = preg_replace('/\btp\.?\b/iu', 'thành phố', $a);
    $a = preg_replace('/\s*,\s*/', ', ', $a);
    $a = preg_replace('/\s+/u', ' ', $a);
    $a = trim($a);
    if (!preg_match('/\b(việt nam|hanoi|ha noi|tp\.? hcm|ho chi minh)\b/iu', $a)) {
        $a .= ', Việt Nam';
    }
    return $a;
}

// ── Geocode địa chỉ bằng GraphHopper Geocoding API ─────────────
function _geocodeGraphHopper(string $address): ?array {
    $variants = [
        $address,
        normalizeShippingAddress($address),
        _normalizeAddressForGraphHopper($address),
    ];

    foreach (array_unique($variants) as $candidate) {
        $encoded = urlencode($candidate);
        $url     = "https://graphhopper.com/api/1/geocode"
                 . "?q={$encoded}"
                 . "&locale=vi"
                 . "&countrycodes=VN"
                 . "&limit=5"
                 . "&key=" . GRAPH_HOPPER_API_KEY;

        $resp = _httpGet($url);
        if ($resp === false) continue;

        $data = json_decode($resp, true);
        $hits = $data['hits'] ?? [];
        if (empty($hits)) continue;

        $first = $hits[0];
        return [
            'lng' => (float)$first['point']['lng'],
            'lat' => (float)$first['point']['lat'],
        ];
    }

    // Nếu GraphHopper không tìm được, thử Nominatim để làm nguyên liệu fallback (có thể chính xác hơn khi những chuẩn địa chỉ đặc thù)
    return _geocodeNominatim($address);
}

// ── Geocode địa chỉ bằng Nominatim OSM (dùng cho fallback đặc biệt)
function _geocodeNominatim(string $address): ?array {
    $addr = normalizeShippingAddress($address);
    if (!preg_match('/\b(việt nam|vn)\b/iu', $addr)) {
        $addr .= ', Việt Nam';
    }

    $encoded = urlencode($addr);
    $url     = "https://nominatim.openstreetmap.org/search"
             . "?q={$encoded}&format=json&limit=1&countrycodes=vn&addressdetails=0";

    $ctx  = stream_context_create(['http' => [
        'header'  => "User-Agent: CafeProject/2.0\r\n",
        'timeout' => SHIPPING_API_TIMEOUT,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true) ?? [];
    $item = $data[0] ?? null;
    if (!$item) return null;

    return [
        'lng' => (float)$item['lon'],
        'lat' => (float)$item['lat'],
    ];
}

// ================================================================
// PROVIDER 2: Nominatim (OSM) geocode + Haversine
// Hoàn toàn miễn phí, không cần API key
// Độ chính xác ~85-90%, nhân hệ số 1.4 để ước lượng đường bộ
// ================================================================
function _distanceNominatimHaversine(string $address): array {
    $address = normalizeShippingAddress($address);

    $storeLat = defined('STORE_LAT') ? (float)STORE_LAT : 21.0285;
    $storeLng = defined('STORE_LNG') ? (float)STORE_LNG : 105.8542;

    // Geocode địa chỉ khách qua Nominatim
    $encoded  = urlencode($address . ', Việt Nam');
    $url      = "https://nominatim.openstreetmap.org/search"
              . "?q={$encoded}&format=json&limit=1&countrycodes=vn&addressdetails=1";
    $ctx      = stream_context_create(['http' => [
        'header'  => "User-Agent: CafeProject/2.0 (contact@cafe.vn)\r\n",
        'timeout' => SHIPPING_API_TIMEOUT,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);

    if ($resp === false || empty($resp)) {
        return _distanceDefaultFallback($address);
    }

    $results = json_decode($resp, true);
    if (empty($results)) {
        // Thử lại với địa chỉ rút gọn (chỉ lấy phần sau dấu phẩy cuối)
        $parts   = explode(',', $address);
        $shorter = implode(',', array_slice($parts, -2));
        return _distanceNominatimHaversine(trim($shorter) . ' Việt Nam');
    }

    $customerLat = (float)$results[0]['lat'];
    $customerLng = (float)$results[0]['lon'];

    // Haversine
    $km = _haversineKm($storeLat, $storeLng, $customerLat, $customerLng);

    // Nhân hệ số đường bộ (hiện tại = 1, không tăng thêm 15%)
    $kmRoad = round($km * HAVERSINE_ROAD_FACTOR, 2);

    return [
        'ok'         => true,
        'km'         => $kmRoad,
        'method'     => 'nominatim_haversine',
        'error'      => '',
        'from_cache' => false,
        'note'       => 'Khoảng cách ước lượng',
    ];
}

// ── Fallback cuối cùng nếu tất cả thất bại ──────────────────────
function _distanceDefaultFallback(string $address): array {
    error_log("[ShippingEngine] All providers failed for: {$address}");
    return [
        'ok'         => false,
        'km'         => 3.0,
        'method'     => 'default_fallback',
        'error'      => 'Không geocode được địa chỉ, dùng 3km mặc định',
        'from_cache' => false,
    ];
}

// ================================================================
// HAVERSINE FORMULA
// ================================================================
function _haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ================================================================
// HTTP GET HELPER
// ================================================================
function _httpGet(string $url): string|false {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: CafeProject/2.0\r\n",
        'timeout' => SHIPPING_API_TIMEOUT,
    ]]);
    return @file_get_contents($url, false, $ctx);
}

// ================================================================
// CACHE — lưu kết quả vào bảng shipping_distance_cache
// Tránh gọi API lặp lại cho cùng địa chỉ
// ================================================================
function _getDistanceCache(string $address, PDO $pdo): ?array {
    try {
        $stmt = $pdo->prepare(
            "SELECT km, method FROM shipping_distance_cache
             WHERE address_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             LIMIT 1"
        );
        $stmt->execute([md5(strtolower(trim($address))), SHIPPING_CACHE_TTL]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null; // Bảng chưa tạo → bỏ qua cache
    }
}

function _saveDistanceCache(string $address, float $km, string $method, PDO $pdo): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO shipping_distance_cache (address_hash, address_text, km, method)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE km = VALUES(km), method = VALUES(method), created_at = NOW()"
        );
        $stmt->execute([md5(strtolower(trim($address))), mb_substr($address, 0, 300), $km, $method]);
    } catch (Exception $e) {
        // Bảng chưa tạo → bỏ qua
    }
}

// ================================================================
// TÍNH PHÍ VẬN CHUYỂN THEO KM
// ================================================================
if (!function_exists('calculateShippingFee')) {
    function calculateShippingFee(float $km): int {
        if ($km <= 2)  return 10000;
        if ($km <= 5)  return 15000;
        if ($km <= 10) return 20000 + (int)(ceil($km - 5) * 3000);
        // > 10km: cộng thêm 2500đ/km (shipper xa hơn)
        return 35000 + (int)(ceil($km - 10) * 2500);
    }
}