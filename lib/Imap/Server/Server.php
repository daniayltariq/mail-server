<?php


namespace PBMail\Imap\Server;


use PBMail\Imap\Server\Connection;
use React\EventLoop\LoopInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Server extends \React\Socket\Server
{

    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * Server constructor.
     * @param LoopInterface $loop
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(LoopInterface $loop, EventDispatcherInterface $dispatcher)
    {
        parent::__construct($loop);

        $this->loop = $loop;
        $this->dispatcher = $dispatcher;
    }


    /**
     * @param $socket
     * @return \PBMail\Imap\Server\Connection
     */
    public function createConnection($socket): \PBMail\Imap\Server\Connection
    {
        return new Connection($socket, $this->loop, $this, $this->dispatcher);
    }


}