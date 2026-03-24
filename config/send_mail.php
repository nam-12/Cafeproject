<?php
require dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendOrderEmail($toEmail, $toName, $orderNumber, $totalAmount, $paymentMethod, $address) {
    $mail = new PHPMailer(true);

    try {
        // SMTP Server
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = SMTP_PORT;

        // From
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Nội dung email
        $mail->isHTML(true);
        $mail->Subject = "Order confirmation #$orderNumber - Cafe Shop";

        $mail->Body = "
            <h2>Xin chào $toName</h2>
            <p>Cảm ơn bạn đã đặt hàng tại <strong>Cafe Shop</strong>.</p>

            <h3>Thông tin đơn hàng</h3>
            <ul>
                <li><strong>Mã đơn hàng:</strong> $orderNumber</li>
                <li><strong>Phương thức thanh toán:</strong> $paymentMethod</li>
                <li><strong>Tổng thanh toán:</strong> " . number_format($totalAmount) . " VND</li>
                <li><strong>Địa chỉ giao hàng:</strong> $address</li>
            </ul>

            <hr>
            <p>Cafe Shop cảm ơn bạn!</p>
        ";

        return $mail->send();

    } catch (Exception $e) {
        error_log("MAIL ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
