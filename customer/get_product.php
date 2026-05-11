<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/init.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product ID']);
    exit;
}

$productId = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            i.quantity as stock,
            (SELECT COUNT(*) FROM product_reviews pr WHERE pr.product_id = p.id AND pr.status = 'approved') as review_count,
            (SELECT ROUND(AVG(rating), 1) FROM product_reviews pr WHERE pr.product_id = p.id AND pr.status = 'approved') as avg_rating,
            (SELECT SUM(quantity) FROM order_items oi2 JOIN orders o2 ON oi2.order_id = o2.id WHERE oi2.product_id = p.id AND o2.status != 'cancelled') as sold_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE p.id = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    // Fetch approved reviews
    $stmtReviews = $pdo->prepare("
        SELECT 
            pr.*,
            u.full_name as user_full_name,
            u.avatar as profile_image
        FROM product_reviews pr
        LEFT JOIN users u ON pr.user_id = u.id
        WHERE pr.product_id = ? AND pr.status = 'approved'
        ORDER BY pr.created_at DESC
    ");
    $stmtReviews->execute([$productId]);
    $reviews = $stmtReviews->fetchAll(PDO::FETCH_ASSOC);

    $product['reviews'] = $reviews;

    echo json_encode($product);

} catch (Exception $e) {
    error_log("Error fetching product: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>