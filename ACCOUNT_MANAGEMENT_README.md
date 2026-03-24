# Chức Năng Quản Lý Tài Khoản

## Tổng Quan
Chức năng quản lý tài khoản cho phép admin quản lý tất cả tài khoản người dùng trong hệ thống, bao gồm:
- Thêm tài khoản mới
- Xóa tài khoản
- Cập nhật thông tin tài khoản
- Khóa/Mở tài khoản

## Truy Cập
- **URL**: `/admin/manage_accounts.php`
- **Menu**: Quản lý tài khoản (trong sidebar admin)
- **Quyền yêu cầu**: `manage_roles`, `create_user`, `edit_user`

## Tính Năng

### 1. Thêm Tài Khoản Mới
- Nhập thông tin: tên đăng nhập, email, họ tên, mật khẩu, vai trò
- Validation: kiểm tra trùng lặp, độ dài mật khẩu
- Mật khẩu được hash bằng bcrypt
- Tài khoản mặc định được tạo ở trạng thái hoạt động

### 2. Cập Nhật Thông Tin Tài Khoản
- Chỉnh sửa: tên đăng nhập, email, họ tên, vai trò
- Không thể thay đổi mật khẩu (cần chức năng riêng)
- Validation trùng lặp với tài khoản khác

### 3. Khóa/Mở Tài Khoản
- Thay đổi trạng thái `is_active` trong database
- Tài khoản bị khóa không thể đăng nhập
- Không thể khóa tài khoản admin đầu tiên (ID = 1)

### 4. Xóa Tài Khoản
- Xóa vĩnh viễn tài khoản khỏi hệ thống
- Kiểm tra ràng buộc: không xóa nếu có đơn hàng liên quan
- Không thể xóa tài khoản admin đầu tiên

## Bộ Lọc và Tìm Kiếm
- **Lọc theo vai trò**: Admin, Staff, Customer
- **Lọc theo trạng thái**: Hoạt động, Khóa
- **Tìm kiếm**: theo tên đăng nhập, email, họ tên

## Bảo Mật
- Chỉ admin mới có quyền truy cập
- Sử dụng prepared statements để chống SQL injection
- Sanitize input data
- Log tất cả hoạt động quan trọng

## Database Schema
```sql
-- Bảng users (đã tồn tại)
ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1;

-- Bảng user_roles (đã tồn tại cho phân quyền nâng cao)
-- Bảng activity_logs (đã tồn tại cho audit trail)
```

## Files Được Thêm/Sửa
- `admin/manage_accounts.php` - Trang quản lý tài khoản
- `admin/sidebar.php` - Thêm menu "Quản lý tài khoản"
- `config/helpers.php` - Thêm các hàm role management

## Lưu Ý
- Tài khoản admin đầu tiên (ID = 1) không thể bị xóa hoặc khóa
- Khi xóa tài khoản, kiểm tra không có đơn hàng liên quan
- Tất cả thao tác được log trong `activity_logs`
- Sử dụng AJAX cho các thao tác không reload trang