<?php


namespace PBMail\Imap\Server;


use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Stream\Stream;
use React\Stream\WritableStreamInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Connection extends Stream implements ConnectionInterface
{


    // Delimiter for commands.
    const DELIMITER = "\r\n";


    // Commands ==================================
    const CMD_CAPABILITY    = 'capability';
    const CMD_NOOP          = 'noop';
    const CMD_LOGOUT        = 'logout';
    const CMD_AUTHENTICATE  = 'authenticate';
    const CMD_LOGIN         = 'login';
    const CMD_SELECT        = 'select';
    const CMD_CREATE        = 'create';
    const CMD_SUBSCRIBE     = 'subscribe'; // @NOTICE NOT_IMPLEMENTED
    const CMD_UNSUBSCRIBE   = 'unsubscribe'; // @NOTICE NOT_IMPLEMENTED


    // Auth Methods =============================
    const AUTH_METHOD_PLAIN = 'plain';
    
    
    // STATES ===================================
    const STATE_HAS_AUTH        = 'hasAuth';
    const STATE_AUTH_STEP       = 'authStep';
    const STATE_AUTH_TAG        = 'authTag';
    const STATE_AUTH_METHOD     = 'authMethod';
    const STATE_APPEND_STEP     = 'appendStep';
    const STATE_APPEND_TAG      = 'appendTag';
    const STATE_APPEND_FOLDER   = 'appendFolder';
    const STATE_APPEND_FLAGS    = 'appendFlags';
    const STATE_APPEND_DATE     = 'appendDate';
    const STATE_APPEND_LITERAL  = 'appendLiteral';
    const STATE_APPEND_MSG      = 'appendMsg';


    const CMD_LIST = 'list';
    const CMD_LSUB = 'lsub';


    /**
     * @var Server
     */
    protected $server;

    /**
     * @var EventDispatcherInterface|null
     */
    protected $dispatcher;

    /**
     * @var string
     */
    protected $lineBuffer = '';

    /**
     * Connection State
     * @var array
     */
    public $state;
    /**
     * @var string
     */
    protected $selectedFolder;
    /**
     * @var array
     */
    protected $subscriptions = [];

    public function __construct($stream, LoopInterface $loop, Server $server, EventDispatcherInterface $dispatcher = null)
    {

        parent::__construct($stream, $loop);

        $this->server = $server;
        $this->dispatcher = $dispatcher;


        // Set the default STATE.
        $this->state = State::make([
            static::STATE_HAS_AUTH          => false,
            static::STATE_AUTH_STEP         => 0,
            static::STATE_AUTH_TAG          => '',
            static::STATE_AUTH_METHOD       => '',
            static::STATE_APPEND_STEP       => 0,
            static::STATE_APPEND_TAG        => '',
            static::STATE_APPEND_FOLDER     => '',
            static::STATE_APPEND_FLAGS      => [],
            static::STATE_APPEND_DATE       => '',    // @NOTICE NOT_IMPLEMENTED
            static::STATE_APPEND_LITERAL    => 0,
            static::STATE_APPEND_MSG        => '',
        ]);

        // Greet client with hello.
        $this->sendHello();


        // On receiving a complete command sequence, pass it to command handler.
        $this->on('data', function (string $data) {
            $delimiterPos = strpos($data, static::DELIMITER);
            if ($delimiterPos === false) {
                $this->lineBuffer .= $data;
            } else {
                $line = $this->lineBuffer . substr($data, 0, $delimiterPos);
                $this->lineBuffer = '';
                $this->handleRawCommand( $line );
            }
        });

    }


    /**
     * @param string $line
     * @return string
     */
    public function handleRawCommand(string $line): string
    {

        // var_dump("CMD: $line\n");

        $args = $this->parseRawCommandString($line, 3);

        // Get Tag, and remove Tag from Arguments.
        /** @var string $tag */
        $tag = array_shift($args);

        // Get Command, and remove Command from Arguments.
        /** @var string $command */
        $command = array_shift($args);
        $commandCmp = strtolower($command);

        // Get rest Arguments as String. Do not reuse $args here. Just let it as it is.
        /** @var string $restArgs */
        $restArgs = array_shift($args) ?? '';



        switch ( $commandCmp ){


            case static::CMD_CAPABILITY:
                return $this->sendCapability($tag);



            case static::CMD_NOOP:
                return $this->sendNoop($tag);



            case static::CMD_LOGOUT:
                $rv = $this->sendBye('IMAP4rev1 Server logging out');
                $rv .= $this->sendLogout($tag);
                $this->close();
                return $rv;



            case static::CMD_AUTHENTICATE:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);
                $authMethod = $commandArgs[0];


                // Auth Method :: PLAIN
                if( $authMethod === static::AUTH_METHOD_PLAIN ){

                    $this->state->set( static::STATE_AUTH_STEP, 1 );
                    $this->state->set( static::STATE_AUTH_TAG, $tag );
                    $this->state->set( static::STATE_AUTH_METHOD, $authMethod );

                    return $this->sendAuthenticate();
                }

                // TODO: Support any other authentication methods.

                return $this->sendNo($authMethod . ' Unsupported authentication mechanism', $tag);



            case static::CMD_LOGIN:

                $commandArgs = $this->parseRawCommandString($restArgs, 2);

                if ( ( $commandArgs[0] ?? false ) && ( $commandArgs[1] ?? false ) ) {
                    return $this->sendLogin($tag);
                }

                return $this->sendBad('Arguments invalid.', $tag);



            case static::CMD_SELECT:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                if( $this->isAuthenticated() ){

                    if ($commandArgs[0] ?? false) {
                        return $this->sendSelect($tag, $commandArgs[0]);
                    }

                    $this->selectedFolder = '';
                    return $this->sendBad('Arguments invalid.', $tag);

                }

                $this->selectedFolder = '';
                return $this->sendBad('Arguments invalid.', $tag);



            case static::CMD_CREATE:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                if ( $this->isAuthenticated() ) {

                    if ($commandArgs[0] ?? false) {
                        return $this->sendCreate($tag, $commandArgs[0]);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);



            case static::CMD_SUBSCRIBE:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                if ( $this->isAuthenticated() ) {
                    if ( $commandArgs[0] ?? false ) {
                        return $this->sendSubscribe($tag, $commandArgs[0]);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);



            case static::CMD_UNSUBSCRIBE:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                if ( $this->isAuthenticated() ) {
                    if ( $commandArgs[0] ?? false ) {
                        return $this->sendUnsubscribe($tag, $commandArgs[0]);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);



            case static::CMD_LIST:

                $args = $this->parseRawCommandString($restArgs, 2);

                if ( $this->isAuthenticated() ) {
                    if ( ( $args[0] ?? false ) && ( $args[1]) ?? false ) {
                        $refName = $args[0];
                        $folder = $args[1];
                        return $this->sendList($tag, $refName, $folder);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);



            case static::CMD_LSUB:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                // TODO: Implement Logger
//                if (isset($commandArgs[0])) {
//                    $this->logger->debug(sprintf('client %d lsub: %s', $this->id, $commandArgs[0]));
//                } else {
//                    $this->logger->debug(sprintf('client %d lsub: N/A', $this->id));
//                }

                if ( $this->isAuthenticated() ) {
                    if (isset($commandArgs[0]) && $commandArgs[0]) {
                        return $this->sendLsub($tag);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);


                // TODO: Implement rest of commands.

        }

        return '';

    }


    /**
     * @param string $address
     * @return string
     */
    protected function parseAddress(string $address): string
    {
        return trim( substr( $address, 0, strrpos( $address, ':' ) ), '[]');
    }


    /**
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->parseAddress(stream_socket_get_name($this->stream, true));
    }


    /**
     * @param string $str
     * @param int $argsMax
     * @return array
     */
    protected function parseRawCommandString(string $str, int $argsMax): array
    {
        $str = new StringParser($str, $argsMax);
        return $str->parse();
    }


    /**
     * Check if current client is authenticated.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->state->is( static::STATE_HAS_AUTH, true );
    }



    /**
     * @param string $msg
     * @return string
     */
    public function sendData(string $msg): string
    {
        $output = $msg . static::DELIMITER;
        $this->write($output);
        return $output;
    }



    /**
     * @param string $text
     * @param string|null $tag
     * @param string|null $code
     * @return string
     */
    public function sendOk(string $text, string $tag = null, string $code = null): string
    {
        $tag = $tag ?? '*';
        return $this->sendData($tag . ' OK' . ($code ? ' [' . $code . ']' : '') . ' ' . $text);
    }


    /**
     * Send NO Message.
     *
     * @param string $text
     * @param string|null $tag
     * @param string|null $code
     * @return string
     */
    public function sendNo(string $text, string $tag = null, string $code = null): string
    {
        $tag = $tag ?? '*';
        return $this->sendData($tag . ' NO' . ($code ? ' [' . $code . ']' : '') . ' ' . $text);
    }


    /**
     * Send BAD Message
     * @param string $text
     * @param string|null $tag
     * @param string|null $code
     * @return string
     */
    public function sendBad(string $text, string $tag = null, string $code = null): string
    {
        $tag = $tag ?? '*';
        return $this->sendData($tag . ' BAD' . ($code ? ' [' . $code . ']' : '') . ' ' . $text);
    }


    /**
     * Send welcome message
     *
     * @return string
     */
    public function sendHello(): string
    {
        return $this->sendOk('IMAP4rev1 Service Ready');
    }



    /**
     * Send all applicable capabilities.
     *
     * @param string $tag
     * @return string
     */
    public function sendCapability(string $tag): string
    {
        $rv = $this->sendData('* CAPABILITY IMAP4rev1 AUTH=PLAIN');
        $rv .= $this->sendOk('CAPABILITY completed', $tag);
        return $rv;
    }

    /**
     *
     * Send NOOP.
     *
     * @param string $tag
     * @return string
     */
    public function sendNoop(string $tag): string
    {
//        if ($this->selectedFolder) {
//            $this->sendSelectedFolderInfos();
//        }

        // TODO: Implement NOOP.

        return '';

    }


    /**
     *
     * Send BYE message
     *
     * @param string $string
     * @param string|null $code
     * @return string
     */
    public function sendBye(string $string, string $code = null): string
    {
        return $this->sendData('* BYE' . ($code ? ' [' . $code . ']' : '') . ' ' . $string);
    }

    /**
     * Send LOGOUT Message.
     * @param string $tag
     * @return string
     */
    public function sendLogout(string $tag): string
    {
        return $this->sendOk('LOGOUT completed', $tag);
    }


    /**
     *
     * @return string
     */
    public function sendAuthenticate(): string
    {

        if( $this->state->is( static::STATE_AUTH_STEP, 1 ) ){
            return $this->sendData('+');
        }

        if( $this->state->is( static::STATE_AUTH_STEP, 2 ) ){

            // TODO: Do actual authentication of static::STATE_AUTH_METHOD

            $this->state->set(static::STATE_HAS_AUTH, true);
            $this->state->set(static::STATE_AUTH_STEP, 0);

            $msg = sprintf('%s authentication successful', $this->state->get( static::STATE_AUTH_METHOD ) );
            return $this->sendOk($msg, $this->state->get( static::STATE_AUTH_TAG ));

        }

        return '';
        
    }


    /**
     * Send LOGIN Message
     *
     * @param string $tag
     * @return string
     */
    public function sendLogin(string $tag): string
    {
        return $this->sendOk('LOGIN completed', $tag);
    }


    /**
     * Send SELECT Message
     *
     * @param string $tag
     * @param string $folder
     * @return string
     */
    public function sendSelect(string $tag, string $folder): string
    {


        if (strtolower($folder) === 'inbox' && $folder != 'INBOX') {
            // Set folder to INBOX if folder is not INBOX
            // e.g. Inbox, INbOx or something like this.
            $folder = 'INBOX';
        }

        // TODO: Implement the commented section below.
//        if ( $this->select( $folder ) ) {
//            $rv = $this->sendSelectedFolderInfos();
//            $rv .= $this->sendOk('SELECT completed', $tag, 'READ-WRITE');
//            return $rv;
//        }

        return $this->sendNo('"' . $folder . '" no such mailbox', $tag);

    }

    public function sendCreate(string $tag, string $folder): string
    {

        if ( strpos($folder, '/') !== false) {
            $msg = 'invalid name';
            $msg .= ' - no directory separator allowed in folder name';
            return $this->sendNo('CREATE failure: ' . $msg, $tag);
        }

        // TODO: Create Folder.

//        if ($this->getServer()->addFolder($folder)) {
//            return $this->sendOk('CREATE completed', $tag);
//        }

        return $this->sendNo('CREATE failure: folder already exists', $tag);
    }


    /**
     * Send SUBSCRIBE Message.
     *
     * @param string $tag
     * @param string $folder
     * @return string
     */
    public function sendSubscribe(string $tag, string $folder): string
    {

        // TODO: Check if folder exists.

        if ( /*$this->getServer()->folderExists($folder)*/ true ) {
            // @NOTICE NOT_IMPLEMENTED
            $this->subscriptions[] = $folder;
            return $this->sendOk('SUBSCRIBE completed', $tag);
        }

        return $this->sendNo("SUBSCRIBE failure: no subfolder named $folder", $tag);

    }


    /**
     * Send UNSUBSCRIBE Message
     *
     * @param string $tag
     * @param string $folder
     * @return string
     */
    public function sendUnsubscribe(string $tag, string $folder): string
    {

        // TODO: Check if folder exists.

        if (/*$this->getServer()->folderExists($folder)*/ true) {
            // @NOTICE NOT_IMPLEMENTED
            return $this->sendOk('UNSUBSCRIBE completed', $tag);
        }

        return $this->sendNo('UNSUBSCRIBE failure: no subfolder named test_dir', $tag);
    }


    /**
     * Send LIST Message
     *
     * @param string $tag
     * @param string $baseFolder
     * @param string $folder
     * @return string
     */
    public function sendList(string $tag, string $baseFolder, string $folder): string
    {

        $folder = str_replace('%', '*', $folder); // @NOTICE NOT_IMPLEMENTED


        // $folders = $this->getServer()->getFolders($baseFolder, $folder, true);

        // TODO: Implement get folders.
        $folders = [];

        $rv = '';
        if ( count($folders) ) {
            foreach ($folders as $f) {
                $rv .= $this->sendData('* LIST () "." "' . $f . '"');
            }
        } else {
            // TODO: Check if folder exists.
            if (/*$this->getServer()->folderExists($folder)*/  true ) {
                $rv .= $this->sendData('* LIST () "." "' . $folder . '"');
            }
        }

        $rv .= $this->sendOk('LIST completed', $tag);

        return $rv;

    }


    /**
     * Send LSUB Message
     *
     * @param string $tag
     * @return string
     */
    public function sendLsub(string $tag): string
    {

        $rv = '';
        foreach ($this->subscriptions as $subscription) {
            $rv .= $this->sendData('* LSUB () "." "' . $subscription . '"');
        }

        $rv .= $this->sendOk('LSUB completed', $tag);

        return $rv;

    }

}