<?php
require_once '../config/init.php';
require_once '../config/helpers.php';

// Redirect nếu đã đăng nhập
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/index.php');
    } else {
        header('Location: ../customer/index.php');
    }
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($username) || empty($full_name) || empty($email) || empty($password)) {
        $error = "Vui lòng điền đầy đủ thông tin!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // Kiểm tra định dạng email
        $error = "Định dạng email không hợp lệ!";
    } elseif ($password !== $confirm_password) {
        $error = "Mật khẩu và xác nhận mật khẩu không khớp!";
    } elseif (strlen($password) < 6) {
        $error = "Mật khẩu phải có ít nhất 6 ký tự!";
    } else {
        // Kiểm tra username đã tồn tại chưa
        $stmt_user = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_user->execute([$username]);
        if ($stmt_user->fetch()) {
            $error = "Tên đăng nhập đã tồn tại, vui lòng chọn tên khác!";
        }
        // Kiểm tra email đã tồn tại chưa
        else {
            $stmt_email = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_email->execute([$email]);
            if ($stmt_email->fetch()) {
                $error = "Email này đã được sử dụng cho tài khoản khác!";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // 2. Insert user (Thêm email vào câu lệnh INSERT)
                $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, password) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$username, $full_name, $email, $hashed_password])) {
                    $success = "Đăng ký thành công! Bạn có thể <a href='login.php'>đăng nhập</a> ngay.";
                } else {
                    $error = "Đã có lỗi xảy ra, vui lòng thử lại!";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/register.css">
</head>

<body>
    <div class="register-container">
        <div class="register-image">
            <div class="register-image-content">
                <i class="fas fa-coffee"></i>
                <h3>Tham gia</h3>
                <h2>Cafe Manager</h2>
                <p>Tạo tài khoản mới để trải nghiệm dịch vụ quản lý quán cà phê chuyên nghiệp</p>

            </div>
        </div>

        <div class="register-form-container">
            <div class="register-header">
                <h2>Đăng ký tài khoản</h2>
                <p>Vui lòng điền thông tin để tạo tài khoản mới</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="registerForm">
                <div class="form-group">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" name="full_name" class="form-control" placeholder="Họ và tên"
                        value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <i class="fas fa-id-badge input-icon"></i>
                    <input type="text" name="username" class="form-control" placeholder="Tên đăng nhập"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="Email"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-control" id="password" placeholder="Mật khẩu"
                        required>
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>

                </div>
                <div class="form-group">
                    <div class="password-strength" id="passwordStrength"></div>
                    <div class="form-text">Mật khẩu phải có ít nhất 6 ký tự</div>
                </div>

                <div class="form-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="confirm_password" class="form-control" id="confirmPassword"
                        placeholder="Xác nhận mật khẩu" required>
                    <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                </div>

                <button type="submit" class="btn btn-register">
                    <i class="fas fa-user-plus me-2"></i>Đăng ký
                </button>
            </form>

            <div class="divider">
                <span>Hoặc</span>
            </div>

            <a href="login.php" class="btn btn-back">
                <i class="fas fa-sign-in-alt me-2"></i>Quay lại đăng nhập
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function () {
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

        document.getElementById('toggleConfirmPassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('confirmPassword');
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
        document.getElementById('password').addEventListener('input', function () {
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
        document.getElementById('registerForm').addEventListener('submit', function (e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (password !== confirmPassword) {
                e.preventDefault();

                // Create or update error message
                let errorDiv = document.querySelector('.alert-danger');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Mật khẩu và xác nhận mật khẩu không khớp!';
                    document.getElementById('registerForm').prepend(errorDiv);
                }

                // Scroll to top of form
                window.scrollTo({
                    top: document.querySelector('.register-container').offsetTop - 20,
                    behavior: 'smooth'
                });
            }
        });

        // Add focus effect to form inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function () {
                this.parentElement.style.transform = 'scale(1.02)';
            });

            input.addEventListener('blur', function () {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>

</html>