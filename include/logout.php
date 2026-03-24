<?php
require_once '../config/init.php';

// Xóa tất cả session
$_SESSION = [];

// Hủy session
session_destroy();

// Chuyển hướng về trang login
header("Location: login.php");
exit;
