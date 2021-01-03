<?php
namespace PBMail;

//use Dotenv\Dotenv;

//use PBMail\Smtp\Server as SMTP;
//use PBMail\IMAP\Server as IMAP;

//use PBMail\Smtp\Server\Connection;
//use PBMail\Smtp\Server\Event\LogSubscriber as SMTPLogSubscriber;
//use PBMail\Smtp\Server\Server as SMTPServer;
//use PBMail\Imap\Event\LogSubscriber as ImapLogSubscriber;
//use PBMail\IMAP\Server\Server as IMAPServer;
use Dotenv\Dotenv;
use Symfony\Component\EventDispatcher\EventDispatcher;

include 'vendor/autoload.php';
require_once './lib/MessageProcessingHandler.php';
require_once './lib/MessageReceivedSubscriber.php';


$env = Dotenv::createUnsafeImmutable(__DIR__);
$env->load();


try{


    $app = App::make();


    // Start SMTP server for this application.
    $smtp = $app->useSmtpServer([
        'host' => '0.0.0.0',
        'port' => 25,
    ]);


    // Start IMAP server for this application.
    $imap = $app->useImapServer([
        'host' => '0.0.0.0',
        'port' => 143,
    ]);


    $app->run();


}catch (\Throwable $e){

    var_dump( $e );

}
