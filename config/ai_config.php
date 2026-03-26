<?php
// ============================================================
// config/ai_config.php
// Cấu hình trung tâm cho tất cả tính năng AI (sử dụng Gemini API)
// Tất cả file PHP khác require_once file này
// ============================================================

// ── Hằng số cấu hình ────────────────────────────────────────
define('GEMINI_API_URL_BASE', 'https://generativelanguage.googleapis.com/v1');
define('GEMINI_DEFAULT_MODEL', 'gemini-2.5-flash');
define('GEMINI_MAX_TOKENS',     1024);
define('CHAT_HISTORY_LIMIT',    10);   // Số tin nhắn lịch sử gửi lên AI
define('SEARCH_PRODUCTS_LIMIT', 30);   // Số sản phẩm tối đa gửi cho AI phân tích

/**
 * Đọc setting từ DB — có cache tĩnh để tránh query lặp
 */
function getAiSetting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $cache[$key] = $stmt->fetchColumn() ?: $default;
    }
    return $cache[$key];
}

/**
 * callGeminiAPI()
 *
 * Hàm trung tâm gọi Gemini API. Tất cả tính năng AI dùng hàm này.
 *
 * @param string $systemPrompt  Hướng dẫn cho AI (vai trò, dữ liệu, quy tắc)
 * @param array  $messages      Mảng tin nhắn [{role, content}, ...]
 * @param PDO    $pdo           Kết nối DB để lấy API key
 * @return string               Phản hồi văn bản từ AI
 * @throws RuntimeException     Khi API thất bại
 */
function callGeminiAPI(string $systemPrompt, array $messages, PDO $pdo): string {
    $apiKey = getAiSetting($pdo, 'gemini_api_key');

    $invalidKeyExamples = ['YOUR_GEMINI_API_KEY_HERE'];
    $keyInvalid = empty($apiKey);
    foreach ($invalidKeyExamples as $bad) {
        if (str_contains($apiKey, $bad)) {
            $keyInvalid = true;
            break;
        }
    }

    if ($keyInvalid) {
        throw new RuntimeException('Gemini API key chưa được cấu hình hợp lệ. Vào phpMyAdmin → bảng ai_settings → cập nhật gemini_api_key (key dạng ai-.../... or full key từ Google Cloud).');
    }

    // Kết hợp system prompt và messages thành một text
    $fullPrompt = $systemPrompt . "\n\n";
    foreach ($messages as $msg) {
        $fullPrompt .= $msg['content'] . "\n";
    }

    // Lấy model từ bảng ai_settings (hoặc dùng giá trị mặc định)
    $model = getAiSetting($pdo, 'gemini_model', GEMINI_DEFAULT_MODEL);
    if (empty($model)) {
        throw new RuntimeException('Không tìm thấy gemini_model trong ai_settings và cũng không có giá trị mặc định.');
    }

    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => trim($fullPrompt)]
                ]
            ]
        ]
    ]);

    $url = GEMINI_API_URL_BASE . '/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException('cURL error: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? 'HTTP ' . $httpCode;
        throw new RuntimeException('Gemini API error: ' . $errMsg);
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}