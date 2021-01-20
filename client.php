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
$mail->Username = 'test@admin.com';
$mail->Password   = '$2y$10$EVUt3IYVW/Vd/AMZZ0HqBeUKEQUWIlEMEGZJ/m8eNLDcZF2LrF4Ee';
$mail->setFrom('test@example.site', 'John Doe');
$mail->addAddress('test@timelesscomputersblog.club', 'Joe User'); 
$mail->addAttachment('images/usingwebsite1.png'); 
$mail->addAttachment('images/usingwebsite2.png');    // Add a recipient
//$mail->addAddress('ellen@example.com');               // Name is optional
//$mail->addReplyTo('info@example.com', 'Information');
//$mail->addCC('cc@example.com');
//$mail->addBCC('bcc@example.com');
//$mail->addBCC('another@example.com');

$mail->Subject = 'Here is the subject';
$mail->Body    = 'This is the HTML message body <b>in bold!</b><a href="http://www.veryurl.com">Link</a><table class="mt_text"><tr><td>55888</td></tr></table>';
$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

if(!$mail->send()) {
    echo 'Message could not be sent.';
    echo 'Mailer Error: ' . $mail->ErrorInfo;
} else {
    echo 'Message has been sent';
}

echo $mail->getSentMIMEMessage();