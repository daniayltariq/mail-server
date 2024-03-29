<?php
namespace PBMail;

use PHPMailer\PHPMailer\PHPMailer;

include 'vendor/autoload.php';

$mail = new PHPMailer(true);

$mail->isSMTP();
$mail->Host = '127.0.0.1';
$mail->Port = 25;
$mail->SMTPDebug = 2;
$mail->AuthType   = "CRAM-MD5";
$mail->SMTPAuth = true;
$mail->AuthType = "CRAM-MD5";
$mail->Username = 'test@admin.com';
$mail->Password = '$2y$10$ZR.HSiLH7rdo5c3GG6v5IeHPd6Uwt5qnYkc7ddSqqD9mP3ynmhYE2';

$mail->setFrom('from@example.site', 'John Doe');
$mail->addAddress('to1@example.net', 'Joe User');     // Add a recipient
$mail->addAddress('to2@example.net', 'Joe User');     // Add a recipient
$mail->addAddress('to3@example.net', 'Joe User');     // Add a recipient
//$mail->addAddress('ellen@example.site');               // Name is optional
//$mail->addReplyTo('info@example.site', 'Information');
$mail->addCC('cc1@example.site');
$mail->addCC('cc2@example.site');
$mail->addCC('cc3@example.site');
$mail->addBCC('bcc1@example.site');
$mail->addBCC('bcc2@example.site');
$mail->addBCC('bcc3@example.site');
$mail->addAttachment('images/usingwebsite1.png'); 
$mail->addAttachment('images/usingwebsite2.png');    // Add a recipient

$mail->Subject = 'Here is the subject';
$mail->Body    = 'This is the HTML message body <b>in bold!</b><a href="http://www.veryurl.site">Link</a><table class="mt_text"><tr><td>55888</td></tr></table>';
$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

if(!$mail->send()) {
    echo 'Message could not be sent.';
    echo 'Mailer Error: ' . $mail->ErrorInfo;
} else {
    echo 'Message has been sent';
}

echo $mail->getSentMIMEMessage();