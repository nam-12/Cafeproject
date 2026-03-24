<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Lấy giá mới nhất từ DB cho sản phẩm
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
            $currentPrice = ($isPromo && !empty($p['sale_price'])) ? (float) $p['sale_price'] : (float) $p['price'];

            $_SESSION['cart'][$productId]['price'] = $currentPrice;
        }
    } catch (Exception $e) {
        // Bỏ qua lỗi
    }
}

// POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if (isset($_POST['remove_item'])) {
        removeFromCart($_POST['remove_item']);
        $_SESSION['success'] = "Đã xoá sản phẩm khỏi giỏ hàng!";
        header("Location: cart.php");
        exit;
    }

    if (isset($_POST['clear_cart'])) {
        clearCart();
        $_SESSION['success'] = "Đã xoá toàn bộ giỏ hàng!";
        header("Location: cart.php");
        exit;
    }
}

// CART TOTALS
$cart = $_SESSION['cart'];
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

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
</head>

<body>

<?php include '../templates/navbar.php'; ?>

<div class="container mb-5">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['warning'])): ?>
        <div class="alert alert-warning alert-dismissible fade show mt-3">
            <i class="fas fa-exclamation-triangle me-2"></i><?= $_SESSION['warning']; unset($_SESSION['warning']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="cart-container">
        <div class="cart-header">
            <h2>
                <i class="fas fa-mug-hot me-3"></i>
                Giỏ hàng của bạn
                <?php if ($cart_count > 0): ?>
                    <span class="badge" style="background: var(--gold); color: var(--coffee-dark); margin-left: 12px;"><?= $cart_count ?> sản phẩm</span>
                <?php endif; ?>
            </h2>
        </div>

        <?php if (!empty($cart)): ?>
        <div class="row">
            <div class="col-12">
                <form method="POST" id="cartForm">
                    <div class="select-all-row mb-2">
                        <input type="checkbox" id="selectAll" class="form-check-input me-2" checked>
                        <label for="selectAll" class="form-check-label mb-0">Chọn tất cả sản phẩm</label>
                    </div>

                    <?php foreach ($cart as $productId => $item): ?>
                        <div class="cart-item" data-id="<?= $productId ?>">
                            <div class="row align-items-center">
                                <div class="col-md-1 col-2">
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
                                    <img src="<?= $imagePath ?>" alt="<?= htmlspecialchars($item['name']); ?>" class="img-fluid rounded product-image-premium">
                                </div>

                                <div class="col-md-4 col-6">
                                    <div class="item-name"><?= htmlspecialchars($item['name']); ?></div>
                                    <div class="item-price" data-price="<?= $item['price']; ?>"><?= formatCurrency($item['price']); ?></div>
                                    <div class="stock-badge"><i class="fas fa-coffee me-1"></i>Còn <?= $item['stock']; ?> ly</div>
                                </div>

                                <div class="col-md-3 col-6 mt-3 mt-md-0">
                                    <label class="form-label small fw-semibold">Số lượng</label>
                                    <input type="number" name="quantity[<?= $productId; ?>]" class="form-control quantity-input" value="<?= $item['quantity']; ?>" min="1" max="<?= $item['stock']; ?>" data-price="<?= $item['price']; ?>">
                                </div>

                                <div class="col-md-2 col-4 mt-3 mt-md-0 text-end">
                                    <div class="item-total-display"><?= formatCurrency($item['price'] * $item['quantity']); ?></div>
                                </div>

                                <div class="col-md-1 col-2 text-end">
                                    <button type="submit" name="remove_item" value="<?= $productId; ?>" class="btn-remove" onclick="return confirm('Xóa sản phẩm này?')">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between mt-4 flex-wrap gap-3">
                        <button type="submit" name="update_cart" class="btn btn-primary"><i class="fas fa-sync-alt me-2"></i>Cập nhật giỏ hàng</button>
                        <button type="submit" name="clear_cart" class="btn btn-outline-danger" onclick="return confirm('Bạn chắc muốn xóa toàn bộ?')"><i class="fas fa-trash-alt me-2"></i>Xóa tất cả</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="checkout-floating-bar mt-3">
            <div class="total-section">
                <span class="total-label"><i class="fas fa-shopping-bag me-1"></i> Tổng:</span>
                <span class="total-amount" id="cart-total-final"><?= formatCurrency($subtotal); ?></span>
            </div>
            <button type="button" id="checkoutSelectedBtn" class="btn-checkout-large"><i class="fas fa-credit-card"></i> Thanh toán ngay</button>
        </div>

        <?php else: ?>
        <div class="empty-cart text-center mt-5">
            <i class="fas fa-shopping-cart fa-3x"></i>
            <h3 class="text-muted mt-3">Giỏ hàng trống</h3>
            <a href="index.php" class="btn btn-primary btn-lg mt-3">Bắt đầu mua sắm</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function formatCurrencyJS(amount) {
    return amount.toLocaleString('vi-VN') + "₫";
}

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
        const itemTotalEl = item.querySelector('.item-total-display');
        if(itemTotalEl) itemTotalEl.innerHTML = formatCurrencyJS(total);

        subtotal += total;
    });

    const totalFinalEl = document.getElementById('cart-total-final');
    if(totalFinalEl) totalFinalEl.innerHTML = formatCurrencyJS(subtotal);
}

function toggleItemSelectionClass(checkbox) {
    const item = checkbox.closest('.cart-item');
    if(item) {
        if(checkbox.checked) item.classList.add('selected');
        else item.classList.remove('selected');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    if(selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checked = this.checked;
            document.querySelectorAll('.selected-item-checkbox').forEach(cb => {
                cb.checked = checked;
                toggleItemSelectionClass(cb);
            });
            recalcCartTotals();
        });
    }

    document.querySelectorAll('.selected-item-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            const all = Array.from(document.querySelectorAll('.selected-item-checkbox'));
            if(selectAllCheckbox) selectAllCheckbox.checked = all.every(ch => ch.checked);
            toggleItemSelectionClass(this);
            recalcCartTotals();
        });
        toggleItemSelectionClass(cb);
    });

    document.querySelectorAll('.quantity-input').forEach(input => {
        input.addEventListener('input', recalcCartTotals);
        input.addEventListener('change', function(){
            let val = parseInt(this.value), min = parseInt(this.min) || 1, max = parseInt(this.max) || 999;
            if(isNaN(val) || val < min) this.value = min;
            if(val > max) this.value = max;
            recalcCartTotals();
        });
    });

    document.querySelectorAll('.cart-item').forEach(item => {
        item.addEventListener('click', function(e){
            if(e.target.closest('.btn-remove') || e.target.closest('.quantity-input') || e.target.closest('.selected-item-checkbox')) return;
            const cb = this.querySelector('.selected-item-checkbox');
            if(cb){
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change',{bubbles:true}));
            }
        });
    });

    const checkoutBtn = document.getElementById('checkoutSelectedBtn');
    if(checkoutBtn){
        checkoutBtn.addEventListener('click', function(){
            const selected = Array.from(document.querySelectorAll('.selected-item-checkbox:checked')).map(ch=>ch.value);
            if(!selected.length){ alert('Vui lòng chọn ít nhất 1 sản phẩm để thanh toán.'); return; }
            window.location.href = 'checkout.php?selected_items=' + encodeURIComponent(selected.join(','));
        });
    }

    recalcCartTotals();

    // Tự động ẩn alert sau 3 giây
    setTimeout(() => {
        document.querySelectorAll('.alert.alert-dismissible').forEach(alertBox => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alertBox);
            bsAlert.close();
        });
    }, 3000);
});
</script>

</body>
</html>