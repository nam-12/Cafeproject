<?php
// ============================================================
// customer/search.php
// API endpoint: nhận từ khóa → trả về danh sách sản phẩm AI (sử dụng Gemini)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once '../config/init.php';
require_once '../config/ai_config.php';

// ── Xử lý log click (beacon từ JS) ──────────────────────────
if (isset($_GET['log_click']) && isset($_GET['product_id'])) {
    session_start();
    $clickStmt = $pdo->prepare(
        "UPDATE ai_search_log SET clicked_id = ?
         WHERE session_id = ? AND query = ?
         ORDER BY id DESC LIMIT 1"
    );
    $clickStmt->execute([
        (int)$_GET['product_id'],
        session_id(),
        trim($_GET['q'] ?? ''),
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Lấy và validate từ khóa ─────────────────────────────────
$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    echo json_encode(['products' => [], 'ai_used' => false, 'count' => 0]);
    exit;
}
$query = htmlspecialchars(strip_tags($query), ENT_QUOTES, 'UTF-8');

// ── Rate limiting đơn giản ───────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$now = time();
$_SESSION['_search_calls'] = array_filter(
    $_SESSION['_search_calls'] ?? [],
    fn($t) => ($now - $t) < 60
);
if (count($_SESSION['_search_calls']) >= 20) {
    echo json_encode(['products' => [], 'ai_used' => false, 'error' => 'Quá nhiều yêu cầu']);
    exit;
}
$_SESSION['_search_calls'][] = $now;

// ── Kiểm tra cache session ────────────────────────────────────
$cacheKey = 'search_' . md5($query);
if (isset($_SESSION[$cacheKey])) {
    echo $_SESSION[$cacheKey];
    exit;
}

// ── Lấy danh sách sản phẩm từ DB ────────────────────────────
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.price, p.category_id,
            p.description, p.ai_metadata, p.image,
            c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.status = 'active'
     ORDER BY p.name ASC
     LIMIT " . SEARCH_PRODUCTS_LIMIT
);
$stmt->execute();
$allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allProducts)) {
    echo json_encode(['products' => [], 'ai_used' => false, 'count' => 0]);
    exit;
}

// ── BƯỚC 1: Thử tìm kiếm AI ──────────────────────────────────
$aiUsed    = false;
$resultIds = [];
$aiExplain = '';

try {
    // Tạo danh sách ngắn gọn để gửi cho AI (tiết kiệm token)
    $productList = array_map(fn($p) => sprintf(
        '[ID:%d] %s — Loại: %s — Giá: %s VND — %s',
        $p['id'],
        $p['name'],
        $p['category_name'] ?? 'Khác',
        number_format($p['price']),
        mb_substr($p['ai_metadata'] ?: $p['description'] ?: '', 0, 120)
    ), $allProducts);

    $systemPrompt = <<<PROMPT
Bạn là trợ lý AI cho quán cà phê Việt Nam.
Nhiệm vụ: Cho danh sách sản phẩm, hãy tìm những sản phẩm phù hợp nhất với yêu cầu tìm kiếm của khách hàng.

QUY TẮC:
- Chỉ trả về JSON, KHÔNG thêm bất kỳ văn bản nào khác
- Format: {"ids": [id1, id2, ...], "reason": "lý giải ngắn bằng tiếng Việt"}
- Trả về tối đa 8 sản phẩm phù hợp nhất, xếp theo độ liên quan giảm dần
- Nếu không có sản phẩm nào phù hợp, trả về ids: []
- Hiểu cả tiếng Việt lẫn tiếng Anh, cả từ thông thường lẫn chuyên ngành
- Hiểu ngữ nghĩa: "ngọt nhẹ" = ít đường, "mát" = đồ uống lạnh, "ít calo" = không đường/ít béo
PROMPT;

    $userMessage = "Danh sách sản phẩm:\n" . implode("\n", $productList)
                 . "\n\nTừ khóa tìm kiếm: \"" . $query . '"';

    $aiResponse = callGeminiAPI($systemPrompt, [
        ['role' => 'user', 'content' => $userMessage]
    ], $pdo);

    // Parse JSON từ AI (xóa ```json ``` nếu có)
    $aiResponse = preg_replace('/```json\s*|```/i', '', $aiResponse);
    $aiData     = json_decode(trim($aiResponse), true);

    if (isset($aiData['ids']) && is_array($aiData['ids'])) {
        $resultIds = array_map('intval', $aiData['ids']);
        $aiExplain = $aiData['reason'] ?? '';
        $aiUsed    = true;
    }

} catch (Exception $e) {
    // AI thất bại → tự động dùng fallback LIKE bình thường
    error_log('[search.php] AI thất bại, dùng fallback: ' . $e->getMessage());
}

// ── BƯỚC 2: Fallback — tìm kiếm LIKE thông thường ────────────
if (!$aiUsed) {
    $likeSql  = "SELECT id FROM products
                  WHERE status = 'active'
                    AND (name LIKE ? OR description LIKE ? OR ai_metadata LIKE ?)
                  LIMIT 8";
    $likeStmt = $pdo->prepare($likeSql);
    $likeValue = '%' . $query . '%';
    $likeStmt->execute([$likeValue, $likeValue, $likeValue]);
    $resultIds = $likeStmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── BƯỚC 3: Lấy chi tiết sản phẩm theo thứ tự AI xếp hạng ──
$products = [];
if (!empty($resultIds)) {
    $placeholders = implode(',', array_fill(0, count($resultIds), '?'));
    $detailStmt   = $pdo->prepare(
        "SELECT id, name, price, description, image, category_id
         FROM products
         WHERE id IN ($placeholders) AND status = 'active'"
    );
    $detailStmt->execute($resultIds);
    $raw     = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    $indexed = array_column($raw, null, 'id');

    // Giữ đúng thứ tự AI đề xuất
    foreach ($resultIds as $id) {
        if (isset($indexed[$id])) {
            $products[] = $indexed[$id];
        }
    }
}

// ── BƯỚC 4: Lưu log tìm kiếm ─────────────────────────────────
try {
    $logStmt = $pdo->prepare(
        "INSERT INTO ai_search_log (session_id, user_id, query, results_ids)
         VALUES (?, ?, ?, ?)"
    );
    $logStmt->execute([
        session_id(),
        $_SESSION['user_id'] ?? null,
        $query,
        json_encode($resultIds),
    ]);
} catch (Exception $e) {
    error_log('[search.php] Log error: ' . $e->getMessage());
}

// ── Cache kết quả vào session 5 phút ─────────────────────────
$output = json_encode([
    'products'  => $products,
    'ai_used'   => $aiUsed,
    'ai_reason' => $aiExplain,
    'count'     => count($products),
]);
$_SESSION[$cacheKey]           = $output;
$_SESSION[$cacheKey . '_time'] = $now;

// Xóa cache cũ hơn 5 phút
foreach (array_keys($_SESSION) as $k) {
    if (str_starts_with($k, 'search_') && str_ends_with($k, '_time')) {
        if ($now - $_SESSION[$k] > 300) {
            $baseKey = str_replace('_time', '', $k);
            unset($_SESSION[$baseKey], $_SESSION[$k]);
        }
    }
}

echo $output;