<?php
// ============================================================
// customer/chatbot_api.php
// API endpoint cho chatbot — nhận POST, trả về JSON (sử dụng Gemini)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/init.php';
require_once '../config/ai_config.php';

// Chỉ chấp nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Rate limiting: tối đa 20 req/phút/session ────────────────
$now = time();
$_SESSION['_chat_calls'] = array_filter(
    $_SESSION['_chat_calls'] ?? [],
    fn($t) => ($now - $t) < 60
);
if (count($_SESSION['_chat_calls']) >= 20) {
    http_response_code(429);
    echo json_encode([
        'reply' => 'Bạn đang gửi tin nhắn quá nhanh. Vui lòng đợi 1 phút rồi thử lại.',
        'error' => true,
    ]);
    exit;
}
$_SESSION['_chat_calls'][] = $now;

// ── Lấy và validate tin nhắn đầu vào ────────────────────────
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim($input['message'] ?? '');

if (empty($message)) {
    echo json_encode(['error' => 'Tin nhắn trống']);
    exit;
}
if (mb_strlen($message) > 500) {
    echo json_encode(['reply' => 'Tin nhắn quá dài (tối đa 500 ký tự).', 'error' => true]);
    exit;
}

// Sanitize input
$message = htmlspecialchars(strip_tags($message), ENT_QUOTES, 'UTF-8');

// ── BƯỚC 1: Lấy menu sản phẩm từ DB ─────────────────────────
$menuStmt = $pdo->query(
    "SELECT p.id, p.name, p.price, p.description,
            p.ai_metadata, c.name AS category
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.status = 'active'
     ORDER BY c.name, p.name"
);
$menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo văn bản menu gọn cho AI
$menuText = "MENU HIỆN TẠI:\n";
foreach ($menuItems as $item) {
    $menuText .= sprintf(
        "- %s [ID:%d] | Giá: %s VND | Loại: %s | %s\n",
        $item['name'],
        $item['id'],
        number_format($item['price']),
        $item['category'] ?? 'Khác',
        mb_substr($item['ai_metadata'] ?: $item['description'] ?: '', 0, 100)
    );
}

// ── BƯỚC 2: Lấy lịch sử chat từ DB ──────────────────────────
$histStmt = $pdo->prepare(
    "SELECT role, message FROM chat_history
     WHERE session_id = ?
     ORDER BY created_at DESC
     LIMIT " . CHAT_HISTORY_LIMIT
);
$histStmt->execute([session_id()]);
$history = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));

// ── BƯỚC 3: Tạo system prompt ────────────────────────────────
$systemPrompt = <<<PROMPT
Bạn là trợ lý AI thân thiện của quán cà phê Việt Nam.
Nhiệm vụ chính: tư vấn, gợi ý sản phẩm phù hợp cho khách hàng.

$menuText

NGUYÊN TẮC TƯ VẤN:
1. Chỉ gợi ý SẢN PHẨM CÓ TRONG MENU trên — tuyệt đối không bịa
2. Khi gợi ý, luôn kèm: tên sản phẩm, giá, và lý do phù hợp
3. Trả lời ngắn gọn, thân thiện, tiếng Việt tự nhiên
4. Nếu khách hỏi về đặt hàng, hướng dẫn: 'Hãy nhấn vào sản phẩm để thêm vào giỏ hàng'
5. Nếu khách hỏi gì ngoài menu/quán cafe, trả lời ngắn rồi đưa về chủ đề thức uống
6. Dùng format Markdown nhẹ (in đậm tên sản phẩm, dùng - cho danh sách)
PROMPT;

// ── BƯỚC 4: Gọi Gemini API ───────────────────────────────────
$messages = [];
foreach ($history as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['message']];
}
$messages[] = ['role' => 'user', 'content' => $message];

try {
    $reply = callGeminiAPI($systemPrompt, $messages, $pdo);
} catch (Exception $e) {
    error_log('[chatbot_api.php] ' . $e->getMessage());
    $reply = 'Xin lỗi, tôi đang gặp sự cố kỹ thuật. Vui lòng thử lại sau.';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $reply .= ' (DEBUG: ' . $e->getMessage() . ')';
    }
    echo json_encode([
        'reply' => $reply,
        'error' => true,
    ]);
    exit;
}

// ── BƯỚC 5: Lưu cả 2 tin nhắn vào DB ────────────────────────
$saveStmt = $pdo->prepare(
    "INSERT INTO chat_history (session_id, user_id, role, message)
     VALUES (?, ?, ?, ?)"
);
$userId = $_SESSION['user_id'] ?? null;
$saveStmt->execute([session_id(), $userId, 'user',      $message]);
$saveStmt->execute([session_id(), $userId, 'assistant', $reply]);

// ── BƯỚC 6: Trích xuất ID sản phẩm được đề cập ──────────────
// Để frontend hiển thị nút 'Xem sản phẩm' nhanh
$mentionedIds = [];
preg_match_all('/\[ID:(\d+)\]/', $reply, $matches);
if (!empty($matches[1])) {
    $mentionedIds = array_unique(array_map('intval', $matches[1]));
}

// Xóa [ID:x] khỏi văn bản hiển thị cho khách
$cleanReply = preg_replace('/\s*\[ID:\d+\]/', '', $reply);

echo json_encode([
    'reply'       => $cleanReply,
    'product_ids' => array_values($mentionedIds),
    'error'       => false,
]);