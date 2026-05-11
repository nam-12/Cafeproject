<?php
require_once '../config/init.php';
require_once '../config/helpers.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem đơn hàng
if (!hasPermission('view_orders')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

// Xử lý cập nhật trạng thái đơn hàng 
if (isset($_POST['update_status'])) {
    // Kiểm tra quyền xác nhận/cập nhật đơn hàng
    if (!hasPermission('confirm_order') && !hasPermission('edit_order')) {
        $_SESSION['error'] = 'Bạn không có quyền cập nhật đơn hàng!';
        header('Location: orders.php');
        exit;
    }
    $order_id = (int) $_POST['order_id'];
    $status = $_POST['status'];

    // Lấy trạng thái hiện tại
    $check = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $check->execute([$order_id]);
    $current = $check->fetchColumn();

    // Không cho cập nhật khi đã hoàn thành hoặc bị hủy
    if ($current === 'completed' || $current === 'cancelled') {
        $_SESSION['error'] = "Đơn hàng đã ở trạng thái: $current, không thể cập nhật thêm!";
        header("Location: orders.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lấy order_number để ghi log lịch sử
        $order_stmt = $pdo->prepare("SELECT order_number FROM orders WHERE id = ?");
        $order_stmt->execute([$order_id]);
        $order = $order_stmt->fetch();

        if (!$order) {
            throw new Exception("Không tìm thấy đơn hàng!");
        }

        // Cập nhật trạng thái
        // Lấy estimated_delivery_at hiện tại
        $current_delivery_stmt = $pdo->prepare("SELECT estimated_delivery_at FROM orders WHERE id = ?");
        $current_delivery_stmt->execute([$order_id]);
        $current_delivery = $current_delivery_stmt->fetchColumn();
        
        // Xử lý estimated_delivery_at: chỉ cập nhật lần đầu (nếu hiện tại NULL)
        $estimated_delivery_at = trim($_POST['estimated_delivery_at'] ?? '');
        if ($estimated_delivery_at === '') {
            // Nếu form không nhập, giữ giá trị cũ
            $estimated_delivery_at = $current_delivery;
        } else {
            // Nếu form nhập nhưng đã có giá trị cũ, không cho phép thay đổi
            if ($current_delivery !== null && $current_delivery !== '') {
                $_SESSION['warning'] = "Thời gian dự kiến giao đã được cập nhật trước đó, không thể thay đổi.";
                $estimated_delivery_at = $current_delivery;
            } else {
                // Lần đầu cập nhật, convert format
                $estimated_delivery_at = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $estimated_delivery_at)));
            }
        }
        
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, completed_at = ?, estimated_delivery_at = ? WHERE id = ?");
        $completed_at = ($status == 'completed') ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$status, $completed_at, $estimated_delivery_at, $order_id]);

        // Nếu hủy đơn → cộng lại kho
        if ($status == 'cancelled') {
            // Lấy item của đơn
            $items_stmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $items_stmt->execute([$order_id]);
            $items = $items_stmt->fetchAll();

            foreach ($items as $item) {
                // Cộng lại kho
                $update_inv = $pdo->prepare(
                    "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ?"
                );
                $update_inv->execute([$item['quantity'], $item['product_id']]);

                // Lưu lịch sử nhập kho lại
                $history = $pdo->prepare("
                    INSERT INTO inventory_history (product_id, quantity_change, type, note)
                    VALUES (?, ?, ?, ?)
                ");
                $history->execute([
                    $item['product_id'],
                    $item['quantity'], 
                    'in',
                    'Hủy đơn #' . $order['order_number']
                ]);
            }
        }

        $pdo->commit();
        $_SESSION['success'] = "Đã cập nhật trạng thái đơn hàng!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }

    header('Location: orders.php');
    exit;
}

// Xử lý xóa đơn hàng (Không thay đổi)
if (isset($_GET['delete'])) {
    $order_id = (int) $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ? AND status = 'pending'");
        $stmt->execute([$order_id]);
        $_SESSION['success'] = "Đã xóa đơn hàng!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi: Không thể xóa đơn hàng. " . $e->getMessage();
    }
    header('Location: orders.php');
    exit;
}

 $limit = 10; // Số lượng đơn hàng trên mỗi trang
 $current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
 $offset = ($current_page - 1) * $limit;

// Tham số lọc
 $status_filter = $_GET['status'] ?? '';
 $date_filter = $_GET['date'] ?? '';

// Xây dựng điều kiện WHERE cho bộ lọc (Dùng chung cho cả COUNT và SELECT)
 $where_clauses = " WHERE 1=1";
 $params = [];

if ($status_filter) {
    $where_clauses .= " AND o.status = :status";
    $params[':status'] = $status_filter;
}
if ($date_filter) {
    $where_clauses .= " AND DATE(o.created_at) = :date";
    $params[':date'] = $date_filter;
}

// Đếm tổng số đơn hàng
 $count_sql = "SELECT COUNT(o.id) FROM orders o" . $where_clauses;
 $count_stmt = $pdo->prepare($count_sql);
 $count_stmt->execute($params);
 $total_orders = $count_stmt->fetchColumn();

 $total_pages = ceil($total_orders / $limit);

if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit;
} elseif ($current_page < 1) {
    $current_page = 1;
    $offset = 0;
}

// Lấy danh sách đơn hàng cho trang hiện tại
 $sql = "SELECT o.*, COUNT(oi.id) as items_count 
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id"
    . $where_clauses .
    " GROUP BY o.id 
        ORDER BY o.created_at DESC 
        LIMIT :limit OFFSET :offset";

 $stmt = $pdo->prepare($sql);
// Gắn tham số lọc
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
// Gắn tham số phân trang
 $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
 $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
 $stmt->execute();
 $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê theo trạng thái 
 $stats_stmt = $pdo->query("
    SELECT status, COUNT(*) as count, SUM(total_amount) as total
    FROM orders
    WHERE DATE(created_at) = CURDATE()
    GROUP BY status
");
 $stats = [];
while ($row = $stats_stmt->fetch()) {
    $stats[$row['status']] = $row;
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Đơn hàng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/orders.css">
</head>

<body class="admin-layout">

        <?php include 'sidebar.php'; ?>
   

    <main class="content-wrapper">
        <div class="page-header">
            <h1><i class="fas fa-shopping-cart me-2"></i>Quản lý Đơn hàng</h1>
            <a href="order_form.php" class="btn btn-action btn-primary"><i class="fas fa-plus me-2"></i>Tạo đơn hàng mới</a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div id="alert-message" class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success'];
                unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div id="alert-message" class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error'];
                unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['warning'])): ?>
            <div id="alert-message" class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['warning'];
                unset($_SESSION['warning']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <?php
            $status_labels = [
                'pending' => 'Chờ xử lý',
                'confirmed' => 'Xác nhận',
                'preparing' => 'Chuẩn bị',
                'shipping' => 'Vận chuyển',
                'completed' => 'Hoàn thành',
                'cancelled' => 'Đã hủy'
            ];
            $status_colors = [
                'pending' => 'warning',
                'confirmed' => 'info',
                'preparing' => 'primary',
                'shipping' => 'secondary',
                'completed' => 'success',
                'cancelled' => 'danger'
            ];
            foreach ($status_labels as $status => $label):
                $count = $stats[$status]['count'] ?? 0;
                $total = $stats[$status]['total'] ?? 0;
                ?>
                <div class="col-md-2 mb-2">
                    <div class="content-card border-<?php echo $status_colors[$status]; ?>">
                        <div class="card-body text-center">
                            <h6 class="text-muted"><?php echo $label; ?></h6>
                            <h3 class="text-<?php echo $status_colors[$status]; ?>"><?php echo $count; ?> đơn</h3>
                            <small class="text-muted"><?php echo formatCurrency($total); ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-filter"></i>
                Bộ lọc
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="">Tất cả</option>
                            <?php foreach ($status_labels as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $status_filter == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Ngày</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $date_filter; ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-action btn-primary w-100"><i
                                class="fas fa-search me-2"></i>Lọc</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <i class="fas fa-list"></i>
                Danh sách đơn hàng
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Mã đơn</th>
                            <th>Trạng thái</th>
                            <th>Thời gian</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($orders) > 0): ?>
                            <?php foreach ($orders as $order): 
                                $o_dist = (float)($order['distance'] ?? 0);
                                $o_addr = $order['shipping_address'] ?? '';
                            ?>
                                <tr>
                                    <td><?php echo $order['order_number']; ?></td>
                                    <td><?php echo $status_labels[$order['status']]; ?>
                                    </td>
                                    <td><small><?php echo formatDate($order['created_at']); ?></small></td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-outline-info" data-bs-toggle="modal"
                                                data-bs-target="#detailModal" onclick="viewOrder(<?= $order['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <a href="print_invoice.php?id=<?= $order['id'] ?>" target="_blank" 
                                               class="btn btn-outline-secondary" title="In hóa đơn">
                                                <i class="fas fa-print"></i>
                                            </a>

                                            <?php if ($order['status'] !== 'completed' && $order['status'] !== 'cancelled' && hasPermission('confirm_order')): ?>
                                                <button class="btn btn-outline-success" data-bs-toggle="modal"
                                                    data-bs-target="#statusModal"
                                                    onclick="setOrderStatus(<?= $order['id'] ?>, '<?= $order['order_number'] ?>', '<?= $order['status'] ?>', '<?= !empty($order['estimated_delivery_at']) ? date('Y-m-d\TH:i', strtotime($order['estimated_delivery_at'])) : '' ?>', <?= $o_dist ?>, '<?= addslashes($o_addr) ?>')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($order['status'] === 'pending' && hasPermission('delete_order')): ?>
                                                <a href="?delete=<?= $order['id']; ?>" class="btn btn-outline-danger"
                                                    onclick="return confirm('Bạn có chắc muốn xóa đơn hàng này?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4"><i
                                        class="fas fa-inbox fa-3x mb-3 d-block"></i>Không có đơn hàng nào</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination justify-content-center m-0">
                            <?php
                            // Hàm xây dựng URL với các tham số lọc hiện tại và trang mới
                            function getPaginationUrl($page, $status_filter, $date_filter)
                            {
                                $url = 'orders.php?page=' . $page;
                                if ($status_filter) {
                                    $url .= '&status=' . urlencode($status_filter);
                                }
                                if ($date_filter) {
                                    $url .= '&date=' . urlencode($date_filter);
                                }
                                return $url;
                            }
                            ?>

                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= getPaginationUrl($current_page - 1, $status_filter, $date_filter) ?>"
                                    aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>

                            <?php
                            // Hiển thị tối đa 5 trang
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);

                            if ($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . getPaginationUrl(1, $status_filter, $date_filter) . '">1</a></li>';
                                if ($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }

                            for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                    <a class="page-link <?= $i == $current_page ? 'fw-bold' : '' ?>"
                                        href="<?= getPaginationUrl($i, $status_filter, $date_filter) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                                <?php
                            endfor;

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="' . getPaginationUrl($total_pages, $status_filter, $date_filter) . '">' . $total_pages . '</a></li>';
                            }
                            ?>

                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= getPaginationUrl($current_page + 1, $status_filter, $date_filter) ?>"
                                    aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal fade" id="detailModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Chi tiết đơn hàng</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="orderDetail">
                        <div class="text-center">
                            <div class="spinner-border"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                        <button type="button" class="btn btn-primary" id="btn-print-invoice" onclick="printCurrentOrder()">
                            <i class="fas fa-print me-1"></i>In hóa đơn
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="statusModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Cập nhật trạng thái</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="order_id" id="status_order_id">
                            <input type="hidden" name="update_status" value="1">
                            <div class="mb-3">
                                <label class="form-label">Đơn hàng</label>
                                <input type="text" class="form-control" id="status_order_number" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Trạng thái hiện tại</label>
                                <input type="text" class="form-control" id="status_current_status" readonly style="background: #f8f9fa;">
                            </div>

                            <!-- Thông tin giao hàng -->
                            <div id="delivery-info-box" class="mb-3 p-3 rounded" style="background: #fdf8f4; border: 1px solid #e8ddd4; display:none;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fas fa-motorcycle" style="color:#6f4e37;"></i>
                                    <strong style="color:#3E2723; font-size:0.9rem;">Thông tin giao hàng</strong>
                                </div>
                                <div id="delivery-distance-info" class="small text-muted"></div>
                                <div id="delivery-address-info" class="small text-muted mt-1" style="word-break:break-word;"></div>
                                <div id="delivery-estimate-info" class="mt-2">
                                    <span class="badge bg-info text-white" id="delivery-estimate-badge"></span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Thời gian dự kiến giao hiện tại</label>
                                <input type="text" class="form-control" id="status_current_delivery" readonly style="background: #f8f9fa;" placeholder="Chưa cập nhật">
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Trạng thái mới</label>
                                <select name="status" class="form-select" required>
                                    <?php foreach ($status_labels as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">
                                    Thời gian dự kiến giao 
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="btn-auto-estimate" title="Tự động ước lượng từ khoảng cách" style="display:none;">
                                        <i class="fas fa-magic me-1"></i>Tự động ước lượng
                                    </button>
                                </label>
                                <input type="datetime-local" name="estimated_delivery_at" id="status_estimated_delivery_at" class="form-control" />
                                <small class="text-muted" id="estimate-helper-text"></small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-action btn-secondary"
                                data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-action btn-primary">Cập nhật</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setOrderStatus(id, number, current_status, estimated_delivery, distance, address) {
            document.getElementById('status_order_id').value = id;
            document.getElementById('status_order_number').value = number;
            document.getElementById('status_estimated_delivery_at').value = '';
            
            // Hiển thị trạng thái hiện tại
            const status_labels = <?php echo json_encode($status_labels); ?>;
            document.getElementById('status_current_status').value = status_labels[current_status] || current_status;
            
            // Hiển thị thời gian dự kiến giao hiện tại
            if (estimated_delivery) {
                const dt = new Date(estimated_delivery.replace('T', ' '));
                const formatted = dt.toLocaleDateString('vi-VN') + ' ' + dt.toLocaleTimeString('vi-VN', {hour: '2-digit', minute: '2-digit'});
                document.getElementById('status_current_delivery').value = formatted;
            } else {
                document.getElementById('status_current_delivery').value = 'Chưa cập nhật';
            }

            // Hiển thị thông tin giao hàng + ước lượng thời gian
            const infoBox = document.getElementById('delivery-info-box');
            const distInfo = document.getElementById('delivery-distance-info');
            const addrInfo = document.getElementById('delivery-address-info');
            const estBadge = document.getElementById('delivery-estimate-badge');
            const autoBtn  = document.getElementById('btn-auto-estimate');
            const helperText = document.getElementById('estimate-helper-text');

            if (distance && distance > 0) {
                infoBox.style.display = 'block';
                autoBtn.style.display = 'inline-block';
                
                distInfo.innerHTML = '<i class="fas fa-route me-1"></i> Khoảng cách: <strong>' + distance.toFixed(1) + ' km</strong>';
                if (address) {
                    addrInfo.innerHTML = '<i class="fas fa-map-marker-alt me-1"></i> ' + address;
                } else {
                    addrInfo.innerHTML = '';
                }

                // Ước lượng: 25km/h + 10 phút chuẩn bị
                const travelMin = Math.ceil((distance / 25) * 60);
                const prepMin = 10;
                const totalMin = travelMin + prepMin;
                estBadge.innerHTML = '<i class="fas fa-clock me-1"></i> Ước lượng: ~' + totalMin + ' phút (chuẩn bị ' + prepMin + '\' + di chuyển ' + travelMin + '\' )';
                helperText.textContent = 'Gợi ý: ~' + totalMin + ' phút từ bây giờ (' + distance.toFixed(1) + 'km × 25km/h + 10\' chuẩn bị)';

                // Auto-estimate button
                autoBtn.onclick = function() {
                    const now = new Date();
                    now.setMinutes(now.getMinutes() + totalMin);
                    // Format: YYYY-MM-DDTHH:MM
                    const y = now.getFullYear();
                    const m = String(now.getMonth()+1).padStart(2,'0');
                    const d = String(now.getDate()).padStart(2,'0');
                    const h = String(now.getHours()).padStart(2,'0');
                    const mi = String(now.getMinutes()).padStart(2,'0');
                    document.getElementById('status_estimated_delivery_at').value = y+'-'+m+'-'+d+'T'+h+':'+mi;
                };
            } else {
                infoBox.style.display = 'none';
                autoBtn.style.display = 'none';
                helperText.textContent = '';
            }
        }

        var currentOrderId = null;

        function viewOrder(id) {
            currentOrderId = id;
            var container = document.getElementById('orderDetail');
            container.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:#6f4e37;"></div></div>';
            
            fetch('order_detail.php?id=' + id)
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                    // innerHTML không tự chạy <script> → phải extract và chạy thủ công
                    var scripts = container.querySelectorAll('script');
                    scripts.forEach(function(oldScript) {
                        var newScript = document.createElement('script');
                        if (oldScript.src) {
                            newScript.src = oldScript.src;
                        } else {
                            newScript.textContent = oldScript.textContent;
                        }
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                })
                .catch(() => {
                    container.innerHTML = '<div class="alert alert-danger">Lỗi tải dữ liệu</div>';
                });
        }

        function printCurrentOrder() {
            if (currentOrderId) {
                window.open('print_invoice.php?id=' + currentOrderId, '_blank');
            }
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