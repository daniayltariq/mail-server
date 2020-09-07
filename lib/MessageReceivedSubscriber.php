<?php
namespace PBMail;

use Smalot\Smtp\Server\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PhpMimeMailParser\Parser;
use Smalot\Smtp\Server\Event\MessageReceivedEvent;

class MessageReceivedSubscriber implements EventSubscriberInterface
{
    /**
     * @var MessageProcessingHandler
     */
    protected $handler;

    /**
     * MessageReceivedSubscriber constructor.
     * @param MessageProcessingHandler $handler
     */
    public function __construct(MessageProcessingHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
          Events::MESSAGE_RECEIVED => 'onMessageReceived'
        ];
    }

    /**
     * @param MessageReceivedEvent $event
     */
    public function onMessageReceived(MessageReceivedEvent $event)
    {
        $parser = new Parser();
        $parser->setText($event->getMessage());
        $from = $parser->getAddresses('from');
        $subject = $parser->getHeader('subject');
        $html = $parser->getMessageBody('html');
        $this->handler->processEmail($from[0]['address'], $subject, $html);
    }
}
