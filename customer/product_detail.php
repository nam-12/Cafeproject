<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo '<h2>Thiếu ID sản phẩm</h2>';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.id = ? AND p.status = 'active'"
);
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo '<h2>Không tìm thấy sản phẩm</h2>';
    exit;
}

function getProductImagePath($image) {
    if (empty($image)) {
        return '../assets/images/no-image.png';
    }
    $cleanImage = str_replace(['uploads/', '/uploads/'], '', $image);
    $fullPath = '../admin/uploads/' . $cleanImage;
    return file_exists($fullPath) ? $fullPath : '../assets/images/no-image.png';
}

$imageUrl = getProductImagePath($product['image']);
$displayPrice = number_format($product['price'], 0, ',', '.') . '₫';
if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']) {
    $displayPrice = '<span style="text-decoration:line-through; color:#ccc; margin-right:10px;">' . number_format($product['price'], 0, ',', '.') . '₫</span>' .
                    '<span style="color:#e74c3c; font-weight:bold;">' . number_format($product['sale_price'], 0, ',', '.') . '₫</span>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết sản phẩm - <?= htmlspecialchars($product['name']) ?></title>
    <link rel="stylesheet" href="../lib/Bootstrap/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/home.css">
</head>
<body>
    <?php include '../templates/navbarhome.php'; ?>

    <div class="container mt-5 mb-5">
        <div class="row">
            <div class="col-md-6">
                <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="img-fluid rounded shadow-sm" style="width:100%; max-height:550px; object-fit:cover;" />
            </div>
            <div class="col-md-6">
                <h1><?= htmlspecialchars($product['name']) ?></h1>
                <p class="text-muted">Danh mục: <?= htmlspecialchars($product['category_name'] ?: 'Khác') ?></p>
                <h2><?= $displayPrice ?></h2>
                <p><strong>Trạng thái:</strong> <?= $product['status'] === 'active' ? 'Đang bán' : 'Tạm ngưng' ?></p>
                <p><strong>Calo:</strong> <?= $product['calories'] ?: 'N/A' ?> kcal</p>
                <p><strong>Thành phần:</strong> <?= nl2br(htmlspecialchars($product['ingredients'] ?: 'Chưa cập nhật')) ?></p>
                <p><strong>Mô tả:</strong> <?= nl2br(htmlspecialchars($product['description'] ?: 'Chưa có mô tả')) ?></p>
                <a class="btn btn-primary" href="../home.php">&larr; Quay lại</a>
            </div>
        </div>
    </div>

    <script src="../lib/Bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
