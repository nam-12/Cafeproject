<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// LẤY GIÁ MỚI NHẤT TỪ DATABASE CHO TẤT CẢ SẢN PHẨM TRONG GIỎ
foreach ($_SESSION['cart'] as $productId => $item) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT price, sale_price, discount_type, discount_start_date, discount_end_date FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($p) {
            $now = time();
            $start = $p['discount_start_date'] ? strtotime($p['discount_start_date']) : 0;
            $end = $p['discount_end_date'] ? strtotime($p['discount_end_date']) : 2147483647;
            
            $isPromo = ($p['discount_type'] !== 'none' && $now >= $start && $now <= $end);
            $currentPrice = ($isPromo && !empty($p['sale_price'])) ? (float)$p['sale_price'] : (float)$p['price'];
            
            // Cập nhật lại giá vào Session để tính toán bên dưới
            $_SESSION['cart'][$productId]['price'] = $currentPrice;
        }
    } catch (Exception $e) {
        // Bỏ qua lỗi truy vấn lẻ để không làm treo trang giỏ hàng
    }
}

// POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // UPDATE QUANTITY
    if (isset($_POST['update_cart'])) {
        $stock_warning = [];
        foreach ($_POST['quantity'] as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty < 1) continue;

            if (isset($_SESSION['cart'][$productId])) {
                $item = $_SESSION['cart'][$productId];
                $stock = $item['stock'];
                $name = $item['name'];

                if ($qty > $stock) {
                    $qty = $stock;
                    $stock_warning[] = "Số lượng **{$name}** vượt tồn kho — hệ thống tự điều chỉnh xuống **{$stock}**.";
                }
                updateCartQuantity($productId, $qty);
            }
        }

        if (!empty($stock_warning)) {
            $_SESSION['warning'] = implode('<br>', $stock_warning);
        }

        $_SESSION['success'] = "Đã cập nhật giỏ hàng!";
        header("Location: cart.php");
        exit;
    }

    // REMOVE 1 ITEM
    if (isset($_POST['remove_item'])) {
        removeFromCart($_POST['remove_item']);
        $_SESSION['success'] = "Đã xoá sản phẩm khỏi giỏ hàng!";
        header("Location: cart.php");
        exit;
    }

    // CLEAR ALL
    if (isset($_POST['clear_cart'])) {
        clearCart();
        $_SESSION['success'] = "Đã xoá toàn bộ giỏ hàng!";
        header("Location: cart.php");
        exit;
    }
}

// CART TOTALS (Sử dụng giá đã được cập nhật ở đầu file)
$cart = $_SESSION['cart'];
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

$shipping_fee = calculateShippingFee($subtotal);
$total = $subtotal + $shipping_fee;

$cart_count = array_sum(array_column($cart, 'quantity'));
$navbar_type = 'cart';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giỏ hàng - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/cart.css">
    <style>
        /* Nổi bật sản phẩm được chọn */
        .cart-item.selected {
            border: 2px solid #a2836e;
            box-shadow: 0 0 10px rgba(162, 131, 110, 0.25);
            background-color: #fff8f0;
        }

        /* Checkbox lớn, đậm hơn */
        .form-check-input.selected-checkbox {
            width: 1.3rem;
            height: 1.3rem;
            accent-color: #a2836e;
            border-width: 2px;
        }
    </style>
</head>

<body>

    <?php include '../templates/navbar.php'; ?>

    <div class="container mb-5">

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3">
                <?= $_SESSION['success'];
                unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show mt-3">
                <?= $_SESSION['warning'];
                unset($_SESSION['warning']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="cart-container">
            <div class="cart-header">
                <h2>
                    <i class="fas fa-shopping-cart me-3"></i>
                    Giỏ hàng của bạn
                    <?php if ($cart_count > 0): ?>
                        <span class="badge bg-danger ms-2"><?= $cart_count ?> sản phẩm</span>
                    <?php endif; ?>
                </h2>
            </div>

            <?php if (!empty($cart)): ?>
                <div class="row">
                    <div class="col-lg-8">
                        <form method="POST" id="cartForm">
                            <div class="mb-3 d-flex align-items-center">
                                <input type="checkbox" id="selectAll" class="form-check-input me-2" checked>
                                <label for="selectAll" class="form-check-label mb-0">Chọn tất cả để thanh toán</label>
                            </div>
                            <?php foreach ($cart as $productId => $item): ?>
                                <div class="cart-item">
                                    <div class="row align-items-center">

                                        <div class="col-md-1">
                                            <div class="form-check">
                                                <input class="form-check-input selected-item-checkbox" type="checkbox" name="selected_items[]" value="<?= $productId ?>" checked>
                                            </div>
                                        </div>

                                        <div class="col-md-2">
                                            <?php
                                            $image = '';
                                            if (!empty($item['image']) && is_string($item['image'])) {
                                                $image = str_replace(['uploads/', '/uploads/'], '', $item['image']);
                                                $imagePath = "../admin/uploads/" . htmlspecialchars($image);
                                            } else {
                                                $imagePath = "../assets/images/no-image.png"; 
                                            }
                                            ?>
                                            <img src="<?php echo $imagePath; ?>"
                                                alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy"
                                                class="img-fluid rounded product-image-premium">
                                        </div>


                                        <div class="col-md-4">
                                            <div class="item-name"><?= htmlspecialchars($item['name']); ?></div>
                                            <div class="item-price price-new" data-price="<?= $item['price']; ?>" style="font-weight: bold;">
                                                <?= formatCurrency($item['price']); ?>
                                            </div>
                                            <small class="text-muted">Còn <?= $item['stock']; ?> ly</small>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label small">Số lượng</label>
                                            <input type="number" name="quantity[<?= $productId; ?>]"
                                                class="form-control quantity-input" value="<?= $item['quantity']; ?>" min="1"
                                                max="<?= $item['stock']; ?>" data-price="<?= $item['price']; ?>">
                                        </div>

                                        <div class="col-md-2 fw-bold text-success item-total-display text-end">
                                            <?= formatCurrency($item['price'] * $item['quantity']); ?>
                                        </div>

                                        <div class="col-md-1 text-end">
                                            <button type="submit" name="remove_item" value="<?= $productId; ?>"
                                                class="btn btn-remove" onclick="return confirm('Xóa sản phẩm này?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="d-flex justify-content-between mt-3">
                                <button type="submit" name="update_cart" class="btn btn-primary">
                                    <i class="fas fa-sync me-2"></i>Cập nhật giỏ hàng
                                </button>

                                <button type="submit" name="clear_cart" class="btn btn-outline-danger"
                                    onclick="return confirm('Bạn chắc muốn xóa toàn bộ?')">
                                    <i class="fas fa-trash me-2"></i>Xóa tất cả
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-4">
                        <div class="summary-card">
                            <h4 class="mb-4"><i class="fas fa-file-invoice me-2"></i>Tóm tắt đơn hàng</h4>

                            <div class="summary-row">
                                <span>Tạm tính:</span>
                                <strong id="cart-subtotal"><?= formatCurrency($subtotal); ?></strong>
                            </div>

                            <div class="summary-row">
                                <span>Phí vận chuyển:</span>
                                <strong id="shipping-fee-display"
                                    class="<?= $shipping_fee == 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?= $shipping_fee == 0 ? 'Miễn phí' : formatCurrency($shipping_fee); ?>
                                </strong>
                            </div>

                            <div class="summary-row summary-total">
                                <span>Tổng cộng:</span>
                                <strong id="cart-total-final"><?= formatCurrency($total); ?></strong>
                            </div>

                            <button type="button" id="checkoutSelectedBtn" class="btn btn-checkout">
                                <i class="fas fa-credit-card me-2"></i>Thanh toán
                            </button>
                        </div>
                    </div>

                </div>

            <?php else: ?>
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <h3 class="text-muted">Giỏ hàng trống</h3>
                    <a href="index.php" class="btn btn-primary btn-lg">Bắt đầu mua sắm</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function formatCurrencyJS(amount) {
            return amount.toLocaleString('vi-VN') + "₫";
        }

        document.addEventListener('input', function (e) {
            if (!e.target.classList.contains('quantity-input')) return;

            recalcCartTotals();
        });

        document.getElementById('selectAll').addEventListener('change', function() {
            const checked = this.checked;
            document.querySelectorAll('.selected-item-checkbox').forEach(checkbox => {
                checkbox.checked = checked;
                toggleItemSelectionClass(checkbox);
            });
            recalcCartTotals();
        });

        document.querySelectorAll('.selected-item-checkbox').forEach(checkbox => {
            checkbox.classList.add('selected-checkbox');
            checkbox.addEventListener('change', function() {
                const all = Array.from(document.querySelectorAll('.selected-item-checkbox'));
                document.getElementById('selectAll').checked = all.every(ch => ch.checked);
                toggleItemSelectionClass(this);
                recalcCartTotals();
            });
            toggleItemSelectionClass(checkbox);
        });

        // Cho phép click toàn bộ hàng để chọn/bỏ chọn
        document.querySelectorAll('.cart-item').forEach(item => {
            item.addEventListener('click', function(e) {
                // Không xử lý khi click vào hành động nút xóa, ô input số lượng, checkbox chính/nút
                if (e.target.closest('button') || e.target.closest('input.quantity-input') || e.target.closest('input#selectAll')) {
                    return;
                }

                const checkbox = this.querySelector('.selected-item-checkbox');
                if (!checkbox) return;

                // nếu click đúng checkbox thì đã có native event, còn click phần khác thì toggle
                if (e.target === checkbox) {
                    return;
                }

                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        function toggleItemSelectionClass(checkbox) {
            const item = checkbox.closest('.cart-item');
            if (item) {
                if (checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            }
            if (checkbox.classList) {
                checkbox.classList.toggle('selected-checkbox', checkbox.checked);
            }
        }

        document.getElementById('checkoutSelectedBtn').addEventListener('click', function() {
            const selected = Array.from(document.querySelectorAll('.selected-item-checkbox:checked'))
                .map(ch => ch.value);
            if (!selected.length) {
                alert('Vui lòng chọn ít nhất 1 sản phẩm để thanh toán.');
                return;
            }
            window.location.href = 'checkout.php?selected_items=' + encodeURIComponent(selected.join(','));
        });

        function recalcCartTotals() {
            let subtotal = 0;
            document.querySelectorAll('.cart-item').forEach(item => {
                const checkbox = item.querySelector('.selected-item-checkbox');
                if (!checkbox || !checkbox.checked) return;

                let qtyInput = item.querySelector('.quantity-input');
                let price = parseFloat(qtyInput.dataset.price);
                let qty = parseInt(qtyInput.value);

                if (isNaN(qty) || qty < 1) qty = 0;

                let total = price * qty;
                item.querySelector('.item-total-display').innerHTML = formatCurrencyJS(total);
                subtotal += total;
            });

            document.getElementById('cart-subtotal').innerHTML = formatCurrencyJS(subtotal);

            let freeShipThreshold = <?= FREE_SHIP_THRESHOLD; ?>;
            let shippingFee = <?= SHIPPING_FEE; ?>;
            let shipping = subtotal >= freeShipThreshold ? 0 : shippingFee;
            document.getElementById('shipping-fee-display').innerHTML = shipping === 0 ? 'Miễn phí' : formatCurrencyJS(shipping);
            document.getElementById('cart-total-final').innerHTML = formatCurrencyJS(subtotal + shipping);
        }

        recalcCartTotals();

        // Kiểm tra lần đầu dẫn đến khởi tạo class selection
        document.querySelectorAll('.selected-item-checkbox').forEach(ch => toggleItemSelectionClass(ch));

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