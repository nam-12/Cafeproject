# Hướng Dẫn Migration: Claude → Gemini API

## 📋 Tổng Quan
Hệ thống đã được cập nhật từ Claude API sang Google Gemini API với schema database mới sử dụng `ai_metadata` thay vì `ai_description`.

## ✅ Những Gì Đã Được Cập Nhật

### 1. Database Schema
- ✅ Thêm cột `ai_metadata` vào bảng `products`
- ✅ Tạo bảng `ai_settings` cho cấu hình AI
- ✅ Tạo bảng `ai_search_log` để theo dõi tìm kiếm AI
- ✅ Tạo bảng `chat_history` cho lịch sử chat
- ✅ Thêm stored procedure và trigger tự động cập nhật

### 2. PHP Files
- ✅ `customer/search.php` - Sử dụng `ai_metadata` cho tìm kiếm AI
- ✅ `customer/chatbot_api.php` - Sử dụng `ai_metadata` cho chatbot
- ✅ `admin/product_form.php` - Thêm trường chỉnh sửa AI metadata
- ✅ `config/ai_config.php` - Cấu hình Gemini API

### 3. Scripts
- ✅ `update_schema.php` - Cập nhật database schema
- ✅ `generate_ai_metadata.php` - Tự động tạo AI metadata

## 🚀 Cách Sử Dụng

### Bước 1: Cập Nhật Database
```bash
cd e:\xampp\htdocs\cafe
php update_schema.php
```

### Bước 2: Tạo AI Metadata Cho Sản Phẩm Hiện Có
```bash
php generate_ai_metadata.php
```

### Bước 3: Cấu Hình Gemini API Key
1. Vào phpMyAdmin
2. Mở bảng `ai_settings`
3. Tìm row có `key = 'gemini_api_key'`
4. Cập nhật `value` với API key thật từ Google AI Studio

### Bước 4: Kiểm Tra Hệ Thống
- Truy cập trang web và thử tìm kiếm AI
- Thử chatbot để xem có hoạt động không
- Vào admin để chỉnh sửa AI metadata của sản phẩm

## 🔧 Cấu Hình AI (Bảng ai_settings)

| Key | Mô tả | Giá trị mặc định |
|-----|-------|------------------|
| `gemini_api_key` | API key cho Google Gemini | YOUR_GEMINI_API_KEY_HERE |
| `ai_search_enabled` | Bật/tắt tìm kiếm AI | 1 |
| `chatbot_enabled` | Bật/tắt chatbot | 1 |
| `ai_model` | Model AI sử dụng | gemini-pro |
| `ai_max_tokens` | Số token tối đa | 1024 |
| `ai_temperature` | Độ sáng tạo AI | 0.7 |

## 📊 Cấu Trúc Database Mới

### Bảng products
- Thêm cột `ai_metadata TEXT` - Thông tin cho AI hiểu về sản phẩm

### Bảng settings
- Lưu trữ cấu hình AI và hệ thống

### Bảng ai_search_log
- Theo dõi tìm kiếm AI: session, query, kết quả, click

### Bảng chat_history
- Lưu lịch sử chat với AI

## 🛠️ API Endpoints

### Tìm kiếm AI
```
GET /customer/search.php?q={query}
```
- Sử dụng Gemini để tìm sản phẩm phù hợp
- Fallback về LIKE search nếu AI thất bại

### Chatbot
```
POST /customer/chatbot_api.php
Content-Type: application/json
{"message": "Xin chào"}
```
- Chat với AI về menu và sản phẩm
- Lưu lịch sử chat

## 🔍 Debug và Troubleshooting

### Kiểm tra API key
```sql
SELECT * FROM ai_settings WHERE `setting_key` = 'gemini_api_key';
```

### Xem log lỗi
- Kiểm tra file `logs/` trong thư mục config
- Xem PHP error log trong XAMPP

### Test AI search
```bash
curl "http://localhost/cafe/customer/search.php?q=cà phê sữa"
```

### Test chatbot
```bash
curl -X POST "http://localhost/cafe/customer/chatbot_api.php" \
  -H "Content-Type: application/json" \
  -d '{"message":"Menu hôm nay có gì"}'
```

## 📝 Lưu Ý Quan Trọng

1. **API Key**: Phải cấu hình Gemini API key thật để AI hoạt động
2. **AI Metadata**: Thông tin này giúp AI hiểu rõ hơn về sản phẩm
3. **Rate Limiting**: Hệ thống có giới hạn 20 request/phút cho mỗi session
4. **Cache**: Kết quả tìm kiếm được cache trong session để tăng tốc

## 🎯 Tính Năng Mới

- **Tìm kiếm thông minh**: AI hiểu ngữ nghĩa, không chỉ tìm từ khóa
- **Chatbot tư vấn**: Gợi ý sản phẩm phù hợp dựa trên sở thích
- **Theo dõi hành vi**: Log tìm kiếm và click để cải thiện AI
- **Cấu hình linh hoạt**: Có thể bật/tắt AI qua database settings

## 🔄 Rollback (Nếu Cần)

Nếu cần quay về phiên bản cũ:
1. Backup database hiện tại
2. Chạy script rollback (nếu có)
3. Khôi phục API key Claude
4. Cập nhật code về commit trước migration

---

**Migration hoàn tất!** 🎉 Hệ thống đã sẵn sàng sử dụng với Gemini API.</content>
<parameter name="filePath">e:\xampp\htdocs\cafe\MIGRATION_GUIDE.md