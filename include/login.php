<?php
require_once '../config/init.php';
require_once '../config/helpers.php';
// Redirect nếu đã đăng nhập
if (isset($_SESSION['user_id'])) {
    // Redirect theo role nếu đã login
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/index.php');
    } else {
        header('Location: ../customer/index.php');
    }
    exit;
}

 $error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if (password_verify($password, $user['password'])) {
            // Kiểm tra nếu tài khoản bị vô hiệu hóa
            if (!$user['is_active']) {
                $error = "Tài khoản của bạn đã bị vô hiệu hóa!";
            } else {
                // Lưu thông tin session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                // Lưu permissions của user vào session
                $_SESSION['permissions'] = getUserPermissions($user['id']);
                $_SESSION['roles'] = getUserRoles($user['id']);
                
                // Ghi nhật ký đăng nhập
                logActivity('login', 'auth', 'Đăng nhập thành công');

                // Nếu người dùng chọn "Ghi nhớ đăng nhập"
                if ($remember) {
                    // Tạo token an toàn
                    $token = bin2hex(random_bytes(16));
                    $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                    
                    // Lưu token vào database
                    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
                    $stmt->execute([$token, $expiry, $user['id']]);
                    
                    // Đặt cookie
                    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", true, true);
                }

                // Redirect theo role
                if ($user['role'] === 'admin' || $user['role'] === 'staff') {
                    header('Location: ../admin/index.php');
                } else {
                    header('Location: ../customer/index.php');
                }
                exit;
            }
        } else {
            $error = "Tên đăng nhập hoặc mật khẩu không đúng!";
            // Ghi nhật ký đăng nhập thất bại
            logActivity('login_failed', 'auth', "Cố gắng đăng nhập với username: $username - Mật khẩu sai");
        }
    } else {
        $error = "Tài khoản không tồn tại!";
        // Ghi nhật ký đăng nhập thất bại
        logActivity('login_failed', 'auth', "Cố gắng đăng nhập với username không tồn tại: $username");
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/login.css">
   
</head>

<body>
    <div class="login-container">
        <div class="login-image">
            <div class="login-image-content">
                <i class="fas fa-coffee"></i>
                <h3>Chào mừng đến với</h3>
                <h2>Cafe Manager</h2>
                <p>Hệ thống quản lý cửa hàng chuyên nghiệp</p>
            </div>
        </div>
        
        <div class="login-form-container">
            <div class="login-header">
                <h2>Đăng nhập</h2>
                <p>Vui lòng nhập thông tin tài khoản của bạn</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="form-group">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" name="username" class="form-control" placeholder="Tên đăng nhập" required autofocus>
                </div>

                <div class="form-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-control" id="password" placeholder="Mật khẩu" required>
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">
                        Ghi nhớ đăng nhập
                    </label>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Đăng nhập
                </button>
            </form>

            <div class="login-footer">
                <a href="register.php">
                    <i class="fas fa-user-plus me-1"></i>Đăng ký
                </a>
                <a href="forgot_password.php">
                    <i class="fas fa-key me-1"></i>Quên mật khẩu?
                </a>
                <a href="../home.php">
                    <i class="fas fa-home me-1"></i>Trang chủ
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this;
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const password = document.querySelector('input[name="password"]').value;
            
            if (!username || !password) {
                e.preventDefault();
                
                // Create or update error message
                let errorDiv = document.querySelector('.alert-danger');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Vui lòng điền đầy đủ thông tin!';
                    document.getElementById('loginForm').prepend(errorDiv);
                }
                
                // Scroll to top of form
                window.scrollTo({
                    top: document.querySelector('.login-container').offsetTop - 20,
                    behavior: 'smooth'
                });
            }
        });

        // Add focus effect to form inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>

</html>