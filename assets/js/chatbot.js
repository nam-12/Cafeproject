// ============================================================
// assets/js/chatbot.js
// Logic hội thoại chatbot phía trình duyệt
// ============================================================

(function () {
  "use strict";

  const API_URL = "chatbot_api.php";

  // ── DOM refs ────────────────────────────────────────────
  const toggle = document.getElementById("chatbot-toggle");
  const window_ = document.getElementById("chatbot-window");
  const closeBtn = document.getElementById("chatbot-close");
  const messages = document.getElementById("chatbot-messages");
  const input = document.getElementById("chatbot-input");
  const sendBtn = document.getElementById("chatbot-send");
  const badge = document.getElementById("chatbot-badge");
  const suggestions = document.getElementById("chatbot-suggestions");

  if (!toggle) return; // Widget không có trên trang này

  let isOpen = false;
  let isSending = false;
  let unreadCount = 0;

  // ── Mở / đóng chatbot ───────────────────────────────────
  toggle.addEventListener("click", () => {
    isOpen = !isOpen;
    window_.style.display = isOpen ? "flex" : "none";
    toggle.classList.toggle("active", isOpen);

    if (isOpen) {
      unreadCount = 0;
      badge.style.display = "none";
      input.focus();
      scrollToBottom();
    }
  });

  closeBtn.addEventListener("click", () => {
    isOpen = false;
    window_.style.display = "none";
    toggle.classList.remove("active");
  });

  // Đóng khi nhấn ESC
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && isOpen) {
      isOpen = false;
      window_.style.display = "none";
      toggle.classList.remove("active");
    }
  });

  // ── Gửi tin nhắn ────────────────────────────────────────
  async function sendMessage(text) {
    text = text.trim();
    if (!text || isSending) return;

    // Ẩn gợi ý sau tin nhắn đầu
    if (suggestions) suggestions.style.display = "none";

    appendMessage("user", text);
    input.value = "";
    isSending = true;
    sendBtn.disabled = true;

    // Typing indicator
    const typingId = appendTyping();

    try {
      const resp = await fetch(API_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        credentials: "same-origin", // tránh bị chặn cookie
        body: JSON.stringify({ message: text }),
      });

      if (!resp.ok) throw new Error("HTTP " + resp.status);
      const data = await resp.json();

      removeTyping(typingId);

      if (data.error) {
        appendMessage("assistant", data.reply || "Có lỗi xảy ra.", true);
      } else {
        appendMessage("assistant", data.reply);

        // Hiện nút xem sản phẩm nếu AI đề cập đến
        if (data.product_ids && data.product_ids.length > 0) {
          appendProductLinks(data.product_ids);
        }
      }

      // Badge khi cửa sổ đang đóng
      if (!isOpen) {
        unreadCount++;
        badge.textContent = unreadCount;
        badge.style.display = "inline-block";
      }
    } catch (err) {
      removeTyping(typingId);
      appendMessage(
        "assistant",
        "Xin lỗi, lỗi kết nối. Vui lòng thử lại.",
        true,
      );
      console.error("[Chatbot]", err);
    } finally {
      isSending = false;
      sendBtn.disabled = false;
      input.focus();
    }
  }

  // ── Thêm tin nhắn vào khung chat ────────────────────────
  function appendMessage(role, text, isError = false) {
    const div = document.createElement("div");
    div.className = `chat-message ${role}${isError ? " error" : ""}`;

    const bubble = document.createElement("div");
    bubble.className = "chat-bubble";
    bubble.innerHTML = markdownToHtml(escHtml(text));
    div.appendChild(bubble);

    const time = document.createElement("div");
    time.className = "chat-time";
    time.textContent = new Date().toLocaleTimeString("vi-VN", {
      hour: "2-digit",
      minute: "2-digit",
    });
    div.appendChild(time);

    messages.appendChild(div);
    scrollToBottom();
  }

  // ── Nút liên kết đến sản phẩm ───────────────────────────
  function appendProductLinks(ids) {
    const div = document.createElement("div");
    div.className = "chat-product-links";

    ids.slice(0, 3).forEach((id) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn btn-sm btn-outline-secondary me-1 mb-1";
      btn.style.borderColor = "#5D4037";
      btn.style.color = "#5D4037";
      btn.innerHTML = `<i class="fas fa-eye me-1"></i>Xem sản phẩm #${id}`;
      btn.addEventListener('click', () => {
        if (typeof showProductDetail === 'function') {
          showProductDetail(id);
        } else {
          window.location.href = `product_detail.php?id=${id}`;
        }
      });
      div.appendChild(btn);
    });

    messages.appendChild(div);
    scrollToBottom();
  }

  // ── Typing indicator ────────────────────────────────────
  function appendTyping() {
    const id = "typing-" + Date.now();
    const div = document.createElement("div");
    div.id = id;
    div.className = "chat-message assistant";
    div.innerHTML = `<div class="chat-bubble typing">
            <span></span><span></span><span></span>
        </div>`;
    messages.appendChild(div);
    scrollToBottom();
    return id;
  }

  function removeTyping(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
  }

  // ── Chuyển đổi Markdown đơn giản sang HTML ───────────────
  function markdownToHtml(text) {
    return (
      text
        // **bold**
        .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
        // *italic*
        .replace(/\*(.+?)\*/g, "<em>$1</em>")
        // - danh sách
        .replace(/^- (.+)$/gm, "<li>$1</li>")
        // Bọc <li> vào <ul>
        .replace(
          /(<li>.*<\/li>)/s,
          '<ul style="margin:4px 0 4px 16px;padding:0;">$1</ul>',
        )
        // Xuống dòng
        .replace(/\n/g, "<br>")
    );
  }

  // ── Escape HTML chống XSS ────────────────────────────────
  function escHtml(str) {
    const d = document.createElement("div");
    d.textContent = str || "";
    return d.innerHTML;
  }

  function scrollToBottom() {
    messages.scrollTop = messages.scrollHeight;
  }

  // ── Gợi ý nhanh (toàn cục để onclick HTML gọi được) ────
  window.sendSuggestion = (text) => sendMessage(text);

  // ── Kết nối sự kiện ─────────────────────────────────────
  sendBtn.addEventListener("click", () => sendMessage(input.value));

  input.addEventListener("keypress", (e) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage(input.value);
    }
  });

  // Giới hạn nhập hiển thị ký tự còn lại
  input.addEventListener("input", function () {
    const remaining = 300 - this.value.length;
    sendBtn.title = remaining < 50 ? `Còn ${remaining} ký tự` : "";
  });
})();
