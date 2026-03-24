-- ============================================
-- CƠ SỞ DỮ LIỆU HOÀN CHỈNH - CAFE MANAGEMENT
-- ĐÃ TÍCH HỢP GIẢM GIÁ SẢN PHẨM
-- ============================================

-- Tạo database
CREATE DATABASE IF NOT EXISTS cafe_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cafe_management;

-- ============================================
-- BẢNG CƠ BẢN
-- ============================================

-- BẢNG DANH MỤC
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BẢNG SẢN PHẨM (ĐÃ THÊM CỘT GIẢM GIÁ)
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,   
    category_id INT,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    ingredients TEXT,
    calories INT,
    price DECIMAL(10,2) NOT NULL,
    discount_type ENUM('none', 'percentage', 'fixed') DEFAULT 'none',
    discount_value DECIMAL(10,2) DEFAULT 0,
    sale_price DECIMAL(10,2) NULL,
    discount_start_date DATETIME NULL,
    discount_end_date DATETIME NULL,
    is_featured TINYINT(1) DEFAULT 0,
    
    image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_is_featured (is_featured),
    INDEX idx_discount_dates (discount_start_date, discount_end_date)
);

-- KHO HÀNG
CREATE TABLE inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    min_quantity INT NOT NULL DEFAULT 10,
    unit VARCHAR(50) DEFAULT 'ly',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY (product_id)
);

-- NGƯỜI DÙNG
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    avatar VARCHAR(255) DEFAULT NULL,
    role ENUM('admin', 'staff', 'customer') DEFAULT 'customer',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reset_token VARCHAR(255) NULL,
    reset_token_expiry DATETIME NULL,
    remember_token VARCHAR(255) NULL,
    token_expiry DATETIME NULL,
    INDEX idx_reset_token (reset_token),
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active)
);

-- ============================================
-- BẢNG PHÂN QUYỀN NHÂN VIÊN
-- ============================================

-- BẢNG ĐỊNH NGHĨA ROLE (VAI TRÒ)
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BẢNG ĐỊNH NGHĨA PERMISSIONS (QUYỀN HẠN)
CREATE TABLE permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    module VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BẢNG LIÊN KẾT ROLE VÀ PERMISSIONS
CREATE TABLE role_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, permission_id)
);

-- BẢNG GÁN ROLE CHO NGƯỜI DÙNG
CREATE TABLE user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_role (user_id, role_id)
);

-- BẢNG NHẬT KÝ HOẠT ĐỘNG (AUDIT LOG)
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    description TEXT,
    ip_address VARCHAR(50),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created (created_at)
);

-- ============================================
-- BẢNG MÃ GIẢM GIÁ
-- ============================================

CREATE TABLE coupons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    discount_type ENUM('percentage', 'fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    max_discount DECIMAL(10,2) NULL,
    min_order_value DECIMAL(10,2) DEFAULT 0,
    max_usage INT NULL,
    max_usage_per_user INT DEFAULT 1,
    apply_to ENUM('all', 'category', 'product') DEFAULT 'all',
    apply_to_ids JSON NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    used_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
);

CREATE TABLE coupon_usage (
    id INT PRIMARY KEY AUTO_INCREMENT,
    coupon_id INT NOT NULL,
    order_id INT NOT NULL,
    user_id INT NULL,
    customer_email VARCHAR(100),
    discount_amount DECIMAL(10,2) NOT NULL,
    order_total DECIMAL(10,2) NOT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    INDEX idx_coupon (coupon_id),
    INDEX idx_user (user_id),
    INDEX idx_email (customer_email)
);

CREATE TABLE user_coupons ( 
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    coupon_id INT NOT NULL,
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_used TINYINT(1) DEFAULT 0,
    used_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_coupon (user_id, coupon_id)
);

-- ============================================
-- BẢNG ĐƠN HÀNG
-- ============================================

CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    user_id INT, 
    customer_name VARCHAR(100),
    customer_email VARCHAR(255),
    customer_phone VARCHAR(20),
    shipping_address VARCHAR(255),
    sub_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    coupon_id INT NULL,
    coupon_code VARCHAR(50) NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('BANK_TRANSFER', 'COD') DEFAULT 'COD',
    note TEXT NULL,
    estimated_delivery_at DATETIME NULL,
    status ENUM('pending','confirmed','preparing','shipping','completed','cancelled')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
    INDEX idx_coupon_id (coupon_id),
    INDEX idx_coupon_code (coupon_code)
);

CREATE TABLE order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

CREATE TABLE inventory_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    quantity_change INT NOT NULL,
    type ENUM('in', 'out') NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    product_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(100),
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    admin_reply TEXT NULL,
    replied_at TIMESTAMP NULL,
    status ENUM('approved', 'rejected') DEFAULT 'approved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product_rating (product_id, rating),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
);

CREATE TABLE order_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_order (order_id)
);

CREATE TABLE payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('qr','cod') NOT NULL DEFAULT 'qr',
    config JSON,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method_id INT,
    gateway_txn_id VARCHAR(255),
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'VND',
    status ENUM('pending','success','cancelled','refunded') DEFAULT 'pending',
    request_payload TEXT,
    response_payload TEXT,
    signature VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL
);

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(100) NOT NULL UNIQUE,
    order_id INT NOT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(255),
    total DECIMAL(12,2) NOT NULL,
    metadata JSON,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE shipping_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    fullname VARCHAR(150),
    phone VARCHAR(50),
    address TEXT,
    city VARCHAR(100),
    district VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE payment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT,
    endpoint VARCHAR(255),
    payload TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
);

-- ============================================
-- DỮ LIỆU MẪU
-- ============================================

INSERT INTO categories (name, description) VALUES
('Cà phê nóng', 'Các loại cà phê nóng truyền thống'),
('Cà phê đá', 'Các loại cà phê pha lạnh'),
('Trà sữa', 'Các loại trà sữa và trà trái cây'),
('Sinh tố', 'Các loại sinh tố trái cây'),
('Bánh ngọt', 'Các loại bánh và đồ ăn nhẹ'),
('Mocktail', 'Đồ uống không cồn pha chế kiểu cocktail');

INSERT INTO products (category_id, name, description, price) VALUES
(1, 'Cà phê đen nóng', 'Cà phê đen truyền thống', 20000),
(1, 'Cà phê sữa nóng', 'Cà phê pha phin với sữa đặc', 25000),
(2, 'Cà phê đen đá', 'Cà phê đen pha lạnh', 22000),
(2, 'Bạc xỉu', 'Cà phê sữa nhiều sữa ít cà phê', 28000),
(3, 'Trà sữa truyền thống', 'Trà sữa Đài Loan', 30000),
(3, 'Trà đào cam sả', 'Trà trái cây thanh mát', 35000),
(4, 'Sinh tố bơ', 'Sinh tố bơ thơm béo', 40000),
(5, 'Bánh flan', 'Bánh flan caramen', 15000),
(6, 'Blackberry Mojito', 'Blackberry Mojito là một biến thể của Mojito truyền thống, dùng quả mâm xôi đen (blackberry) để tạo màu tím đậm và vị chua ngọt đặc trưng.', 55000);

INSERT INTO inventory (product_id, quantity, min_quantity) VALUES
(1, 50, 10), (2, 45, 10), (3, 40, 10),
(4, 35, 10), (5, 30, 10), (6, 25, 10),
(7, 20, 10), (8, 15, 10), (9, 50, 10);

-- ============================================
-- CẬP NHẬT CƠ SỞ DỮ LIỆU HIỆN TẠI (NẾU CẦN)
-- ============================================

-- Thêm các cột mới vào bảng users nếu chưa có
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20) AFTER full_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1 AFTER token_expiry;

-- Thêm indexes nếu chưa có
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_username (username);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_role (role);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_is_active (is_active);

-- ============================================
-- DỮ LIỆU MẪU
-- ============================================

-- XÓA DỮ LIỆU CŨ (CHỈ KHI CẦN RESET)
-- DELETE FROM user_roles WHERE user_id > 0;
-- DELETE FROM users WHERE id > 0;

-- INSERT USERS (NGƯỜI DÙNG)
INSERT INTO users (username, password, email, full_name, phone, address, role, is_active) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@cafe.com',
 'Quản trị viên', '0123456789', '123 Đường ABC, Quận 1, TP.HCM', 'admin', 1),
('staff1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff1@cafe.com',
 'Nguyễn Văn A', '0987654321', '456 Đường XYZ, Quận 2, TP.HCM', 'staff', 1),
('staff2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff2@cafe.com',
 'Trần Thị B', '0912345678', '789 Đường DEF, Quận 3, TP.HCM', 'staff', 1),
('customer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer1@gmail.com',
 'Lê Văn C', '0901234567', '321 Đường GHI, Quận 4, TP.HCM', 'customer', 1);

-- ============================================
-- DỮ LIỆU PHÂN QUYỀN
-- ============================================

-- INSERT ROLES (VAI TRÒ)
INSERT INTO roles (name, display_name, description) VALUES
('admin', 'Quản trị viên', 'Quản trị viên hệ thống '),
('staff', 'Nhân viên', 'Nhân viên bán hàng ');

-- INSERT PERMISSIONS (CÁC QUYỀN HẠN)
INSERT INTO permissions (name, display_name, description, module) VALUES
-- DASHBOARD
('view_dashboard', 'Xem dashboard', 'Quyền truy cập bảng điều khiển', 'dashboard'),

-- SẢN PHẨM
('view_products', 'Xem sản phẩm', 'Xem danh sách sản phẩm', 'products'),
('create_product', 'Thêm sản phẩm', 'Thêm sản phẩm mới', 'products'),
('edit_product', 'Sửa sản phẩm', 'Chỉnh sửa thông tin sản phẩm', 'products'),
('delete_product', 'Xóa sản phẩm', 'Xóa sản phẩm', 'products'),
('set_product_discount', 'Thiết lập giảm giá', 'Thiết lập hoặc chỉnh sửa giảm giá sản phẩm', 'products'),
('remove_product_discount', 'Xóa giảm giá', 'Xóa giảm giá sản phẩm', 'products'),

-- DANH MỤC
('view_categories', 'Xem danh mục', 'Xem danh mục sản phẩm', 'categories'),
('create_category', 'Thêm danh mục', 'Thêm danh mục mới', 'categories'),
('edit_category', 'Sửa danh mục', 'Chỉnh sửa danh mục', 'categories'),
('delete_category', 'Xóa danh mục', 'Xóa danh mục', 'categories'),

-- ĐƠN HÀNG
('view_orders', 'Xem đơn hàng', 'Xem danh sách đơn hàng', 'orders'),
('create_order', 'Tạo đơn hàng', 'Tạo đơn hàng mới', 'orders'),
('edit_order', 'Sửa đơn hàng', 'Chỉnh sửa thông tin đơn hàng', 'orders'),
('delete_order', 'Xóa đơn hàng', 'Xóa đơn hàng', 'orders'),
('confirm_order', 'Xác nhận đơn hàng', 'Xác nhận và cập nhật trạng thái đơn hàng', 'orders'),

-- KHO HÀNG
('view_inventory', 'Xem kho hàng', 'Xem thông tin tồn kho', 'inventory'),
('edit_inventory', 'Cập nhật kho hàng', 'Chỉnh sửa số lượng tồn kho', 'inventory'),
('view_inventory_history', 'Xem lịch sử kho', 'Xem lịch sử thay đổi kho hàng', 'inventory'),

-- KHUYẾN MÃI & COUPON
('view_coupons', 'Xem mã giảm giá', 'Xem danh sách mã giảm giá', 'coupons'),
('create_coupon', 'Thêm mã giảm giá', 'Tạo mã giảm giá mới', 'coupons'),
('edit_coupon', 'Sửa mã giảm giá', 'Chỉnh sửa mã giảm giá', 'coupons'),
('delete_coupon', 'Xóa mã giảm giá', 'Xóa mã giảm giá', 'coupons'),

-- ĐÁNH GIÁ & BÌNH LUẬN
('view_reviews', 'Xem đánh giá', 'Xem đánh giá sản phẩm', 'reviews'),
('respond_to_review', 'Phản hồi đánh giá', 'Phản hồi và trả lời đánh giá của khách', 'reviews'),

-- BÁNG CÁO & THỐNG KÊ
('view_reports', 'Xem báo cáo', 'Xem báo cáo doanh thu và thống kê', 'reports'),
('view_financial_reports', 'Xem báo cáo tài chính', 'Xem báo cáo tài chính chi tiết', 'reports'),

-- TÀI KHOẢN VÀ HỆ THỐNG
('view_users', 'Xem người dùng', 'Xem danh sách nhân viên', 'users'),
('create_user', 'Thêm người dùng', 'Tạo tài khoản nhân viên mới', 'users'),
('edit_user', 'Sửa người dùng', 'Chỉnh sửa thông tin nhân viên', 'users'),
('delete_user', 'Vô hiệu hóa người dùng', 'Vô hiệu hóa tài khoản nhân viên', 'users'),
('manage_roles', 'Quản lý vai trò', 'Quản lý vai trò và quyền hạn', 'users'),
('view_logs', 'Xem nhật ký hoạt động', 'Xem lịch sử hoạt động của nhân viên', 'settings');

-- GÁNH ROLE VÀ PERMISSIONS
-- Admin có tất cả các quyền
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'admin';

-- Staff - Cấp quyền từng cái một cụ thể
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.name = 'staff' AND p.name IN (
    'view_dashboard', 
    'view_products', 'create_product', 'edit_product', 'delete_product', 'set_product_discount', 'remove_product_discount',
    'view_categories', 'create_category', 'edit_category', 'delete_category',
    'view_orders', 'create_order', 'edit_order', 'delete_order', 'confirm_order',
    'view_inventory', 'edit_inventory',
    'view_coupons', 'create_coupon', 'edit_coupon', 'delete_coupon',
    'view_reviews', 'respond_to_review'
);

-- GÁN ROLE CHO USERS
INSERT INTO user_roles (user_id, role_id) VALUES
(1, 1), -- Admin user gets admin role
(2, 2), -- Staff1 gets staff role
(3, 2); -- Staff2 gets staff role

INSERT INTO payment_methods (code, name, type, config, active) VALUES
('BANK_TRANSFER', 'Chuyển khoản ngân hàng (QR)', 'qr', NULL, 1),
('COD', 'Thanh toán khi nhận hàng (COD)', 'cod', NULL, 1);

INSERT INTO coupons (code, name, description, discount_type, discount_value, max_discount, 
                     min_order_value, max_usage, max_usage_per_user, apply_to, 
                     start_date, end_date, status) VALUES
('WELCOME10', 'Chào mừng khách hàng mới', 'Giảm 10% cho đơn hàng đầu tiên', 
 'percentage', 10.00, 50000.00, 100000.00, NULL, 1, 'all', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active'),
('SUMMER2024', 'Khuyến mãi mùa hè', 'Giảm 15% tối đa 100k cho đơn từ 200k', 
 'percentage', 15.00, 100000.00, 200000.00, 1000, 3, 'all', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active'),
('FREESHIP', 'Miễn phí vận chuyển', 'Giảm 30k phí ship cho đơn từ 150k', 
 'fixed', 30000.00, NULL, 150000.00, NULL, 5, 'all', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active'),
('SAVE50K', 'Giảm 50k', 'Giảm ngay 50k cho đơn hàng từ 300k', 
 'fixed', 50000.00, NULL, 300000.00, 500, 2, 'all', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active'),
('COFFEE20', 'Giảm giá cà phê', 'Giảm 20% cho tất cả đồ uống cà phê', 
 'percentage', 20.00, 40000.00, 50000.00, NULL, 1, 'category', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active'),
('FLASH30', 'Flash Sale 30%', 'Giảm 30% trong 24h - Nhanh tay!', 
 'percentage', 30.00, 150000.00, 100000.00, 100, 1, 'all', 
 NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active'),
('VIP100K', 'Ưu đãi VIP', 'Giảm 100k cho khách hàng thân thiết', 
 'fixed', 100000.00, NULL, 500000.00, 50, 1, 'all', 
 '2026-01-17 00:00:00', '2026-12-31 23:59:59', 'active');

UPDATE coupons SET apply_to_ids = JSON_ARRAY(1, 2) WHERE code = 'COFFEE20';

-- Thiết lập giảm giá mẫu cho sản phẩm
UPDATE products 
SET discount_type = 'percentage', discount_value = 20.00, sale_price = 20000,
    discount_start_date = NOW(), discount_end_date = DATE_ADD(NOW(), INTERVAL 7 DAY),
    is_featured = 1
WHERE id IN (1, 2);

UPDATE products 
SET discount_type = 'fixed', discount_value = 5000.00, sale_price = 20000,
    discount_start_date = NOW(), discount_end_date = DATE_ADD(NOW(), INTERVAL 30 DAY),
    is_featured = 1
WHERE id = 3;

UPDATE products 
SET discount_type = 'percentage', discount_value = 50.00, sale_price = 14000,
    discount_start_date = NOW(), discount_end_date = DATE_ADD(NOW(), INTERVAL 1 DAY),
    is_featured = 1
WHERE id = 4;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER $$

CREATE PROCEDURE update_sale_prices()
BEGIN
    UPDATE products
    SET sale_price = CASE
        WHEN discount_type = 'percentage' THEN 
            ROUND(price - (price * discount_value / 100), 0)
        WHEN discount_type = 'fixed' THEN 
            GREATEST(price - discount_value, 0)
        ELSE NULL
    END
    WHERE discount_type != 'none';
    
    UPDATE products
    SET discount_type = 'none', discount_value = 0, sale_price = NULL
    WHERE discount_end_date IS NOT NULL 
    AND discount_end_date < NOW()
    AND discount_type != 'none';
END$$

DELIMITER ;

-- ============================================
-- TRIGGERS
-- ============================================

DELIMITER $$

CREATE TRIGGER before_product_update
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    IF NEW.discount_type = 'percentage' THEN
        SET NEW.sale_price = ROUND(NEW.price - (NEW.price * NEW.discount_value / 100), 0);
    ELSEIF NEW.discount_type = 'fixed' THEN
        SET NEW.sale_price = GREATEST(NEW.price - NEW.discount_value, 0);
    ELSE
        SET NEW.sale_price = NULL;
    END IF;
    
    IF NEW.discount_type = 'percentage' AND NEW.discount_value > 100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discount percentage cannot exceed 100%';
    END IF;
    
    IF NEW.discount_type = 'fixed' AND NEW.discount_value > NEW.price THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixed discount cannot exceed product price';
    END IF;
END$$

DELIMITER ;

-- ============================================
-- VIEWS
-- ============================================

CREATE VIEW low_stock_products AS
SELECT 
    p.id, p.name, c.name as category_name,
    i.quantity, i.min_quantity, i.unit
FROM products p
INNER JOIN inventory i ON p.id = i.product_id
LEFT JOIN categories c ON p.category_id = c.id
WHERE i.quantity <= i.min_quantity
ORDER BY i.quantity ASC;

CREATE VIEW revenue_report AS
SELECT 
    DATE(o.completed_at) as sale_date,
    COUNT(o.id) as total_orders,
    SUM(o.total_amount) as total_revenue,
    AVG(o.total_amount) as avg_order_value
FROM orders o
WHERE o.status = 'completed'
GROUP BY DATE(o.completed_at);

CREATE OR REPLACE VIEW product_rating_summary AS
SELECT 
    p.id as product_id, p.name as product_name,
    COUNT(pr.id) as total_reviews,
    ROUND(AVG(pr.rating), 1) as avg_rating,
    SUM(CASE WHEN pr.rating = 5 THEN 1 ELSE 0 END) as five_star,
    SUM(CASE WHEN pr.rating = 4 THEN 1 ELSE 0 END) as four_star,
    SUM(CASE WHEN pr.rating = 3 THEN 1 ELSE 0 END) as three_star,
    SUM(CASE WHEN pr.rating = 2 THEN 1 ELSE 0 END) as two_star,
    SUM(CASE WHEN pr.rating = 1 THEN 1 ELSE 0 END) as one_star
FROM products p
LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
GROUP BY p.id, p.name;

CREATE OR REPLACE VIEW coupon_statistics AS
SELECT 
    c.id, c.code, c.name, c.description, c.discount_type, c.discount_value,
    c.max_discount, c.min_order_value, c.max_usage, c.used_count,
    CASE WHEN c.max_usage IS NULL THEN 999999 ELSE c.max_usage - c.used_count END as remaining_usage,
    c.status, c.start_date, c.end_date,
    COUNT(cu.id) as total_usage_count,
    COALESCE(SUM(cu.discount_amount), 0) as total_discount_given,
    COALESCE(SUM(cu.order_total), 0) as total_order_value,
    CASE 
        WHEN c.status = 'inactive' THEN 'Tạm ngưng'
        WHEN NOW() < c.start_date THEN 'Chưa bắt đầu'
        WHEN NOW() > c.end_date THEN 'Hết hạn'
        WHEN c.max_usage IS NOT NULL AND c.used_count >= c.max_usage THEN 'Hết lượt'
        ELSE 'Đang hoạt động'
    END as display_status
FROM coupons c
LEFT JOIN coupon_usage cu ON c.id = cu.coupon_id
GROUP BY c.id;

CREATE OR REPLACE VIEW coupon_usage_detail AS
SELECT 
    cu.id, cu.used_at, c.code as coupon_code, c.name as coupon_name,
    o.order_number, COALESCE(u.full_name, cu.customer_email) as customer,
    cu.order_total, cu.discount_amount, o.status as order_status
FROM coupon_usage cu
INNER JOIN coupons c ON cu.coupon_id = c.id
INNER JOIN orders o ON cu.order_id = o.id
LEFT JOIN users u ON cu.user_id = u.id
ORDER BY cu.used_at DESC;

CREATE OR REPLACE VIEW products_on_sale AS
SELECT 
    p.*, c.name as category_name,
    CASE 
        WHEN p.discount_type = 'percentage' THEN CONCAT(p.discount_value, '%')
        WHEN p.discount_type = 'fixed' THEN CONCAT(FORMAT(p.discount_value, 0), 'đ')
        ELSE 'Không giảm'
    END as discount_display,
    CASE 
        WHEN p.sale_price IS NOT NULL THEN 
            ROUND((p.price - p.sale_price) / p.price * 100, 0)
        ELSE 0
    END as actual_discount_percentage
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
WHERE p.discount_type != 'none'
AND p.status = 'active'
AND (p.discount_start_date IS NULL OR p.discount_start_date <= NOW())
AND (p.discount_end_date IS NULL OR p.discount_end_date >= NOW())
ORDER BY actual_discount_percentage DESC;

CREATE OR REPLACE VIEW featured_products AS
SELECT 
    p.*, c.name as category_name,
    CASE 
        WHEN p.sale_price IS NOT NULL THEN p.sale_price
        ELSE p.price
    END as display_price,
    CASE 
        WHEN p.sale_price IS NOT NULL THEN 
            ROUND((p.price - p.sale_price) / p.price * 100, 0)
        ELSE 0
    END as discount_percentage
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
WHERE p.is_featured = 1 AND p.status = 'active'
ORDER BY p.created_at DESC;

-- ============================================
-- EVENT SCHEDULER
-- ============================================

SET GLOBAL event_scheduler = ON;

DELIMITER $$

CREATE EVENT IF NOT EXISTS update_product_sales_hourly
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    CALL update_sale_prices();
END$$

DELIMITER ;