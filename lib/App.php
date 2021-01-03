<?php


namespace PBMail;

use PBMail\Smtp\Server as Smtp;
use PBMail\Imap\Server as Imap;
use React\EventLoop\Factory;
use Symfony\Component\EventDispatcher\EventDispatcher;

class App
{


    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    public function __construct()
    {
        $this->loop = Factory::create();
    }


    /**
     * @return App
     */
    public static function make(): App
    {
        return new static;
    }


    /**
     * Initialize the SMTP server.
     *
     *     DEFAULT OPTIONS:
     *     [
     *         'log' => 'smtp_log',
     *         'auth' => ['LOGIN', 'CRAM-MD5'],
     *         'host' => '127.0.0.1',
     *         'port' => 25,
     *     ]
     *
     * @param array $options
     * @return Smtp\Server
     */
    public function useSmtpServer(array $options = []): Smtp\Server
    {

        $dispatcher = new EventDispatcher();

        $logger = new \Monolog\Logger($options['log'] ?? 'smtp_log');
        $dispatcher->addSubscriber(new Smtp\Event\LogSubscriber($logger));

        $msgHandler = new MessageProcessingHandler();
        $dispatcher->addSubscriber(new MessageReceivedSubscriber($msgHandler));

        $server = new Smtp\Server($this->loop, $dispatcher);

        // Enable 3 authentication methods.
        $server->authMethods = $options['auth'] ?? [
            Smtp\Connection::AUTH_METHOD_LOGIN,
            Smtp\Connection::AUTH_METHOD_CRAM_MD5,
        ];

        // Listen on port 25.
        $server->listen(
            $options['port'] ?? 25,
            $options['host'] ?? '127.0.0.1'
        );

        return $server;

    }


    /**
     * Initialize the Imap server.
     *
     *    DEFAULT OPTIONS:
     *
     *    [
     *        'log' => 'imap_log',
     *        'host' => '127.0.0.1',
     *        'port' => 25,
     *    ]
     *
     * @param array $options
     *
     * @return Smtp\Server
     */
    public function useImapServer(array $options): Imap\Server
    {

        $dispatcher = new EventDispatcher();

        $logger = new \Monolog\Logger($options['log'] ?? 'imap_log');
        $dispatcher->addSubscriber(new Imap\Event\LogSubscriber($logger));

        $server = new Imap\Server($this->loop, $dispatcher);

        // Listen on port 143.
        $server->listen(
            $options['port'] ?? 143,
            $options['host'] ?? '127.0.0.1'
        );

        return $server;
    }


    /**
     * Run the Event Loop.
     */
    public function run()
    {
        $this->loop->run();
    }


}