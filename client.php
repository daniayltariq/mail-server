<?php
namespace PBMail;

use PHPMailer\PHPMailer\PHPMailer;

include 'vendor/autoload.php';

$mail = new PHPMailer();

$mail->isSMTP();
$mail->Host = 'localhost';
$mail->Port = 25;
$mail->SMTPDebug = true;
$mail->SMTPAuth = true;
$mail->Username = 'username@example.com';
$mail->Username = 'password';

$mail->setFrom('test@example.com', 'John Doe');
$mail->addAddress('joe@example.net', 'Joe User');     // Add a recipient
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