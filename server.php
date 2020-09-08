<?php
namespace PBMail;

include 'vendor/autoload.php';
require_once './lib/MessageProcessingHandler.php';
require_once './lib/MessageReceivedSubscriber.php';

try {
    $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();

    $logger = new \Monolog\Logger('log');
    $dispatcher->addSubscriber(new \Smalot\Smtp\Server\Event\LogSubscriber($logger));

    $msgHandler = new MessageProcessingHandler();
    $dispatcher->addSubscriber(new MessageReceivedSubscriber($msgHandler));

    $loop = \React\EventLoop\Factory::create();
    $server = new \Smalot\Smtp\Server\Server($loop, $dispatcher);

    // Enable 3 authentication methods.
    $server->authMethods = [];

    // Listen on port 25.
    $server->listen(8025, '0.0.0.0');
    $loop->run();
}
catch(\Exception $e) {
    var_dump($e);
}