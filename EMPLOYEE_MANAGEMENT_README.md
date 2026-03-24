# Quản Lý Nhân Viên - Hệ Thống Cafe Management

## Tổng Quan

Chức năng quản lý nhân viên cho phép admin thêm, sửa, xóa và quản lý thông tin nhân viên trong hệ thống cafe.

## Tính Năng Chính

### 1. Thêm Nhân Viên Mới
- **Thông tin bắt buộc**: Họ tên, tên đăng nhập, email, mật khẩu, vai trò
- **Thông tin tùy chọn**: Số điện thoại, địa chỉ
- **Validation**:
  - Kiểm tra email hợp lệ
  - Mật khẩu ít nhất 6 ký tự
  - Tên đăng nhập và email không trùng lặp
  - Phải chọn ít nhất một vai trò

### 2. Sửa Thông Tin Nhân Viên
- **Có thể sửa**: Họ tên, tên đăng nhập, email, số điện thoại, địa chỉ, vai trò
- **Không thể sửa**: Mật khẩu (phải reset riêng)
- **Validation**: Giống như thêm nhân viên

### 3. Quản Lý Trạng Thái
- **Kích hoạt/Vô hiệu hóa** tài khoản nhân viên
- **Không thể** vô hiệu hóa tài khoản admin chính (ID = 1)

### 4. Xóa Nhân Viên
- **Xóa vĩnh viễn** nhân viên khỏi hệ thống
- **Xóa cả** vai trò và quyền hạn liên quan
- **Không thể** xóa tài khoản admin chính

## Giao Diện Người Dùng

### Danh Sách Nhân Viên
- **Hiển thị**: Avatar (chữ cái đầu), họ tên, username, email, trạng thái, vai trò
- **Tương tác**: Click để xem chi tiết và chỉnh sửa
- **Sắp xếp**: Theo ngày tạo (mới nhất trước)

### Chi Tiết Nhân Viên
- **Thông tin cơ bản**: Họ tên, username, email, phone, address, ngày tạo
- **Vai trò & quyền**: Danh sách vai trò được gán
- **Hành động**: Sửa thông tin, xóa nhân viên, thay đổi trạng thái

### Modal Thêm Nhân Viên
- **Form đầy đủ** với validation real-time
- **Chọn nhiều vai trò** bằng checkbox
- **Giao diện thân thiện** với Bootstrap

### Modal Sửa Nhân Viên
- **Tự động load** dữ liệu nhân viên qua AJAX
- **Cập nhật thông tin** và vai trò
- **Validation** giống như thêm mới

## Bảo Mật & Quyền Hạn

### Quyền Truy Cập
- **Chỉ admin** mới có thể truy cập
- **Kiểm tra quyền**: `manage_roles`, `create_user`, `edit_user`

### Validation & Sanitization
- **Sanitize input** để tránh XSS
- **Validate email** format
- **Check trùng lặp** username/email
- **Password hashing** với bcrypt

### Bảo Vệ Dữ Liệu
- **Không thể** xóa/vô hiệu hóa admin chính
- **Log hoạt động** cho tất cả thao tác quan trọng
- **Transaction safety** cho các thao tác phức tạp

## Cơ Sở Dữ Liệu

### Bảng `users`
```sql
- id: Primary key
- username: Tên đăng nhập (unique)
- password: Mật khẩu đã hash
- email: Email (unique)
- full_name: Họ tên đầy đủ
- phone: Số điện thoại
- address: Địa chỉ
- role: Vai trò chính (admin/staff/customer)
- is_active: Trạng thái hoạt động
- created_at: Ngày tạo
- updated_at: Ngày cập nhật
```

### Bảng `user_roles`
```sql
- user_id: Foreign key -> users.id
- role_id: Foreign key -> roles.id
```

### Bảng `roles`
```sql
- id: Primary key
- name: Tên role (unique)
- display_name: Tên hiển thị
- description: Mô tả
```

## API Endpoints

### POST Actions
- `add_employee`: Thêm nhân viên mới
- `edit_employee`: Sửa thông tin nhân viên
- `toggle_status`: Thay đổi trạng thái hoạt động
- `delete_employee`: Xóa nhân viên

### GET Parameters
- `user_id`: ID nhân viên để xem chi tiết
- `ajax=1`: Trả về JSON data cho AJAX requests

## JavaScript Features

### Auto-hide Alerts
```javascript
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 2000);
```

### AJAX Edit Employee
- **Fetch data** từ server
- **Populate form** tự động
- **Error handling** với user feedback

### Confirm Actions
- **Delete confirmation** với tên nhân viên
- **Status change** confirmation

## Error Handling

### PHP Errors
- **Database errors**: Try-catch blocks
- **Validation errors**: User-friendly messages
- **Permission errors**: Redirect với thông báo

### JavaScript Errors
- **AJAX errors**: Console logging + user alerts
- **Form validation**: Real-time feedback

## Logging

Tất cả hoạt động quan trọng được log:
- `add_employee`: Thêm nhân viên mới
- `edit_employee`: Sửa thông tin nhân viên
- `toggle_employee_status`: Thay đổi trạng thái
- `delete_employee`: Xóa nhân viên

## UI/UX Improvements

### Visual Design
- **Avatar initials** cho từng nhân viên
- **Status badges** với màu sắc trực quan
- **Role tags** hiển thị vai trò
- **Hover effects** và transitions

### Responsive Design
- **Mobile-friendly** layout
- **Flexible grid** system
- **Collapsible sections** trên mobile

### User Experience
- **Loading states** cho AJAX operations
- **Success/error feedback** với auto-hide
- **Confirmation dialogs** cho destructive actions
- **Form validation** real-time

## Performance Optimizations

### Database Queries
- **Single query** lấy danh sách nhân viên với roles
- **Indexed fields** cho tìm kiếm nhanh
- **Prepared statements** chống SQL injection

### Frontend Optimization
- **Minimal DOM manipulation**
- **Efficient event handling**
- **Lazy loading** cho danh sách dài

## Testing Scenarios

### Happy Path
1. Thêm nhân viên với thông tin đầy đủ
2. Sửa thông tin nhân viên
3. Thay đổi vai trò
4. Kích hoạt/vô hiệu hóa
5. Xóa nhân viên

### Edge Cases
1. Submit form với thông tin thiếu
2. Email/username trùng lặp
3. Xóa admin chính
4. Network errors trong AJAX
5. Invalid data formats

### Security Testing
1. Direct POST requests
2. XSS attempts
3. SQL injection attempts
4. Unauthorized access attempts

## Future Enhancements

### Potential Features
- **Bulk operations** (thêm/sửa nhiều nhân viên)
- **Import/Export** CSV
- **Advanced search** và filtering
- **Employee profiles** với ảnh
- **Activity logs** per employee
- **Password reset** functionality
- **Two-factor authentication**

### Performance Improvements
- **Pagination** cho danh sách lớn
- **Caching** cho roles/permissions
- **Background jobs** cho bulk operations
- **Real-time updates** với WebSocket

## Troubleshooting

### Common Issues
1. **Cannot add employee**: Check database permissions, role assignments
2. **AJAX not working**: Check network, CORS settings
3. **Form not submitting**: Check JavaScript errors, form validation
4. **Permission denied**: Verify user roles and permissions

### Debug Steps
1. Check PHP error logs
2. Verify database connections
3. Test with simple data first
4. Check browser console for JS errors
5. Verify file permissions

## Maintenance

### Regular Tasks
- **Clean up inactive accounts** periodically
- **Review user roles** and permissions
- **Backup user data** before major changes
- **Update role definitions** as needed

### Monitoring
- **User activity logs**
- **Error rates and patterns**
- **Performance metrics**
- **Security audit logs**