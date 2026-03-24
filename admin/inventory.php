<?php
require_once '../config/init.php';
require_once '../config/helpers.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../include/login.php');
    exit;
}

// Kiểm tra quyền xem kho hàng
if (!hasPermission('view_inventory')) {
    header('HTTP/1.0 403 Forbidden');
    die('Bạn không có quyền truy cập trang này.');
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 'true') {
    $type = $_GET['type'] ?? 'inventory';
    
    if ($type == 'inventory') {
        echo getInventoryData($pdo);
    } elseif ($type == 'history') {
        echo getHistoryData($pdo);
    }
    exit;
}

function getInventoryData($pdo) {
    $filter = $_GET['filter'] ?? 'all';
    $category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $sql = "SELECT p.*, c.name as category_name, i.quantity, i.min_quantity, i.unit, i.last_updated
            FROM products p 
            INNER JOIN inventory i ON p.id = i.product_id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.status = 'active'";

    $count_sql = "SELECT COUNT(*) as total
                  FROM products p 
                  INNER JOIN inventory i ON p.id = i.product_id
                  LEFT JOIN categories c ON p.category_id = c.id
                  WHERE p.status = 'active'";

    $params = [];
    $count_params = [];

    if ($filter == 'low') {
        $sql .= " AND i.quantity <= i.min_quantity";
        $count_sql .= " AND i.quantity <= i.min_quantity";
    }

    if ($category_filter > 0) {
        $sql .= " AND p.category_id = ?";
        $count_sql .= " AND p.category_id = ?";
        $params[] = $category_filter;
        $count_params[] = $category_filter;
    }

    $sql .= " ORDER BY i.quantity ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inventory = $stmt->fetchAll();

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $limit);

    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Sản phẩm</th>
                    <th>Danh mục</th>
                    <th>Tồn kho</th>
                    <th>Mức tối thiểu</th>
                    <th>Trạng thái</th>
                    <th>Cập nhật</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventory)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <i class="fas fa-box-open fa-2x mb-2 text-muted"></i><br>
                        <span class="text-muted">Không có sản phẩm nào</span>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($inventory as $item): ?>
                <?php 
                $is_low = $item['quantity'] <= $item['min_quantity'];
                $status_class = $is_low ? 'table-danger' : '';
                ?>
                <tr class="<?php echo $status_class; ?>">
                    <td>
                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                    </td>
                    <td>
                       <?php echo htmlspecialchars($item['category_name']); ?>
                    </td>
                    <td>
                        <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>
                    </td>
                    <td><?php echo $item['min_quantity']; ?> <?php echo $item['unit']; ?></td>
                    <td>
                        <?php if ($item['quantity'] == 0): ?>
                        <span class="status-badge danger">
                            <i class="fas fa-times-circle"></i> Hết hàng
                         </span>
                        <?php elseif ($is_low): ?>
                        <span class="status-badge warning">
                            <i class="fas fa-exclamation-triangle"></i> Sắp hết
                        </span>
                        <?php else: ?>
                        <span class="status-badge success">
                            <i class="fas fa-check"></i> Đủ hàng
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small class="text-muted"><?php echo formatDate($item['last_updated']); ?></small>
                    </td>
                    <td>
                        <div class="table-actions">
                            <?php if (hasPermission('edit_inventory')): ?>
                            <button class="btn-action success" data-bs-toggle="modal" data-bs-target="#importModal" 
                                    onclick="setProduct(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', 'in')">
                                <i class="fas"></i>Nhập
                            </button>
                            <button class="btn-action warning" data-bs-toggle="modal" data-bs-target="#importModal" 
                                    onclick="setProduct(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', 'out')">
                                <i class="fas "></i>Xuất
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted">Hiển thị <?php echo ($page - 1) * $limit + 1; ?> - <?php echo min($page * $limit, $total_records); ?> của <?php echo $total_records; ?> sản phẩm</div>
        <nav>
            <ul class="pagination">
                <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="loadInventory(<?php echo $page - 1; ?>); return false;">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <li class="page-item">
                        <a class="page-link <?php echo $i == $page ? 'active' : ''; ?>" 
                           href="#" onclick="loadInventory(<?php echo $i; ?>); return false;">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="loadInventory(<?php echo $page + 1; ?>); return false;">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    
    return json_encode([
        'success' => true,
        'html' => $html,
        'total' => $total_records
    ]);
}

function getHistoryData($pdo) {
    $history_page = isset($_GET['history_page']) ? max(1, (int)$_GET['history_page']) : 1;
    $history_limit = 10;
    $history_offset = ($history_page - 1) * $history_limit;

    $history_sql = "SELECT ih.*, p.name as product_name, c.name as category_name
                    FROM inventory_history ih
                    INNER JOIN products p ON ih.product_id = p.id
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE 1=1";

    $history_count_sql = "SELECT COUNT(*) as total
                          FROM inventory_history ih
                          INNER JOIN products p ON ih.product_id = p.id
                          LEFT JOIN categories c ON p.category_id = c.id
                          WHERE 1=1";

    $history_params = [];
    $history_count_params = [];

    $history_category_filter = isset($_GET['history_category']) ? (int)$_GET['history_category'] : 0;
    if ($history_category_filter > 0) {
        $history_sql .= " AND p.category_id = ?";
        $history_count_sql .= " AND p.category_id = ?";
        $history_params[] = $history_category_filter;
        $history_count_params[] = $history_category_filter;
    }

    $history_type_filter = $_GET['history_type'] ?? '';
    if ($history_type_filter && in_array($history_type_filter, ['in', 'out'])) {
        $history_sql .= " AND ih.type = ?";
        $history_count_sql .= " AND ih.type = ?";
        $history_params[] = $history_type_filter;
        $history_count_params[] = $history_type_filter;
    }

    $history_sql .= " ORDER BY ih.created_at DESC LIMIT ? OFFSET ?";
    $history_params[] = $history_limit;
    $history_params[] = $history_offset;

    $history_stmt = $pdo->prepare($history_sql);
    $history_stmt->execute($history_params);
    $history = $history_stmt->fetchAll();

    $history_count_stmt = $pdo->prepare($history_count_sql);
    $history_count_stmt->execute($history_count_params);
    $history_total_records = $history_count_stmt->fetch()['total'];
    $history_total_pages = ceil($history_total_records / $history_limit);

    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Sản phẩm</th>
                    <th>Danh mục</th>
                    <th>Loại</th>
                    <th>Số lượng</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr>
                    <td colspan="6" class="text-center py-4">
                        <i class="fas fa-history fa-2x mb-2 text-muted"></i><br>
                        <span class="text-muted">Không có lịch sử nào</span>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?php echo formatDate($h['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($h['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($h['category_name']); ?></td>
                    <td>
                        <?php if ($h['type'] == 'in'): ?>
                            <span class="status-badge success">
                                <i class="fas fa-arrow-down"></i> Nhập kho
                            </span>
                        <?php else: ?>
                            <span class="status-badge danger">
                                <i class="fas fa-arrow-up"></i> Xuất kho
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo abs($h['quantity_change']); ?></td>
                    <td><?php echo htmlspecialchars($h['note']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($history_total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted">Hiển thị <?php echo ($history_page - 1) * $history_limit + 1; ?> - <?php echo min($history_page * $history_limit, $history_total_records); ?> của <?php echo $history_total_records; ?> bản ghi</div>
        <nav>
            <ul class="pagination">
                <li class="page-item <?php echo $history_page == 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="loadHistory(<?php echo $history_page - 1; ?>); return false;">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                
                <?php for ($i = 1; $i <= $history_total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $history_total_pages || ($i >= $history_page - 2 && $i <= $history_page + 2)): ?>
                    <li class="page-item">
                        <a class="page-link <?php echo $i == $history_page ? 'active' : ''; ?>" 
                           href="#" onclick="loadHistory(<?php echo $i; ?>); return false;">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php elseif ($i == $history_page - 3 || $i == $history_page + 3): ?>
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $history_page == $history_total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="loadHistory(<?php echo $history_page + 1; ?>); return false;">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    
    return json_encode([
        'success' => true,
        'html' => $html,
        'total' => $history_total_records
    ]);
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // Kiểm tra quyền cập nhật kho hàng
    if (!hasPermission('edit_inventory')) {
        $_SESSION['error'] = 'Bạn không có quyền cập nhật kho hàng!';
        header('Location: inventory.php');
        exit;
    }
    
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $type = $_POST['action'] == 'in' ? 'in' : 'out';
    $note = sanitizeInput($_POST['note']);
    
    try {
        $pdo->beginTransaction();
        
        if ($type == 'in') {
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ?");
        }
        $stmt->execute([$quantity, $product_id]);
        
        $stmt = $pdo->prepare("INSERT INTO inventory_history (product_id, quantity_change, type, note) VALUES (?, ?, ?, ?)");
        $stmt->execute([$product_id, $quantity, $type, $note]);
        
        $pdo->commit();
        $_SESSION['success'] = $type == 'in' ? "Đã nhập kho thành công!" : "Đã xuất kho thành công!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }
    
    header('Location: inventory.php');
    exit;
}

// Lấy danh sách danh mục
 $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Kho</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/inventory.css">
</head>
<body>
    <div class="admin-layout">
      
            <?php include 'sidebar.php'; ?>
        
        
        <div class="content-wrapper" id="content-wrapper">
            <main>
                <div class="page-header">
                    <div class="d-flex align-items-center">
                        <button class="mobile-menu-btn" id="mobile-menu-toggle">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h1><i class="fas fa-warehouse"></i>Quản lý Kho</h1>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="#" onclick="setFilter('all'); return false;" class="filter-btn" id="filter-all">
                            <i class="fas fa-list"></i> Tất cả
                        </a>
                        <a href="#" onclick="setFilter('low'); return false;" class="filter-btn danger" id="filter-low">
                            <i class="fas fa-exclamation-triangle"></i> Sắp hết hàng
                        </a>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div id="alert-message" class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div id="alert-message" class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filter Section for Inventory -->
                <div class="filter-section">
                    <h6><i class="fas fa-filter"></i>Lọc kho</h6>
                    <form id="inventoryFilterForm" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Danh mục</label>
                            <select name="category" id="inventory-category" class="form-select">
                                <option value="0">Tất cả danh mục</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo isset($_GET['category']) && $_GET['category'] == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i> Lọc
                            </button>
                            <button type="button" onclick="resetInventoryFilter()" class="btn btn-secondary">
                                <i class="fas fa-refresh me-2"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Inventory Table -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <i class="fas fa-boxes"></i>Kho
                        </div>
                        <span class="badge-count" id="inventory-total">Tổng: 0 sản phẩm</span>
                    </div>
                    <div class="card-body" id="inventory-content">
                        <!-- Content will be loaded here -->
                    </div>
                </div>

                <!-- Filter Section for History -->
                <div class="filter-section">
                    <h6><i class="fas fa-filter"></i>Lọc lịch sử</h6>
                    <form id="historyFilterForm" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Danh mục</label>
                            <select name="history_category" id="history-category" class="form-select">
                                <option value="0">Tất cả danh mục</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Loại</label>
                            <select name="history_type" id="history-type" class="form-select">
                                <option value="">Tất cả loại</option>
                                <option value="in">Nhập kho</option>
                                <option value="out">Xuất kho</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i> Lọc
                            </button>
                            <button type="button" onclick="resetHistoryFilter()" class="btn btn-secondary">
                                <i class="fas fa-refresh me-2"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- History -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <i class="fas fa-history"></i>Lịch sử nhập xuất
                        </div>
                        <span class="badge-count" id="history-total">Tổng: 0 bản ghi</span>
                    </div>
                    <div class="card-body" id="history-content">
                        <!-- Content will be loaded here -->
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal nhập/xuất kho -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Nhập kho</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="product_id" id="product_id">
                        <input type="hidden" name="action" id="action">
                        
                        <div class="mb-3">
                            <label class="form-label">Sản phẩm</label>
                            <input type="text" class="form-control" id="product_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Số lượng <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ghi chú</label>
                            <textarea name="note" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary">Xác nhận</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables
        let currentFilter = '<?php echo $_GET['filter'] ?? 'all'; ?>';
        let currentInventoryPage = 1;
        let currentHistoryPage = 1;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
            
            // Initial load
            loadInventory(1);
            loadHistory(1);
        });

        // Set product for modal
        function setProduct(id, name, action) {
            document.getElementById('product_id').value = id;
            document.getElementById('product_name').value = name;
            document.getElementById('action').value = action;
            document.getElementById('modalTitle').textContent = action == 'in' ? 'Nhập kho' : 'Xuất kho';
        }

        // Show loading
        function showLoading(containerId) {
            const container = document.getElementById(containerId);
            const loading = document.createElement('div');
            loading.className = 'loading-overlay';
            loading.innerHTML = '<div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>';
            container.appendChild(loading);
        }

        // Hide loading
        function hideLoading(containerId) {
            const container = document.getElementById(containerId);
            const loading = container.querySelector('.loading-overlay');
            if (loading) {
                loading.remove();
            }
        }

        // Set filter
        function setFilter(filter) {
            currentFilter = filter;
            currentInventoryPage = 1;
            
            // Update button states
            document.getElementById('filter-all').className = filter === 'all' ? 'filter-btn active' : 'filter-btn';
            document.getElementById('filter-low').className = filter === 'low' ? 'filter-btn danger active' : 'filter-btn danger';
            
            loadInventory(1);
        }

        // Load inventory data
        function loadInventory(page = 1) {
            currentInventoryPage = page;
            showLoading('inventory-content');
            
            const category = document.getElementById('inventory-category').value;
            const params = new URLSearchParams({
                ajax: 'true',
                type: 'inventory',
                filter: currentFilter,
                category: category,
                page: page
            });

            fetch('inventory.php?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    hideLoading('inventory-content');
                    if (data.success) {
                        document.getElementById('inventory-content').innerHTML = data.html;
                        document.getElementById('inventory-total').textContent = 'Tổng: ' + data.total + ' sản phẩm';
                    }
                })
                .catch(error => {
                    hideLoading('inventory-content');
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra khi tải dữ liệu');
                });
        }

        // Load history data
        function loadHistory(page = 1) {
            currentHistoryPage = page;
            showLoading('history-content');
            
            const category = document.getElementById('history-category').value;
            const type = document.getElementById('history-type').value;
            const params = new URLSearchParams({
                ajax: 'true',
                type: 'history',
                history_category: category,
                history_type: type,
                history_page: page
            });

            fetch('inventory.php?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    hideLoading('history-content');
                    if (data.success) {
                        document.getElementById('history-content').innerHTML = data.html;
                        document.getElementById('history-total').textContent = 'Tổng: ' + data.total + ' bản ghi';
                    }
                })
                .catch(error => {
                    hideLoading('history-content');
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra khi tải dữ liệu');
                });
        }

        // Reset inventory filter
        function resetInventoryFilter() {
            document.getElementById('inventory-category').value = '0';
            currentFilter = 'all';
            document.getElementById('filter-all').className = 'filter-btn active';
            document.getElementById('filter-low').className = 'filter-btn danger';
            loadInventory(1);
        }

        // Reset history filter
        function resetHistoryFilter() {
            document.getElementById('history-category').value = '0';
            document.getElementById('history-type').value = '';
            loadHistory(1);
        }

        // Form submit handlers
        document.getElementById('inventoryFilterForm').addEventListener('submit', function(e) {
            e.preventDefault();
            loadInventory(1);
        });

        document.getElementById('historyFilterForm').addEventListener('submit', function(e) {
            e.preventDefault();
            loadHistory(1);
        });

        // Auto hide alerts
        setTimeout(() => {
            const alertBox = document.getElementById('alert-message');
            if(alertBox){
                alertBox.classList.remove('show');
                alertBox.classList.add('hide');
                setTimeout(() => {
                    alertBox.remove();
                }, 500);
            }
        }, 2000);
    </script>
</body>
</html>