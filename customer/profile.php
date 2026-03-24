<?php
require_once '../config/init.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Kiểm tra quyền customer
$user_id = $_SESSION['user_id'];
$role_check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$role_check->execute([$user_id]);
$user_role = $role_check->fetchColumn();

if ($user_role !== 'customer') {
    header('Location: ../admin/profile.php');
    exit;
}

$success_message = '';
$error_message = '';

// Xử lý cập nhật thông tin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        
        // Validate
        if (empty($full_name) || empty($username) || empty($email)) {
            $error_message = 'Vui lòng điền đầy đủ thông tin!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Định dạng email không hợp lệ!';
        } else {
            
            // 1. Kiểm tra username đã tồn tại (trừ user hiện tại)
            $check_username_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_username_stmt->execute([$username, $user_id]);
            
            // 2. Kiểm tra email đã tồn tại (trừ user hiện tại)
            $check_email_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_email_stmt->execute([$email, $user_id]);

            if ($check_username_stmt->fetch()) {
                $error_message = 'Tên đăng nhập đã được sử dụng!';
            } elseif ($check_email_stmt->fetch()) { // Xử lý nếu email đã tồn tại
                $error_message = 'Email đã được sử dụng cho tài khoản khác!';
            } else {
                // Cập nhật thông tin (BỔ SUNG email vào câu lệnh UPDATE)
                $update_stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?");
                if ($update_stmt->execute([$full_name, $username, $email, $user_id])) {
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name;
                    $success_message = 'Cập nhật thông tin thành công!';
                } else {
                    $error_message = 'Có lỗi xảy ra, vui lòng thử lại!';
                }
            }
        }

    }
    
    // Xử lý đổi mật khẩu
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = 'Vui lòng điền đầy đủ thông tin mật khẩu!';
        } elseif ($new_password !== $confirm_password) {
            $error_message = 'Mật khẩu mới không khớp!';
        } elseif (strlen($new_password) < 6) {
            $error_message = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
        } else {
            // Kiểm tra mật khẩu hiện tại
            $pass_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $pass_stmt->execute([$user_id]);
            $user_data = $pass_stmt->fetch();
            
            if (password_verify($current_password, $user_data['password'])) {
                // Cập nhật mật khẩu mới
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_pass = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                
                if ($update_pass->execute([$new_hash, $user_id])) {
                    $success_message = 'Đổi mật khẩu thành công!';
                } else {
                    $error_message = 'Có lỗi xảy ra, vui lòng thử lại!';
                }
            } else {
                $error_message = 'Mật khẩu hiện tại không đúng!';
            }
        }
    }

    if (isset($_POST['update_avatar'])) {

    // 1. Upload avatar mới (ƯU TIÊN)
    if (!empty($_FILES['avatar_upload']['name'])) {
        $file = $_FILES['avatar_upload'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $error_message = 'Chỉ chấp nhận JPG, PNG, WEBP!';
        } else {
            $fileName = 'customer/avatar_' . $user_id . '_' . time() . '.' . $ext;
            $uploadPath = '../admin/upload/' . $fileName;

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->execute([$fileName, $user_id]);
                $success_message = 'Cập nhật avatar thành công!';
            }
        }
    }

    // 2. Nếu KHÔNG upload → dùng avatar mặc định
    elseif (!empty($_POST['selected_avatar'])) {
        $avatarPath = $_POST['selected_avatar'];

        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->execute([$avatarPath, $user_id]);
        $success_message = 'Cập nhật avatar thành công!';
    }
}
}

// Lấy thông tin user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông tin tài khoản - Coffee House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/profile.css">
</head>
<body>
    <?php include '../templates/navbar.php'; ?>

    <section class="profile-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php if (!empty($user['avatar'])): ?>
                                    <img src="../admin/upload/<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>

                                <button class="edit-avatar-btn" data-bs-toggle="modal" data-bs-target="#avatarModal">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                            <span class="profile-role">
                                <i class="fas fa-crown me-2"></i>
                                Khách hàng
                            </span>
                        </div>

                        <div class="profile-body">
                            <?php if ($success_message): ?>
                                <div class="alert alert-success-premium" id="alert-message">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <?php echo $success_message; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($error_message): ?>
                                <div class="alert alert-danger-premium" id="alert-message">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <?php echo $error_message; ?>
                                </div>
                            <?php endif; ?>

                            <ul class="nav nav-tabs nav-tabs-premium" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#info">
                                        <i class="fas fa-info-circle me-2"></i>Thông tin
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#edit">
                                        <i class="fas fa-edit me-2"></i>Chỉnh sửa
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#security">
                                        <i class="fas fa-lock me-2"></i>Bảo mật
                                    </a>
                                </li>
                            </ul>

                            <div class="tab-content">
                                <div id="info" class="tab-pane fade show active">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-user me-2"></i>Họ và tên
                                                </div>
                                                <div class="info-value">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-at me-2"></i>Tên đăng nhập
                                                </div>
                                                <div class="info-value">
                                                    <?php echo htmlspecialchars($user['username']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-envelope me-2"></i>Email
                                                </div>
                                                <div class="info-value">
                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-shield-alt me-2"></i>Vai trò
                                                </div>
                                                <div class="info-value">
                                                    Khách hàng
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-calendar me-2"></i>Ngày tạo
                                                </div>
                                                <div class="info-value">
                                                    <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="edit" class="tab-pane fade">
                                    <form method="POST" action="">
                                        <div class="row">
                                            <div class="col-md-6 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-user me-2"></i>Họ và tên
                                                </label>
                                                <input type="text" name="full_name" class="form-control form-control-premium"
                                                    value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-at me-2"></i>Tên đăng nhập
                                                </label>
                                                <input type="text" name="username" class="form-control form-control-premium"
                                                    value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-envelope me-2"></i>Email
                                                </label>
                                                <input type="email" name="email" class="form-control form-control-premium"
                                                    value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            </div>
                                            </div>
                                        <div class="text-end">
                                            <button type="submit" name="update_profile" class="btn btn-premium">
                                                <i class="fas fa-save me-2"></i>Lưu thay đổi
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div id="security" class="tab-pane fade">
                                    <form method="POST" action="">
                                        <div class="row">
                                            <div class="col-md-12 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-lock me-2"></i>Mật khẩu hiện tại
                                                </label>
                                                <input type="password" name="current_password" class="form-control form-control-premium"
                                                    placeholder="Nhập mật khẩu hiện tại" required>
                                            </div>
                                            <div class="col-md-6 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-key me-2"></i>Mật khẩu mới
                                                </label>
                                                <input type="password" name="new_password" class="form-control form-control-premium"
                                                    placeholder="Nhập mật khẩu mới (tối thiểu 6 ký tự)" required>
                                            </div>
                                            <div class="col-md-6 mb-4">
                                                <label class="form-label-premium">
                                                    <i class="fas fa-check-double me-2"></i>Xác nhận mật khẩu
                                                </label>
                                                <input type="password" name="confirm_password" class="form-control form-control-premium"
                                                    placeholder="Nhập lại mật khẩu mới" required>
                                            </div>
                                        </div>
                                        
                                        <div class="text-end">
                                            <button type="submit" name="change_password" class="btn btn-premium">
                                                <i class="fas fa-shield-alt me-2"></i>Đổi mật khẩu
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Tự động ẩn alert sau 3 giây
    setTimeout(() => {
        const alertBox = document.getElementById('alert-message');
        if(alertBox){
            alertBox.style.transition = 'opacity 0.5s';
            alertBox.style.opacity = '0';
            setTimeout(() => {
                alertBox.remove();
            }, 500);
        }
    }, 3000);
    </script>

    <div class="modal fade" id="avatarModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">Cập nhật hình đại diện</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <h6 class="mb-3">Avatar mặc định</h6>
            <div class="avatar-grid">
                <?php
                $defaultAvatars = [];
                $uploadDir = '../admin/upload/';

                if (is_dir($uploadDir)) {
                    $files = scandir($uploadDir);
                    foreach ($files as $file) {
                        if (preg_match('/^default_avatar_\d+\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
                            $defaultAvatars[] = $file;
                        }
                    }
                }

                if (empty($defaultAvatars)):
                ?>
                    <p class="text-muted">Chưa có avatar mặc định</p>
                <?php
                else:
                    foreach ($defaultAvatars as $file):
                ?>
                    <label class="avatar-item">
                        <input type="radio" name="selected_avatar" value="<?php echo $file; ?>">
                        <img src="../admin/upload/<?php echo htmlspecialchars($file); ?>" alt="Avatar mặc định">
                    </label>
                <?php
                    endforeach;
                endif;
                ?>
            </div>

            <hr>

            <h6 class="mb-2">Hoặc tải ảnh lên</h6>
            <input type="file" name="avatar_upload" class="form-control"
                accept="image/png,image/jpeg,image/jpg,image/webp">
        </div>
        <div class="modal-footer">
          <button type="submit" name="update_avatar" class="btn btn-premium">
            Lưu avatar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>