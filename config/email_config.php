<?php
// Cấu hình email
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'junghior925@gmail.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'taug hdzu tykj kkaq'); // App password của Gmail
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'junghior925@gmail.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'Cafe Shop');
if (!defined('SMTP_ENCRYPTION')) define('SMTP_ENCRYPTION', 'tls'); // 'tls' hoặc 'ssl'

// URL gốc website (CHÍNH XÁC)
define('SITE_URL', 'http://localhost/cafe');
?>