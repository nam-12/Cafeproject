<?php
require_once '../config/init.php';
require_once '../config/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

 $success_message = '';
 $error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_or_username = trim($_POST['email_or_username']);

    if (empty($email_or_username)) {
        $error_message = 'Vui lòng nhập email hoặc tên đăng nhập!';
    } else {
        // Kiểm tra user tồn tại
        $stmt = $pdo->prepare("SELECT id, username, email, full_name FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$email_or_username, $email_or_username]);
        $user = $stmt->fetch();

        if ($user) {
            // Tạo token reset password
            $token = bin2hex(random_bytes(32));
            $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Lưu token vào database
            $update_stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
            $update_stmt->execute([$token, $token_expiry, $user['id']]);

            // Gửi email
            $reset_link = SITE_URL . '/include/reset_password.php?token=' . $token;

            $mail = new PHPMailer(true);

            try {
                // Cấu hình SMTP
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = SMTP_ENCRYPTION;
                $mail->Port = SMTP_PORT;
                $mail->CharSet = 'UTF-8';

                // Người gửi và người nhận
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($user['email'], $user['full_name']);

                // Nội dung email
                $mail->isHTML(true);
                $mail->Subject = 'Đặt lại mật khẩu - Cafe Manager';
                $mail->Body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: linear-gradient(135deg, #6f4e37 0%, #4a3326 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                            .button { display: inline-block; padding: 12px 30px; background: #6f4e37; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>Đặt lại mật khẩu</h1>
                            </div>
                            <div class='content'>
                                <p>Xin chào <strong>{$user['full_name']}</strong>,</p>
                                <p>Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.</p>
                                <p>Nhấn vào nút bên dưới để đặt lại mật khẩu:</p>
                                <p style='text-align: center;'>
                                    <a href='{$reset_link}' class='button'>Đặt lại mật khẩu</a>
                                </p>
                                <p>Hoặc copy link sau vào trình duyệt:</p>
                                <p style='word-break: break-all; background: #fff; padding: 10px; border-left: 3px solid #6f4e37;'>{$reset_link}</p>
                                <p><strong>Lưu ý:</strong> Link này chỉ có hiệu lực trong 1 giờ.</p>
                                <p>Nếu bạn không yêu cầu đặt lại mật khẩu, vui lòng bỏ qua email này.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; 2024 Cafe Manager System. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";

                $mail->send();
                $success_message = 'Link đặt lại mật khẩu đã được gửi đến email của bạn!';
            } catch (Exception $e) {
                $error_message = 'Không thể gửi email. Lỗi: ' . $mail->ErrorInfo;
            }
        } else {
            $error_message = 'Không tìm thấy tài khoản với thông tin này!';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quên mật khẩu - Cafe Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/forgot_password.css">
</head>

<body>
    <div class="forgot-container">
        <div class="forgot-image">
            <div class="forgot-image-content">
                <i class="fas fa-key"></i>
                <h3>Khôi phục</h3>
                <h2>Mật khẩu</h2>
                <p>Nhập thông tin của bạn để nhận link đặt lại mật khẩu</p>
                
                <div class="forgot-image-steps">
                    <div class="step-item">
                        <i class="fas fa-envelope"></i>
                        <span>Nhập email hoặc tên đăng nhập</span>
                    </div>
                    <div class="step-item">
                        <i class="fas fa-paper-plane"></i>
                        <span>Nhận email khôi phục</span>
                    </div>
                    <div class="step-item">
                        <i class="fas fa-undo"></i>
                        <span>Đặt lại mật khẩu mới</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="forgot-form-container">
            <div class="forgot-header">
                <h2>Quên mật khẩu</h2>
                <p>Nhập email hoặc tên đăng nhập để nhận link đặt lại</p>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="forgotForm">
                <div class="form-group">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="text" name="email_or_username" class="form-control" placeholder="Email hoặc Tên đăng nhập" required>
                </div>

                <button type="submit" class="btn btn-submit">
                    <i class="fas fa-paper-plane me-2"></i>Gửi link đặt lại
                </button>
            </form>

            <div class="divider">
                <span>Hoặc</span>
            </div>

            <a href="login.php" class="btn btn-back">
                <i class="fas fa-arrow-left me-2"></i>Quay lại đăng nhập
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            const emailInput = document.querySelector('input[name="email_or_username"]').value.trim();
            
            if (!emailInput) {
                e.preventDefault();
                
                // Create or update error message
                let errorDiv = document.querySelector('.alert-danger');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Vui lòng nhập email hoặc tên đăng nhập!';
                    document.getElementById('forgotForm').prepend(errorDiv);
                }
                
                // Scroll to top of form
                window.scrollTo({
                    top: document.querySelector('.forgot-container').offsetTop - 20,
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