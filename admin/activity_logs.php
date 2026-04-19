<?php
require_once '../config/init.php';

// Kiểm tra quyền
requirePermission('view_logs');

// Xử lý AJAX request để xóa nhật ký cũ
if (isset($_GET['action']) && $_GET['action'] === 'clear_old_logs') {
    header('Content-Type: application/json');
    
    if (!hasPermission('manage_roles')) {
        echo json_encode(['success' => false, 'message' => 'Bạn không có quyền']);
        exit;
    }
    
    try {
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $delete_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < ?");
        $stmt->execute([$delete_date]);
        $deleted_count = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "Đã xóa $deleted_count nhật ký cũ hơn $days ngày",
            'deleted_count' => $deleted_count
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Xử lý AJAX request để lấy thống kê
if (isset($_GET['action']) && $_GET['action'] === 'get_stats') {
    header('Content-Type: application/json');
    
    try {
        $today = date('Y-m-d');
        $week_ago = date('Y-m-d', strtotime('-7 days'));
        $month_ago = date('Y-m-d', strtotime('-30 days'));
        
        $total = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $today_logs = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = '$today'")->fetchColumn();
        $week_logs = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at > '$week_ago'")->fetchColumn();
        $month_logs = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at > '$month_ago'")->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'total' => $total,
            'today' => $today_logs,
            'week' => $week_logs,
            'month' => $month_logs
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Lấy danh sách activity logs
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Lọc theo user hoặc action nếu có
$user_filter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$action_filter = isset($_GET['action']) ? sanitizeInput($_GET['action']) : null;

$where_clause = "1=1";
$params = [];

if ($user_filter) {
    $where_clause .= " AND user_id = ?";
    $params[] = $user_filter;
}

if ($action_filter) {
    $where_clause .= " AND action = ?";
    $params[] = $action_filter;
}

// Đếm tổng số logs
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM activity_logs WHERE $where_clause");
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / $limit);

// Lấy danh sách logs
$stmt = $pdo->prepare("
    SELECT al.*, u.full_name, u.username
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $where_clause
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$logs = $stmt->fetchAll();

// Lấy danh sách nhân viên để filter
$employees = $pdo->query("
    SELECT id, full_name, username 
    FROM users 
    WHERE role IN ('admin', 'staff')
    ORDER BY full_name
")->fetchAll();

// Lấy danh sách actions duy nhất
$actions = $pdo->query("
    SELECT DISTINCT action
    FROM activity_logs
    ORDER BY action
")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhật ký hoạt động - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cssad/index.css">
    <style>
        .log-row {
            padding: 12px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s ease;
        }
        
        .log-row:hover {
            background-color: #f8f9fa;
        }
        
        .action-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .action-login {
            background-color: #d4edda;
            color: #155724;
        }
        
        .action-logout {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .action-create {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .action-update {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .action-delete {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .action-default {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-grow-1">
            <!-- Header -->
            <div class="header d-flex justify-content-between align-items-center p-4 bg-light border-bottom">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm mobile-menu-btn me-3" id="mobileMenuBtn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h2 class="m-0">
                            <i class="fas fa-history me-2"></i>Nhật Ký Hoạt Động
                        </h2>
                        <small class="text-muted">Tổng: <span id="totalLogs">0</span> | Hôm nay: <span id="todayLogs">0</span> | Tuần: <span id="weekLogs">0</span> | Tháng: <span id="monthLogs">0</span></small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <?php if (hasPermission('manage_roles')): ?>
                    <button class="btn btn-warning" onclick="showClearDialog()">
                        <i class="fas fa-trash me-2"></i>Xóa Nhật Ký Cũ
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-times me-2"></i>Xóa Bộ Lọc
                    </button>
                </div>
            </div>
            
            <!-- Content -->
            <div class="p-4">
                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Lọc theo nhân viên</label>
                            <select name="user_id" class="form-select">
                                <option value="">-- Tất cả nhân viên --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" 
                                        <?php echo ($user_filter === $emp['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($emp['full_name']); ?> (<?php echo e($emp['username']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Tìm kiếm
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Logs Table -->
                <div class="card">
                    <div class="card-body p-0">
                        <?php if (empty($logs)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Không có nhật ký nào.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th style="width: 30%;">Nhân Viên</th>
                                            <th style="width: 15%;">Hành Động</th>
                                            <th style="width: 15%;">Module</th>
                                            <th style="width: 20%;">Mô Tả</th>
                                            <th style="width: 12%;">IP Address</th>
                                            <th style="width: 15%;">Thời Gian</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $log['full_name'] ? e($log['full_name']) : 'Hệ thống'; ?></strong>
                                                    <?php if ($log['username']): ?>
                                                        <br>
                                                        <small class="text-muted"><?php echo e($log['username']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                        $action_class = 'action-default';
                                                        if (strpos($log['action'], 'login') !== false) $action_class = 'action-login';
                                                        elseif (strpos($log['action'], 'logout') !== false) $action_class = 'action-logout';
                                                        elseif (strpos($log['action'], 'add') !== false || strpos($log['action'], 'create') !== false) $action_class = 'action-create';
                                                        elseif (strpos($log['action'], 'update') !== false) $action_class = 'action-update';
                                                        elseif (strpos($log['action'], 'delete') !== false) $action_class = 'action-delete';
                                                    ?>
                                                    <span class="action-badge <?php echo $action_class; ?>">
                                                       <?php echo translateAction($log['action']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                     <small><?php echo $log['module'] ? translateModule($log['module']) : '-'; ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo $log['description'] ? e($log['description']) : '-'; ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-monospace"><?php echo e($log['ip_address']); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo formatDate($log['created_at'], 'd/m/Y H:i'); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            $current_url = $_SERVER['REQUEST_URI'];
                            $separator = strpos($current_url, '?') !== false ? '&' : '?';
                            
                            // Previous button
                            if ($page > 1) {
                                $prev_url = preg_replace('/[&?]page=\d+/', '', $current_url);
                                $prev_url .= (strpos($prev_url, '?') !== false ? '&' : '?') . 'page=' . ($page - 1);
                                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($prev_url) . '">Trước</a></li>';
                            } else {
                                echo '<li class="page-item disabled"><span class="page-link">Trước</span></li>';
                            }
                            
                            // Page numbers
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                                if ($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $page) {
                                    echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                } else {
                                    $page_url = preg_replace('/[&?]page=\d+/', '', $current_url);
                                    $page_url .= (strpos($page_url, '?') !== false ? '&' : '?') . 'page=' . $i;
                                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($page_url) . '">' . $i . '</a></li>';
                                }
                            }
                            
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '">' . $total_pages . '</a></li>';
                            }
                            
                            // Next button
                            if ($page < $total_pages) {
                                $next_url = preg_replace('/[&?]page=\d+/', '', $current_url);
                                $next_url .= (strpos($next_url, '?') !== false ? '&' : '?') . 'page=' . ($page + 1);
                                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($next_url) . '">Sau</a></li>';
                            } else {
                                echo '<li class="page-item disabled"><span class="page-link">Sau</span></li>';
                            }
                            ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                
                <!-- Summary -->
                <div class="alert alert-info mt-4 mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Đang hiển thị <strong><?php echo count($logs); ?></strong> trên <strong><?php echo $total; ?></strong> nhật ký (Trang <?php echo $page; ?>/<?php echo $total_pages; ?>)
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Load stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
        });

        // Load statistics
        function loadStats() {
            fetch('activity_logs.php?action=get_stats', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('totalLogs').textContent = data.total;
                    document.getElementById('todayLogs').textContent = data.today;
                    document.getElementById('weekLogs').textContent = data.week;
                    document.getElementById('monthLogs').textContent = data.month;
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function clearFilters() {
            window.location.href = 'activity_logs.php';
        }

        function showClearDialog() {
            const days = prompt('Xóa nhật ký cũ hơn bao nhiêu ngày?\n(Mặc định: 30 ngày)', '30');
            if (days !== null && !isNaN(days) && days > 0) {
                clearOldLogs(parseInt(days));
            }
        }

        function clearOldLogs(days) {
            if (!confirm(`Bạn có chắc chắn muốn xóa tất cả nhật ký cũ hơn ${days} ngày? Hành động này không thể hoàn tác!`)) {
                return;
            }

            fetch(`activity_logs.php?action=clear_old_logs&days=${days}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    loadStats();
                    location.reload();
                } else {
                    alert('Lỗi: ' + data.message);
                }
            })
            .catch(error => {
                alert('Đã xảy ra lỗi');
                console.error('Error:', error);
            });
        }
    </script>
</body>
</html>
