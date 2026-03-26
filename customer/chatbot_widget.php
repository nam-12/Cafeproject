<?php
// ============================================================
// templates/chatbot_widget.php
// Include file này vào cuối mỗi trang khách hàng:
// <?php include '../templates/chatbot_widget.php'; ?>



<!-- ── Nút mở chatbot (góc phải dưới) ── -->
<button id="chatbot-toggle" class="chatbot-toggle-btn" aria-label="Mở tư vấn AI">
    <i class="fas fa-robot" id="chatbot-icon"></i>
    <span class="chatbot-badge" id="chatbot-badge" style="display:none;">1</span>
</button>

<!-- ── Hộp chat ── -->
<div id="chatbot-window" class="chatbot-window" style="display:none;" role="dialog" aria-label="Chat với AI tư vấn">

    <!-- Header -->
    <div class="chatbot-header">
        <div class="chatbot-avatar">
            <i class="fas fa-robot"></i>
        </div>
        <div>
            <div class="chatbot-title">Trợ Lý Cà Phê AI</div>
            <div class="chatbot-status">
                <span class="status-dot"></span> Trực tuyến
            </div>
        </div>
        <button class="chatbot-close" id="chatbot-close" aria-label="Đóng chat">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Vùng tin nhắn -->
    <div class="chatbot-messages" id="chatbot-messages" role="log" aria-live="polite">
        <!-- Tin nhắn chào hỏi đầu tiên -->
        <div class="chat-message assistant">
            <div class="chat-bubble">
                Xin chào! 👋 Tôi có thể giúp bạn tìm đồ uống phù hợp.<br>
                Thử hỏi: <em>'Món nào ít calo?'</em> hoặc <em>'Đồ uống mát cho mùa hè?'</em>
            </div>
            <div class="chat-time">Ngay bây giờ</div>
        </div>
    </div>

    <!-- Gợi ý nhanh -->
    <div class="chatbot-suggestions" id="chatbot-suggestions">
        <button class="suggestion-chip" onclick="sendSuggestion('Món nào ít calo?')">
            🥗 Ít calo
        </button>
        <button class="suggestion-chip" onclick="sendSuggestion('Đồ uống nào phù hợp mùa hè?')">
            ☀️ Mùa hè
        </button>
        <button class="suggestion-chip" onclick="sendSuggestion('Món nào được yêu thích nhất?')">
            ⭐ Phổ biến
        </button>
        <button class="suggestion-chip" onclick="sendSuggestion('Cà phê nào dưới 50000?')">
            💰 Giá rẻ
        </button>
    </div>

    <!-- Ô nhập liệu -->
    <div class="chatbot-input-area">
        <input type="text"
               id="chatbot-input"
               placeholder="Hỏi về đồ uống, giá, thành phần..."
               maxlength="300"
               autocomplete="off"
               aria-label="Nhập tin nhắn">
        <button id="chatbot-send" aria-label="Gửi tin nhắn">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>

</div>

<link rel="stylesheet" href="../assets/css/ai_components.css">
<script src="../assets/js/chatbot.js"></script>