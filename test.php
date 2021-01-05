<?php

use PBMail\Helpers\MailHelper;
use Dotenv\Dotenv;

require 'vendor/autoload.php';


$env = Dotenv::createUnsafeImmutable(__DIR__);
$env->load();

//$header = 'cc1@anbharat.com, CC 2 Name <cc2@anbharat.com>';
//
//$emails = MailHelper::parseEmailsFromHeader($header);
//var_dump( $emails );


//$db = \PBMail\Helpers\DbHelper::getInstance();
//$db->connect();
//
//$stmt = $db->connection()->prepare('select `raw_email` from `emails` limit 1, 1');
//$stmt->execute();
//$rawEmail = $stmt->fetchColumn();
//$mail = new \PhpMimeMailParser\Parser();
//$mail->setText( $rawEmail );
//
//var_dump(
//    $mail->getHeader('to'),
//    $mail->getHeader('subject'),
//    MailHelper::parseEmailsFromHeader( $mail->getHeader('from'), false, false )
//);
//
//$db->disconnect();


$storage = \PBMail\Imap\Server\Storage::make(2);
$storage->db()->connect();
var_dump( $data->queryString );