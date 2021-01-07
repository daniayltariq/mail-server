<?php


namespace PBMail\Imap\Server\Event;


use PBMail\Imap\Server\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LogSubscriber implements EventSubscriberInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * LogSubscriber constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            Events::CONNECTION_CHANGE_STATE => 'onConnectionChangeState',
            Events::CONNECTION_HELO_RECEIVED => 'onConnectionHeloReceived',
            Events::CONNECTION_LINE_RECEIVED => 'onConnectionLineReceived',
        ];
    }
}