# Hướng Dẫn Thiết Lập AI (Gemini API)

## Tổng Quan
Hệ thống sử dụng Google Gemini API cho các tính năng AI:
- Tìm kiếm thông minh sản phẩm
- Chatbot tư vấn

## Cấu Trúc Database
Database đã được cập nhật với các bảng mới:
- `settings`: Lưu trữ cấu hình AI (API key, cài đặt)
- `ai_search_log`: Ghi log tìm kiếm AI để phân tích

## Cập Nhật API Key

### Bước 1: Lấy API Key từ Google AI Studio
1. Truy cập: https://makersuite.google.com/app/apikey
2. Đăng nhập tài khoản Google
3. Tạo API key mới
4. Copy API key (dạng: `AIzaSy...`)

### Bước 2: Cập Nhật Trong Database
1. Mở phpMyAdmin
2. Chọn database `cafe_management`
3. Vào bảng `settings`
4. Tìm dòng có `key = 'gemini_api_key'`
5. Cập nhật cột `value` với API key thật của bạn

### Bước 3: Kiểm Tra
1. Truy cập trang chủ khách hàng
2. Thử tìm kiếm: "ít calo", "cà phê lạnh", etc.
3. Kiểm tra chatbot góc phải dưới

## Cài Đặt Khác
Trong bảng `settings`, bạn có thể điều chỉnh:
- `ai_search_enabled`: Bật/tắt tìm kiếm AI (1/0)
- `chatbot_enabled`: Bật/tắt chatbot (1/0)
- `ai_model`: Model AI (gemini-pro, gemini-pro-vision)
- `ai_max_tokens`: Giới hạn token (1024)
- `ai_temperature`: Độ sáng tạo (0.0-1.0)

## Lưu Ý
- API key được mã hóa và lưu trữ an toàn
- Nếu API key sai, hệ thống sẽ fallback về tìm kiếm thông thường
- Gemini API miễn phí với giới hạn sử dụng

## Troubleshooting
- Nếu không hoạt động: Kiểm tra API key có đúng không
- Nếu lỗi quota: Chờ reset hoặc nâng cấp tài khoản Google
- Logs lỗi: Xem trong `logs/` hoặc error_log PHP

## Khắc Phục Lỗi Thường Gặp

### Lỗi "Unexpected token '<', "<br />" trong chatbot.js
**Nguyên nhân:** Server trả về HTML error page thay vì JSON
**Giải pháp:**
1. Database chưa có bảng `chat_history` → Đã tự động tạo
2. API key chưa đúng → Cập nhật theo hướng dẫn trên
3. Nếu vẫn lỗi, kiểm tra phpMyAdmin xem bảng `settings` có tồn tại không

### Lỗi "API key not valid"
**Nguyên nhân:** API key Gemini chưa được cập nhật
**Giải pháp:** Làm theo Bước 2 ở trên để cập nhật API key thật
