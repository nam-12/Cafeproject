<?php
require_once '../config/init.php';

 $success_message = '';
 $error_message = '';
 $token_valid = false;
 $token = '';

// Kiểm tra token
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $token_valid = true;
    } else {
        $error_message = 'Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn!';
    }
}

// Xử lý form reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $token = $_POST['token'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($new_password) || empty($confirm_password)) {
        $error_message = 'Vui lòng điền đầy đủ thông tin!';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'Mật khẩu xác nhận không khớp!';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'Mật khẩu phải có ít nhất 6 ký tự!';
    } else {
        // Kiểm tra token lại
        $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Cập nhật mật khẩu mới
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
            
            if ($update_stmt->execute([$new_hash, $user['id']])) {
                $success_message = 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập ngay bây giờ.';
                $token_valid = false;
            } else {
                $error_message = 'Có lỗi xảy ra, vui lòng thử lại!';
            }
        } else {
            $error_message = 'Token không hợp lệ hoặc đã hết hạn!';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt lại mật khẩu - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/reset_password.css">
</head>

<body>
    <div class="reset-container">
        <div class="reset-image">
            <div class="reset-image-content">
                <i class="fas fa-lock"></i>
                <h3>Đặt lại</h3>
                <h2>Mật khẩu</h2>
                <p>Tạo mật khẩu mới an toàn cho tài khoản của bạn</p>
                
                <div class="reset-image-tips">
                    <div class="tip-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Sử dụng mật khẩu mạnh</span>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-key"></i>
                        <span>Kết hợp chữ và số</span>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-lock"></i>
                        <span>Không chia sẻ với người khác</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="reset-form-container">
            <div class="reset-header">
                <h2>Đặt lại mật khẩu</h2>
                <p>Tạo mật khẩu mới cho tài khoản của bạn</p>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            </div>
            
            <a href="login.php" class="btn btn-submit">
                <i class="fas fa-sign-in-alt me-2"></i>Đăng nhập ngay
            </a>
            <?php elseif ($error_message && !$token_valid): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            </div>
            
            <a href="forgot_password.php" class="btn btn-back">
                <i class="fas fa-arrow-left me-2"></i>Quay lại quên mật khẩu
            </a>
            <?php elseif ($token_valid): ?>
                <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="resetForm">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="new_password" class="form-control" id="new_password" 
                               placeholder="Mật khẩu mới (tối thiểu 6 ký tự)" required>
                        <i class="fas fa-eye password-toggle" id="toggleNewPassword"></i>
                        <div class="password-strength" id="passwordStrength"></div>
                        <div class="form-text">Mật khẩu phải có ít nhất 6 ký tự</div>
                    </div>
                    
                    <div class="form-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="confirm_password" class="form-control" id="confirm_password" 
                               placeholder="Xác nhận mật khẩu mới" required>
                        <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                    </div>
                    
                    <button type="submit" name="reset_password" class="btn btn-submit">
                        <i class="fas fa-check me-2"></i>Đặt lại mật khẩu
                    </button>
                </form>
                
                <div class="divider">
                    <span>Hoặc</span>
                </div>
                
                <a href="login.php" class="btn btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Quay lại đăng nhập
                </a>
            <?php else: ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Token không hợp lệ!
                </div>
                
                <a href="forgot_password.php" class="btn btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Quay lại quên mật khẩu
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
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

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirm_password');
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

        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthIndicator = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthIndicator.className = 'password-strength';
                return;
            }
            
            let strength = 0;
            
            // Check length
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            
            // Check for mixed case, numbers, special characters
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            // Update strength indicator
            strengthIndicator.className = 'password-strength';
            
            if (strength <= 2) {
                strengthIndicator.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthIndicator.classList.add('strength-medium');
            } else {
                strengthIndicator.classList.add('strength-strong');
            }
        });

        // Form validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                
                // Create or update error message
                let errorDiv = document.querySelector('.alert-danger');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Mật khẩu và xác nhận mật khẩu không khớp!';
                    document.getElementById('resetForm').prepend(errorDiv);
                }
                
                // Scroll to top of form
                window.scrollTo({
                    top: document.querySelector('.reset-container').offsetTop - 20,
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