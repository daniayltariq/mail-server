<?php
namespace PBMail;

use Dotenv\Dotenv;

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
