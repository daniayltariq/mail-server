<?php
namespace PBMail;

use Dotenv\Dotenv;
use PBMail\Smtp\Server\Connection;
use PBMail\Smtp\Server\Event\LogSubscriber;
use PBMail\Smtp\Server\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

include 'vendor/autoload.php';
require_once './lib/MessageProcessingHandler.php';
require_once './lib/MessageReceivedSubscriber.php';

try {

    $dotenv = Dotenv::createUnsafeImmutable(__DIR__);
    $dotenv->load();

    $dispatcher = new EventDispatcher();

    $logger = new \Monolog\Logger('log');
    $dispatcher->addSubscriber(new LogSubscriber($logger));

    $msgHandler = new MessageProcessingHandler();
    $dispatcher->addSubscriber(new MessageReceivedSubscriber($msgHandler));

    $loop = \React\EventLoop\Factory::create();
    $server = new Server($loop, $dispatcher);

    // Enable 3 authentication methods.
    $server->authMethods = [
        Connection::AUTH_METHOD_LOGIN,
        Connection::AUTH_METHOD_CRAM_MD5,
    ];

    // Listen on port 25.
    $server->listen(25, '0.0.0.0');

    $loop->run();
}
catch(\Exception $e) {
    var_dump($e);
}