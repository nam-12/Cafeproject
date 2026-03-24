<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../include/login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$upload_dir = __DIR__ . '/upload/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Yêu cầu không hợp lệ!";
    } else {
        if (isset($_FILES['avatar_upload'])) {
            header('Content-Type: application/json');
            $file = $_FILES['avatar_upload'];
            if (in_array($file['type'],['image/jpeg','image/png','image/gif','image/webp']) && $file['size']<=5000000) {
                $ext=pathinfo($file['name'],PATHINFO_EXTENSION);
                $name='avatar_'.$user_id.'_'.time().'.'.$ext;
                if (move_uploaded_file($file['tmp_name'],$upload_dir.$name)) {
                    $s=$pdo->prepare("SELECT avatar FROM users WHERE id=?"); $s->execute([$user_id]); $cu=$s->fetch();
                    if (!empty($cu['avatar'])&&!preg_match('/^upload\/default_avatar_/',$cu['avatar'])&&file_exists(__DIR__.'/'.$cu['avatar'])) unlink(__DIR__.'/'.$cu['avatar']);
                    $ap='upload/'.$name; $u=$pdo->prepare("UPDATE users SET avatar=? WHERE id=?");
                    echo $u->execute([$ap,$user_id])?json_encode(['success'=>true,'avatar'=>$ap]):json_encode(['success'=>false,'message'=>'Lỗi database']);
                } else { echo json_encode(['success'=>false,'message'=>'Lỗi upload file']); }
            } else { echo json_encode(['success'=>false,'message'=>'File không hợp lệ hoặc quá lớn (tối đa 5MB)']); }
            exit;
        }
        if (isset($_POST['select_default_avatar'])) {
            header('Content-Type: application/json');
            $ap=$_POST['avatar_path'];
            if (file_exists(__DIR__.'/'.$ap)) {
                $s=$pdo->prepare("SELECT avatar FROM users WHERE id=?"); $s->execute([$user_id]); $cu=$s->fetch();
                if (!empty($cu['avatar'])&&!preg_match('/^upload\/default_avatar_/',$cu['avatar'])&&file_exists(__DIR__.'/'.$cu['avatar'])) unlink(__DIR__.'/'.$cu['avatar']);
                $u=$pdo->prepare("UPDATE users SET avatar=? WHERE id=?");
                echo $u->execute([$ap,$user_id])?json_encode(['success'=>true,'avatar'=>$ap]):json_encode(['success'=>false,'message'=>'Lỗi cập nhật']);
            } else { echo json_encode(['success'=>false,'message'=>'File không tồn tại']); }
            exit;
        }
        if (isset($_POST['update_profile'])) {
            $fn=trim($_POST['full_name']); $un=trim($_POST['username']); $em=trim($_POST['email']);
            if (empty($fn)||empty($un)||empty($em)) { $error_message='Vui lòng điền đầy đủ thông tin!'; }
            elseif (!filter_var($em,FILTER_VALIDATE_EMAIL)) { $error_message='Định dạng email không hợp lệ!'; }
            else {
                $cu=$pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?"); $cu->execute([$un,$user_id]);
                $ce=$pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");    $ce->execute([$em,$user_id]);
                if ($cu->fetch()) { $error_message='Tên đăng nhập đã được sử dụng!'; }
                elseif ($ce->fetch()) { $error_message='Email đã được sử dụng!'; }
                else {
                    $u=$pdo->prepare("UPDATE users SET full_name=?,username=?,email=? WHERE id=?");
                    if ($u->execute([$fn,$un,$em,$user_id])) { $_SESSION['username']=$un; $_SESSION['full_name']=$fn; $success_message='Cập nhật thành công!'; }
                    else { $error_message='Có lỗi xảy ra!'; }
                }
            }
        }
        if (isset($_POST['change_password'])) {
            $cur=$_POST['current_password']; $new=$_POST['new_password']; $con=$_POST['confirm_password'];
            if (empty($cur)||empty($new)||empty($con)) { $error_message='Vui lòng điền đầy đủ!'; }
            elseif ($new!==$con) { $error_message='Mật khẩu mới không khớp!'; }
            elseif (strlen($new)<6) { $error_message='Mật khẩu tối thiểu 6 ký tự!'; }
            else {
                $s=$pdo->prepare("SELECT password FROM users WHERE id=?"); $s->execute([$user_id]); $ud=$s->fetch();
                if (password_verify($cur,$ud['password'])) {
                    $u=$pdo->prepare("UPDATE users SET password=? WHERE id=?");
                    if ($u->execute([password_hash($new,PASSWORD_DEFAULT),$user_id])) { $success_message='Đổi mật khẩu thành công!'; }
                    else { $error_message='Có lỗi xảy ra!'; }
                } else { $error_message='Mật khẩu hiện tại không đúng!'; }
            }
        }
    }
}

$stmt=$pdo->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$user_id]); $user=$stmt->fetch();
$user_roles=getUserRoles($user_id);
$ps=$pdo->prepare("SELECT DISTINCT p.name,p.display_name,p.module,p.description FROM permissions p INNER JOIN role_permissions rp ON p.id=rp.permission_id INNER JOIN user_roles ur ON rp.role_id=ur.role_id WHERE ur.user_id=? ORDER BY p.module,p.display_name");
$ps->execute([$user_id]); $user_permissions=$ps->fetchAll();
$as=$pdo->prepare("SELECT * FROM activity_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 5"); $as->execute([$user_id]); $recent_activities=$as->fetchAll();
$default_avatars=[];
if(is_dir($upload_dir)) foreach(scandir($upload_dir) as $f) if(preg_match('/^default_avatar_\d+\.(jpg|jpeg|png|gif|webp)$/i',$f)) $default_avatars[]='upload/'.$f;
$module_names=['dashboard'=>'📊 Bảng Điều Khiển','products'=>'☕ Sản Phẩm','categories'=>'📁 Danh Mục','orders'=>'🛒 Đơn Hàng','inventory'=>'📦 Kho Hàng','coupons'=>'🏷️ Mã Giảm Giá','reviews'=>'⭐ Đánh Giá','reports'=>'📈 Báo Cáo','users'=>'👥 Người Dùng','settings'=>'⚙️ Cài Đặt'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang cá nhân - Cafe Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/cssad/profile.css">
</head>
<body>

<div class="d-flex">
    <?php include 'sidebar.php'; ?>

    <main class="profile-main">

        <!-- Header -->
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-header-icon"><i class="fas fa-user"></i></div>
                <div>
                    <h1 class="page-title">Trang Cá Nhân</h1>
                    <p class="page-subtitle">Quản lý thông tin tài khoản của bạn</p>
                </div>
            </div>
            <button class="btn-gold-outline" id="manageDefaultsBtn">
                <i class="fas fa-images me-2"></i>Thêm avatar mặc định
            </button>
        </div>

        <!-- Card -->
        <div class="profile-card">

            <!-- Card top: avatar + name -->
            <div class="profile-card-top">
                <div class="profile-avatar-wrap">
                    <?php if (!empty($user['avatar'])&&file_exists(__DIR__.'/'.$user['avatar'])): ?>
                        <img src="<?=htmlspecialchars($user['avatar'])?>?t=<?=time()?>" alt="Avatar" class="profile-avatar-img">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                    <button class="profile-avatar-edit-btn" id="avatarEditBtn" title="Thay đổi avatar">
                        <i class="fas fa-pen"></i>
                    </button>
                </div>
                <h2 class="profile-fullname"><?=htmlspecialchars($user['full_name'])?></h2>
                <div class="profile-role-tag">
                    <i class="fas fa-shield-alt me-1"></i>
                    <?=htmlspecialchars(!empty($user_roles)?$user_roles[0]['display_name']:$user['role'])?>
                </div>
                <?php if (!empty($user_roles)): ?>
                    <div class="profile-badges">
                        <?php foreach($user_roles as $r): ?>
                            <span class="profile-badge"><i class="fas fa-badge me-1"></i><?=htmlspecialchars($r['display_name'])?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card body: tabs -->
            <div class="profile-card-body">
                <div id="alertContainer">
                    <?php if (!empty($success_message)): ?>
                        <div class="p-alert p-alert-success auto-hide"><i class="fas fa-check-circle"></i> <span><?=htmlspecialchars($success_message)?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="p-alert p-alert-danger auto-hide"><i class="fas fa-exclamation-circle"></i> <span><?=htmlspecialchars($error_message)?></span></div>
                    <?php endif; ?>
                </div>

                <!-- Tabs — Bootstrap 5 -->
                <ul class="profile-nav-tabs" id="profileTabs" role="tablist">
                    <li role="presentation"><button class="profile-tab-btn active" data-bs-toggle="tab" data-bs-target="#info"        type="button" role="tab" aria-selected="true"><i class="fas fa-info-circle me-1"></i>Thông tin</button></li>
                    <li role="presentation"><button class="profile-tab-btn"        data-bs-toggle="tab" data-bs-target="#permissions" type="button" role="tab" aria-selected="false"><i class="fas fa-lock me-1"></i>Quyền Hạn</button></li>
                    <li role="presentation"><button class="profile-tab-btn"        data-bs-toggle="tab" data-bs-target="#edit"        type="button" role="tab" aria-selected="false"><i class="fas fa-edit me-1"></i>Chỉnh sửa</button></li>
                    <li role="presentation"><button class="profile-tab-btn"        data-bs-toggle="tab" data-bs-target="#security"    type="button" role="tab" aria-selected="false"><i class="fas fa-shield-alt me-1"></i>Bảo mật</button></li>
                    <li role="presentation"><button class="profile-tab-btn"        data-bs-toggle="tab" data-bs-target="#activity"    type="button" role="tab" aria-selected="false"><i class="fas fa-history me-1"></i>Hoạt Động</button></li>
                </ul>

                <div class="tab-content" id="profileTabContent">

                    <!-- Thông tin -->
                    <div id="info" class="tab-pane fade show active" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6"><div class="info-row"><div class="info-lbl"><i class="fas fa-user me-2"></i>Họ và tên</div><div class="info-val"><?=htmlspecialchars($user['full_name'])?></div></div></div>
                            <div class="col-md-6"><div class="info-row"><div class="info-lbl"><i class="fas fa-at me-2"></i>Tên đăng nhập</div><div class="info-val"><?=htmlspecialchars($user['username'])?></div></div></div>
                            <div class="col-md-6"><div class="info-row"><div class="info-lbl"><i class="fas fa-envelope me-2"></i>Email</div><div class="info-val"><?=htmlspecialchars($user['email'])?></div></div></div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-lbl"><i class="fas fa-shield-alt me-2"></i>Vai trò</div>
                                    <div class="info-val">
                                        <?php if (!empty($user_roles)): ?>
                                            <?php foreach($user_roles as $r): ?>
                                                <div class="role-card"><strong><i class="fas fa-badge me-1" style="color:#D4AF37"></i><?=htmlspecialchars($r['display_name'])?></strong><?php if(!empty($r['description'])): ?><small><?=htmlspecialchars($r['description'])?></small><?php endif; ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?><span class="text-muted">Không có vai trò</span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6"><div class="info-row"><div class="info-lbl"><i class="fas fa-calendar me-2"></i>Ngày tham gia</div><div class="info-val"><?=date('d/m/Y',strtotime($user['created_at']))?></div></div></div>
                        </div>
                    </div>

                    <!-- Quyền Hạn -->
                    <div id="permissions" class="tab-pane fade" role="tabpanel">
                        <div class="section-heading"><i class="fas fa-lock me-2"></i>Quyền Hạn của Bạn</div>
                        <?php if (!empty($user_permissions)):
                            $by_mod=[];
                            foreach($user_permissions as $p) $by_mod[$p['module']??'other'][]=$p;
                        ?>
                            <div class="row">
                                <?php foreach($by_mod as $mod=>$perms): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="perm-group">
                                            <div class="perm-group-title"><?=$module_names[$mod]??'🔧 '.ucfirst($mod)?></div>
                                            <?php foreach($perms as $p): ?>
                                                <div class="perm-item"><i class="fas fa-check-circle"></i><span><strong><?=htmlspecialchars($p['display_name']??$p['name'])?></strong><?php if(!empty($p['description'])): ?><small><?=htmlspecialchars($p['description'])?></small><?php endif; ?></span></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-alert p-alert-info"><i class="fas fa-info-circle me-2"></i>Bạn hiện không có quyền hạn nào.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Chỉnh sửa -->
                    <div id="edit" class="tab-pane fade" role="tabpanel">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                            <div class="row">
                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" value="<?=htmlspecialchars($user['full_name'])?>" required></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Tên đăng nhập <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" value="<?=htmlspecialchars($user['username'])?>" required></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" value="<?=htmlspecialchars($user['email'])?>" required></div>
                            </div>
                            <div class="text-end"><button type="submit" name="update_profile" class="btn-gold"><i class="fas fa-save me-2"></i>Lưu thay đổi</button></div>
                        </form>
                    </div>

                    <!-- Bảo mật -->
                    <div id="security" class="tab-pane fade" role="tabpanel">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                            <div class="row">
                                <div class="col-md-12 mb-3"><label class="form-label fw-semibold">Mật khẩu hiện tại <span class="text-danger">*</span></label><input type="password" name="current_password" class="form-control" required></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Mật khẩu mới <span class="text-danger">*</span></label><input type="password" name="new_password" class="form-control" required></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Xác nhận mật khẩu <span class="text-danger">*</span></label><input type="password" name="confirm_password" class="form-control" required></div>
                            </div>
                            <div class="text-end"><button type="submit" name="change_password" class="btn-gold"><i class="fas fa-shield-alt me-2"></i>Đổi mật khẩu</button></div>
                        </form>
                    </div>

                    <!-- Hoạt Động -->
                    <div id="activity" class="tab-pane fade" role="tabpanel">
                        <div class="section-heading"><i class="fas fa-history me-2"></i>Hoạt Động Gần Đây</div>
                        <?php if (!empty($recent_activities)): ?>
                            <div class="activity-list">
                                <?php foreach($recent_activities as $act):
                                    $icon='fa-circle';
                                    if(strpos($act['action'],'login')!==false)  $icon='fa-sign-in-alt text-success';
                                    elseif(strpos($act['action'],'logout')!==false) $icon='fa-sign-out-alt text-warning';
                                    elseif(strpos($act['action'],'create')!==false||strpos($act['action'],'add')!==false) $icon='fa-plus-circle text-primary';
                                    elseif(strpos($act['action'],'update')!==false) $icon='fa-edit text-info';
                                    elseif(strpos($act['action'],'delete')!==false) $icon='fa-trash-alt text-danger';
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-icon"><i class="fas <?=$icon?>"></i></div>
                                        <div class="activity-body">
                                            <div class="activity-action">
                                                <strong><?=htmlspecialchars(function_exists('translateAction')?translateAction($act['action']):ucfirst($act['action']))?></strong>
                                                <?php if(!empty($act['module'])): ?><span class="activity-tag"><?=htmlspecialchars(function_exists('translateModule')?translateModule($act['module']):$act['module'])?></span><?php endif; ?>
                                            </div>
                                            <?php if(!empty($act['description'])): ?><div class="activity-desc"><?=htmlspecialchars($act['description'])?></div><?php endif; ?>
                                            <div class="activity-meta">
                                                <i class="fas fa-clock me-1"></i><?=formatDate($act['created_at'],'d/m/Y H:i')?>
                                                <?php if(!empty($act['ip_address'])): ?><i class="fas fa-globe ms-2 me-1"></i><span class="activity-ip"><?=htmlspecialchars($act['ip_address'])?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-alert p-alert-info"><i class="fas fa-info-circle me-2"></i>Chưa có hoạt động nào được ghi lại.</div>
                        <?php endif; ?>
                    </div>

                </div><!-- /.tab-content -->
            </div><!-- /.profile-card-body -->
        </div><!-- /.profile-card -->
    </main>
</div>

<!-- Modal: Avatar -->
<div class="modal fade" id="avatarModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-image me-2"></i>Thay đổi ảnh đại diện</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="upload-zone"><label for="avatarUpload" class="upload-btn"><i class="fas fa-upload me-2"></i>Tải ảnh lên</label><input type="file" id="avatarUpload" accept="image/*" hidden><small class="text-muted d-block mt-2">Tối đa 5MB · JPG, PNG, GIF, WEBP</small></div>
            <div class="modal-divider"><span>HOẶC CHỌN AVATAR MẶC ĐỊNH</span></div>
            <?php if(!empty($default_avatars)): ?>
                <div class="avatar-grid" id="avatarPickerGrid"><?php foreach($default_avatars as $av): ?><div class="avatar-thumb" data-path="<?=htmlspecialchars($av)?>"><img src="<?=htmlspecialchars($av)?>" alt="Avatar"></div><?php endforeach; ?></div>
            <?php else: ?>
                <div class="empty-hint"><i class="fas fa-image"></i><strong>Chưa có avatar mặc định</strong><p>Vui lòng thêm avatar trong mục quản lý</p></div>
            <?php endif; ?>
        </div>
    </div></div>
</div>

<!-- Modal: Quản lý Avatar -->
<div class="modal fade" id="manageDefaultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-images me-2"></i>Quản lý Avatar Mặc Định</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="upload-zone"><label for="defaultAvatarUpload" class="upload-btn"><i class="fas fa-plus me-2"></i>Thêm avatar mặc định</label><input type="file" id="defaultAvatarUpload" accept="image/*" hidden><small class="text-muted d-block mt-2">Avatar này sẽ hiển thị cho tất cả người dùng</small></div>
            <div class="modal-divider"><span>DANH SÁCH AVATAR MẶC ĐỊNH</span></div>
            <div id="defaultAvatarsList" class="avatar-grid">
                <?php if(!empty($default_avatars)): ?>
                    <?php foreach($default_avatars as $av): ?>
                        <div class="avatar-thumb avatar-manage"><img src="<?=htmlspecialchars($av)?>" alt="Avatar"><button class="avatar-del-btn" data-path="<?=htmlspecialchars($av)?>"><i class="fas fa-times"></i></button></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-hint"><i class="fas fa-user-circle"></i><strong>Chưa có avatar mặc định</strong><p>Hãy thêm avatar để người dùng lựa chọn.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = '<?=$_SESSION['csrf_token']?>';

function showAlert(type, msg) {
    var el=document.createElement('div');
    el.className='p-alert p-alert-'+type;
    el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> <span>'+msg+'</span>';
    document.getElementById('alertContainer').appendChild(el);
    setTimeout(function(){ el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(function(){ el.remove(); },500); },3000);
}
function postForm(url,fd,onOk,onErr){
    fetch(url,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){ d.success?onOk(d):onErr(d.message||'Có lỗi xảy ra'); }).catch(function(e){ onErr('Lỗi kết nối: '+e.message); });
}

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.auto-hide').forEach(function(el){
        setTimeout(function(){ el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(function(){ el.remove(); },500); },3000);
    });
    document.getElementById('avatarEditBtn').addEventListener('click',function(){ new bootstrap.Modal(document.getElementById('avatarModal')).show(); });
    document.getElementById('manageDefaultsBtn').addEventListener('click',function(){ new bootstrap.Modal(document.getElementById('manageDefaultsModal')).show(); });

    document.getElementById('avatarUpload').addEventListener('change',function(){
        var file=this.files[0]; if(!file) return;
        var btn=document.querySelector('label[for="avatarUpload"]'),orig=btn.innerHTML;
        btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Đang tải...'; btn.style.pointerEvents='none';
        var fd=new FormData(); fd.append('avatar_upload',file); fd.append('csrf_token',csrfToken);
        postForm('profile.php',fd, function(){ showAlert('success','Cập nhật avatar thành công!'); setTimeout(function(){ location.reload(); },1500); }, function(m){ showAlert('danger',m); btn.innerHTML=orig; btn.style.pointerEvents='auto'; });
    });

    var pg=document.getElementById('avatarPickerGrid');
    if(pg) pg.addEventListener('click',function(e){
        var opt=e.target.closest('.avatar-thumb'); if(!opt||!opt.dataset.path) return;
        pg.querySelectorAll('.avatar-thumb').forEach(function(o){ o.style.opacity='0.6'; o.style.pointerEvents='none'; });
        var fd=new FormData(); fd.append('select_default_avatar','1'); fd.append('avatar_path',opt.dataset.path); fd.append('csrf_token',csrfToken);
        postForm('profile.php',fd, function(){ showAlert('success','Cập nhật avatar thành công!'); setTimeout(function(){ location.reload(); },1500); }, function(m){ showAlert('danger',m); pg.querySelectorAll('.avatar-thumb').forEach(function(o){ o.style.opacity='1'; o.style.pointerEvents='auto'; }); });
    });

    document.getElementById('defaultAvatarUpload').addEventListener('change',function(){
        var file=this.files[0]; if(!file) return;
        var btn=document.querySelector('label[for="defaultAvatarUpload"]'),orig=btn.innerHTML;
        btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Đang tải...'; btn.style.pointerEvents='none';
        var fd=new FormData(); fd.append('default_avatar_upload',file); fd.append('csrf_token',csrfToken);
        postForm('manage_default_avatars.php',fd, function(){ showAlert('success','Thêm avatar thành công!'); setTimeout(function(){ location.reload(); },1500); }, function(m){ showAlert('danger',m); btn.innerHTML=orig; btn.style.pointerEvents='auto'; });
    });

    document.getElementById('defaultAvatarsList').addEventListener('click',function(e){
        var btn=e.target.closest('.avatar-del-btn'); if(!btn||!btn.dataset.path) return;
        if(!confirm('Bạn có chắc muốn xóa avatar này?')) return;
        var orig=btn.innerHTML; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>'; btn.disabled=true;
        var fd=new FormData(); fd.append('delete_default_avatar','1'); fd.append('avatar_path',btn.dataset.path); fd.append('csrf_token',csrfToken);
        postForm('manage_default_avatars.php',fd,
            function(){
                showAlert('success','Xóa avatar thành công!');
                var item=btn.closest('.avatar-thumb'); item.style.transition='opacity .3s,transform .3s'; item.style.opacity='0'; item.style.transform='scale(0.8)';
                setTimeout(function(){ item.remove(); if(!document.querySelectorAll('#defaultAvatarsList .avatar-thumb').length) document.getElementById('defaultAvatarsList').innerHTML='<div class="empty-hint"><i class="fas fa-user-circle"></i><strong>Chưa có avatar mặc định</strong><p>Hãy thêm avatar để người dùng lựa chọn.</p></div>'; },300);
            },
            function(m){ showAlert('danger',m); btn.innerHTML=orig; btn.disabled=false; }
        );
    });
});
</script>
</body>
</html>