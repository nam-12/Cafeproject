<?php
require_once '../config/init.php';
require_once '../config/helpers.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// tạo đơn hàng
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_name = sanitizeInput($_POST['customer_name']);
    $customer_phone = sanitizeInput($_POST['customer_phone']);
    $payment_method = $_POST['payment_method'];
    $products = json_decode($_POST['products'], true);
    
    if (empty($products)) {
        $_SESSION['error'] = "Vui lòng chọn ít nhất một sản phẩm!";
        header('Location: order_form.php');
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Tính tổng tiền
        $total_amount = 0;
        foreach ($products as $item) {
            $total_amount += $item['price'] * $item['quantity'];
        }
        
        // Tạo đơn hàng
        $order_number = generateOrderNumber();
        $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, total_amount, payment_method, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$order_number, $customer_name, $customer_phone, $total_amount, $payment_method]);
        $order_id = $pdo->lastInsertId();
        
        // Thêm chi tiết đơn hàng
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($products as $item) {
            $subtotal = $item['price'] * $item['quantity'];
            $stmt->execute([
                $order_id, 
                $item['id'], 
                $item['name'], 
                $item['quantity'], 
                $item['price'], 
                $subtotal
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Đã tạo đơn hàng #$order_number thành công!";
        header('Location: orders.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }
}

// Lấy danh sách sản phẩm
 $products = $pdo->query("
    SELECT p.*, c.name as category_name, i.quantity as stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN inventory i ON p.id = i.product_id
    WHERE p.status = 'active'
    ORDER BY c.name, p.name
")->fetchAll();

// Nhóm theo danh mục
 $products_by_category = [];
foreach ($products as $product) {
    $category = $product['category_name'] ?: 'Khác';
    $products_by_category[$category][] = $product;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo đơn hàng mới</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/cssad/order_form.css">
</head>
<body class="admin-layout">
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="container-fluid min-vh-100">
        <div class="row g-0 flex-lg-nowrap flex-wrap">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main content -->
            <main class="content-wrapper">
                <div class="page-header">
                    <h1><i class="fas fa-plus-circle me-2"></i>Tạo đơn hàng mới</h1>
                    <a href="orders.php" class="btn btn-action btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Quay lại
                    </a>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Danh sách sản phẩm -->
                    <div class="col-lg-8">
                        <div class="content-card">
                            <div class="card-header">
                                <i class="fas fa-list"></i>
                                <span>Chọn sản phẩm</span>
                            </div>
                            <div class="card-body" style="max-height: 700px; overflow-y: auto;">
                                <?php foreach ($products_by_category as $category => $items): ?>
                                <div class="category-header">
                                    <i class="fas fa-tag me-2"></i><?php echo $category; ?>
                                </div>
                                <div class="row g-3 mb-4">
                                    <?php foreach ($items as $product): ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="card product-card h-100" onclick="addToCart(<?php echo htmlspecialchars(json_encode($product)); ?>)">
                                            <div class="card-body">
                                                <h6 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h6>
                                                <p class="card-text price">
                                                    <?php echo formatCurrency($product['price']); ?>
                                                </p>
                                                <div class="stock-info">
                                                    <i class="fas fa-box me-1"></i>
                                                    Tồn: <?php echo $product['stock']; ?> ly
                                                </div>
                                                <button type="button" class="btn btn-sm btn-success">
                                                    <i class="fas fa-plus me-1"></i>Thêm
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Giỏ hàng -->
                    <div class="col-lg-4">
                        <div class="content-card sticky-cart">
                            <div class="card-header">
                                <i class="fas fa-shopping-cart"></i>
                                <span>Giỏ hàng (<span id="cart-count">0</span>)</span>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <div id="cart-items">
                                    <div class="empty-cart">
                                        <i class="fas fa-shopping-basket"></i>
                                        <p>Chưa có sản phẩm nào</p>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body cart-summary">
                                <form method="POST" id="orderForm">
                                    <input type="hidden" name="products" id="products-input">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Tên khách hàng</label>
                                        <input type="text" name="customer_name" class="form-control" placeholder="Nhập tên khách hàng">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Số điện thoại</label>
                                        <input type="text" name="customer_phone" class="form-control" placeholder="Nhập số điện thoại">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Phương thức thanh toán</label>
                                        <select name="payment_method" class="form-select" required>
                                            <option value="COD">Tiền mặt</option>
                                            <option value="BANK_TRANSFER">Chuyển khoản</option>
                                        </select>
                                    </div>
                                    
                                    <div class="cart-total">
                                        <h5>Tổng cộng:</h5>
                                        <h4 id="total-amount">0đ</h4>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-action btn-success w-100" id="submit-btn" disabled>
                                        <i class="fas fa-check me-2"></i>Tạo đơn hàng
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.querySelector('.sidebar-wrapper').classList.toggle('show');
        });

        let cart = [];

        function addToCart(product) {
            const existing = cart.find(item => item.id === product.id);
            
            if (existing) {
                if (existing.quantity < product.stock) {
                    existing.quantity++;
                    showNotification('Đã tăng số lượng sản phẩm', 'success');
                } else {
                    showNotification('Không đủ hàng trong kho!', 'danger');
                    return;
                }
            } else {
                cart.push({
                    id: product.id,
                    name: product.name,
                    price: parseFloat(product.price),
                    quantity: 1,
                    stock: parseInt(product.stock)
                });
                showNotification('Đã thêm sản phẩm vào giỏ hàng', 'success');
            }
            
            updateCart();
        }

        function removeFromCart(index) {
            const item = cart[index];
            cart.splice(index, 1);
            showNotification(`Đã xóa ${item.name} khỏi giỏ hàng`, 'info');
            updateCart();
        }

        function updateQuantity(index, delta) {
            const item = cart[index];
            const newQty = item.quantity + delta;
            
            if (newQty < 1) {
                removeFromCart(index);
                return;
            }
            
            if (newQty > item.stock) {
                showNotification('Không đủ hàng trong kho!', 'danger');
                return;
            }
            
            item.quantity = newQty;
            updateCart();
        }

        function updateCart() {
            const cartItems = document.getElementById('cart-items');
            const cartCount = document.getElementById('cart-count');
            const totalAmount = document.getElementById('total-amount');
            const productsInput = document.getElementById('products-input');
            const submitBtn = document.getElementById('submit-btn');
            
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="empty-cart">
                        <i class="fas fa-shopping-basket"></i>
                        <p>Chưa có sản phẩm nào</p>
                    </div>
                `;
                submitBtn.disabled = true;
                totalAmount.textContent = '0đ';
                cartCount.textContent = '0';
                return;
            }
            
            let html = '';
            let total = 0;
            
            cart.forEach((item, index) => {
                const subtotal = item.price * item.quantity;
                total += subtotal;
                
                html += `
                    <div class="cart-item">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="item-name text-truncate" style="max-width: 180px;">${item.name}</div>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${index})">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="quantity-controls">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${index}, -1)">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <span class="quantity-display">${item.quantity}</span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${index}, 1)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                <span class="item-price">${subtotal.toLocaleString('vi-VN')}đ</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            cartItems.innerHTML = html;
            cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
            totalAmount.textContent = total.toLocaleString('vi-VN') + 'đ';
            productsInput.value = JSON.stringify(cart);
            submitBtn.disabled = false;
        }

        function showNotification(message, type) {
            // Create a toast notification instead of using alert
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
                    ${message}
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 500);
            }, 3000);
        }

        // Tự động ẩn alert sau 2 giây
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert.alert-dismissible');
            alerts.forEach(alertBox => {
                alertBox.classList.remove('show');
                alertBox.classList.add('hide');
                setTimeout(() => {
                    alertBox.remove();
                }, 500);
            });
        }, 2000);
    </script>
</body>
</html>