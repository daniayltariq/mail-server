<?php
namespace PBMail;

use PBMail\Smtp\Server\Event\MessageReceivedEvent;
use PBMail\Smtp\Server\Events;
use PBMail\Smtp\Server\Event\LogSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use PhpMimeMailParser\Parser;
// use PBMail\ConnectionRcptReceivedEvent;
// use Psr\Log\LoggerInterface;

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

    public function clearArray($array)
    {
        // cc
        // [{"display":"cc1@example.site","address":"cc1@example.site","is_group":false},{"display":"cc2@example.site","address":"cc2@example.site","is_group":false},{"display":"cc3@example.site","address":"cc3@example.site","is_group":false}]
        // bcc
        // [{"name":"joe@example.net","email":null},{"name":"cc1@example.site","email":null},{"name":"cc2@example.site","email":null},{"name":"cc3@example.site","email":null},{"name":"bcc1@example.site","email":null},{"name":"bcc2@example.site","email":null}]

    }

    /**
     * @param MessageReceivedEvent $event
     */
    public function onMessageReceived(MessageReceivedEvent $event)
    {
        // Check if user authenticated and if authenticated get the username (email of the user).
        // We will use this username in processEmail function if this is an outgoing email
        // to authorize users if they have permission to send email on the domain or not.
        $username = null;
        try{
            $username = $event->getConnection()->getAuthMethod()->getUsername();
        }catch(\Throwable $th){
            $username = null;
        }

        $parser = new Parser();
        $rawEmail = $event->getMessage();
        $parser->setText($rawEmail);
        $from = $parser->getAddresses('from');
        $to = $parser->getAddresses('to');
        $cc = $parser->getAddresses('cc');

        // $bcc = $parser->getAddresses('bcc'); //returns nothing
        $bcc = $_SESSION["bcc"];//$parser->getHeadersRaw(); //does not contain the bcc addresses
        $_SESSION["bcc"] = array(); //making it blank for next mail

        

        $subject = $parser->getHeader('subject');
        $html = $parser->getMessageBody('html');
        // Message-ID is the identifier for email. This will be used to identify replied emails.
        $messageId = $parser->getHeader('Message-ID');
        // In-Reply-To and References headers mean that
        // email is sent as a reply to an email identified by id
        // in In-Reply-To and References headers.
        // getHeader will return false if these headers do not exists. In this case assign null to these variables.
        $inReplyTo = $parser->getHeader('In-Reply-To') ? $parser->getHeader('In-Reply-To') : null;
        $references = $parser->getHeader('References') ? $parser->getHeader('References') : null;

        $this->handler->processEmail(
            $from[0]['address'],
            $from[0]['display'],
            $to[0]['address'],
            $cc,
            $bcc,
            $subject,
            $html,
            $username,
            $messageId,
            $inReplyTo,
            $references,
            $rawEmail
        );
    }
}
