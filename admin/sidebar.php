<link rel="stylesheet" href="../assets/cssad/sidebar.css">

<aside class="sidebar-wrapper col-auto px-0" id="adminSidebar">


    <!-- Sidebar Menu -->
    <div class="sidebar-menu collapse d-lg-block" id="sidebarMenu">
        <!-- Brand Box -->
        <div class="brand-box d-none d-lg-block">
            <i class="fas fa-coffee fa-3x"></i>
            <h5>Cafe Manager</h5>
            <small><?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
        </div>

        <!-- Navigation Menu -->
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <?php if (hasPermission('view_dashboard')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="index.php"
                    data-tooltip="Trang chủ">
                    <i class="fas fa-home"></i>
                    <span>Trang chủ</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Danh mục -->
            <?php if (hasPermission('view_categories')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : '' ?>"
                    href="categories.php" data-tooltip="Danh mục">
                    <i class="fas fa-th-large"></i>
                    <span>Danh mục</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Sản phẩm -->
            <?php if (hasPermission('view_products')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>"
                    href="products.php" data-tooltip="Sản phẩm">
                    <i class="fas fa-mug-hot"></i>
                    <span>Sản phẩm</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Quản lý kho -->
            <?php if (hasPermission('view_inventory')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : '' ?>"
                    href="inventory.php" data-tooltip="Quản lý kho">
                    <i class="fas fa-warehouse"></i>
                    <span>Quản lý kho</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Đơn hàng -->
            <?php if (hasPermission('view_orders')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : '' ?>"
                    href="orders.php" data-tooltip="Đơn hàng">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Đơn hàng</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Mã giảm giá -->
            <?php if (hasPermission('view_coupons')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'coupons.php' ? 'active' : '' ?>"
                    href="coupons.php" data-tooltip="Mã giảm giá">
                    <i class="fas fa-tags"></i>
                    <span>Mã giảm giá</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Báo cáo -->
            <?php if (hasAnyPermission(['view_reports', 'view_financial_reports'])): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : '' ?>"
                    href="reports.php" data-tooltip="Báo cáo">
                    <i class="fas fa-chart-line"></i>
                    <span>Báo cáo</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Quản lý đánh giá -->
            <?php if (hasPermission('view_reviews')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reviews.php' ? 'active' : '' ?>"
                    href="reviews.php" data-tooltip="Quản lý đánh giá">
                    <i class="fas fa-star"></i>
                    <span>Quản lý đánh giá</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- SEPARATOR: HỆ THỐNG -->
            <?php if (hasAnyPermission(['manage_roles', 'view_users', 'view_logs'])): ?>
            <li class="nav-divider"></li>
            <?php endif; ?>
            
            <!-- Quản lý nhân viên -->
            <?php if (hasAnyPermission(['manage_roles', 'view_users', 'create_user', 'edit_user'])): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'manage_employees.php' ? 'active' : '' ?>"
                    href="manage_employees.php" data-tooltip="Quản lý nhân viên">
                    <i class="fas fa-users"></i>
                    <span>Quản lý nhân viên</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Quản lý tài khoản -->
            <?php if (hasAnyPermission(['manage_roles', 'view_users', 'create_user', 'edit_user'])): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'manage_accounts.php' ? 'active' : '' ?>"
                    href="manage_accounts.php" data-tooltip="Quản lý tài khoản">
                    <i class="fas fa-users-cog"></i>
                    <span>Quản lý tài khoản</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Phân Quyền -->
            <?php if (hasPermission('manage_roles')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'roles_permissions.php' ? 'active' : '' ?>"
                    href="roles_permissions.php" data-tooltip="Roles & Permissions">
                    <i class="fas fa-shield-alt"></i>
                    <span>Phân Quyền</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Nhật ký hoạt động -->
            <?php if (hasPermission('view_logs')): ?>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'active' : '' ?>"
                    href="activity_logs.php" data-tooltip="Nhật ký hoạt động">
                    <i class="fas fa-history"></i>
                    <span>Nhật ký hoạt động</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Trang cá nhân (luôn hiển thị) -->
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>"
                    href="profile.php" data-tooltip="Trang cá nhân">
                    <i class="fas fa-user-cog"></i>
                    <span>Trang cá nhân</span>
                </a>
            </li>
        </ul>
        </ul>
    </div>

    <!-- Sidebar Footer with Collapse Button -->
    <div class="sidebar-footer d-none d-lg-block">
        <!-- Logout Button -->
        <div class="nav-item mb-0">
            <a class="nav-link" href="../home.php" data-tooltip="Đăng xuất">
                <i class="fas fa-sign-out-alt"></i>
                <span>Đăng xuất</span>
            </a>
        </div>
        <button class="sidebar-collapse-btn nav-link" type="button" data-sidebar-toggle data-tooltip="Thu gọn">
            <i class="fas fa-angles-left"></i>
            <span>Thu gọn</span>
        </button>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sidebar = document.getElementById('adminSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const toggleButtons = document.querySelectorAll('[data-sidebar-toggle]');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        // Khởi tạo trạng thái sidebar từ localStorage
        function initSidebarState() {
            const savedState = localStorage.getItem('sidebarCollapsed');
            if (savedState === 'true') {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
                updateToggleIcon(true);
            }
        }

        // Lưu trạng thái sidebar
        function saveSidebarState(isCollapsed) {
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }

        // Cập nhật icon nút toggle
        function updateToggleIcon(isCollapsed) {
            toggleButtons.forEach(btn => {
                const icon = btn.querySelector('i');
                const span = btn.querySelector('span');

                if (isCollapsed) {
                    icon.className = 'fas fa-angles-right';
                    if (span) span.textContent = 'Mở rộng';
                    // Cập nhật tooltip
                    btn.setAttribute('data-tooltip', 'Mở rộng');
                } else {
                    icon.className = 'fas fa-angles-left';
                    if (span) span.textContent = 'Thu gọn';
                    // Cập nhật tooltip
                    btn.setAttribute('data-tooltip', 'Thu gọn');
                }
            });
        }

        function openMobileSidebar() {
            sidebar.classList.add('show');
            if (sidebarOverlay) sidebarOverlay.classList.add('show');
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('show');
            if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        }

        // Khởi tạo khi tải trang
        initSidebarState();

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function () {
                if (sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeMobileSidebar);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('show')) {
                closeMobileSidebar();
            }
        });

        // Xử lý sự kiện toggle
        toggleButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const isCollapsed = sidebar.classList.toggle('collapsed');
                document.body.classList.toggle('sidebar-collapsed');
                saveSidebarState(isCollapsed);
                updateToggleIcon(isCollapsed);
            });
        });

        // Thêm hiệu ứng hover cho nav links
        const navLinks = sidebar.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('mouseenter', function () {
                if (!this.classList.contains('active')) {
                    this.style.transition = 'all 0.3s ease';
                }
            });
        });
    })();
</script>