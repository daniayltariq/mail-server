<?php


namespace PBMail\Imap\Server;


use PBMail\Helpers\DbHelper;
use PBMail\Helpers\MailHelper;
use PhpMimeMailParser\Parser;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Stream\Stream;
use React\Stream\WritableStreamInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TheFox\Logic\AndGate;
use TheFox\Logic\CriteriaTree;
use TheFox\Logic\Gate;
use TheFox\Logic\NotGate;
use TheFox\Logic\Obj;
use TheFox\Logic\OrGate;

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
    const CMD_LIST          = 'list';
    const CMD_LSUB          = 'lsub';
    const CMD_UID           = 'uid';
    const CMD_SEARCH            = 'search';


    // Auth Methods =============================
    const AUTH_METHOD_PLAIN = 'plain';

    
    // STATES ===================================
    const STATE_AUTH_STEP       = 'authStep';
    const STATE_AUTH_TAG        = 'authTag';
    const STATE_AUTH_METHOD     = 'authMethod';
    const STATE_AUTH_USER       = 'authUser';
    const STATE_APPEND_STEP     = 'appendStep';
    const STATE_APPEND_TAG      = 'appendTag';
    const STATE_APPEND_FOLDER   = 'appendFolder';
    const STATE_APPEND_FLAGS    = 'appendFlags';
    const STATE_APPEND_DATE     = 'appendDate';
    const STATE_APPEND_LITERAL  = 'appendLiteral';
    const STATE_APPEND_MSG      = 'appendMsg';


    const CMD_CLOSE = 'close';
    const CMD_EXPUNGE = 'expunge';
    const CMD_CHECK = 'check';


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
     * @var State
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

    /**
     * @var array
     */
    protected $expunge = [];


    public function __construct($stream, LoopInterface $loop, Server $server, EventDispatcherInterface $dispatcher = null)
    {

        parent::__construct($stream, $loop);

        $this->server = $server;
        $this->dispatcher = $dispatcher;


        // Set the default STATE.
        $this->state = State::make([
            static::STATE_AUTH_USER         => null,
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
                $this->end();
                return $rv;



            case static::CMD_AUTHENTICATE:

                $commandArgs = $this->parseRawCommandString($restArgs, 2);
                $authMethod = $commandArgs[0];


                // Auth Method :: PLAIN
                if( $authMethod === static::AUTH_METHOD_PLAIN ){

                    /**
                     * If auth hash exists in the command, then directly execute
                     * authentication step 2.
                     */
                    $credentials = $this->parsePlainCredentials( $commandArgs[1] ?? '' );

                    $this->state->set( static::STATE_AUTH_STEP, ($credentials['username'] ?? false) ? 2 : 1 );
                    $this->state->set( static::STATE_AUTH_TAG, $tag );
                    $this->state->set( static::STATE_AUTH_METHOD, $authMethod );

                    return $this->sendAuthenticate( $credentials );
                }

                return $this->sendNo($authMethod . ' Unsupported authentication mechanism', $tag);



            case static::CMD_LOGIN:


                // TODO: This is plain-text login, later we may implement more auth methods.

                $commandArgs = $this->parseRawCommandString($restArgs, 2);

                if ( ( $commandArgs[0] ?? false ) && ( $commandArgs[1] ?? false ) ) {
                    return $this->sendLogin($tag, [
                        'username' => $commandArgs[0],
                        'password' => $commandArgs[1],
                    ]);
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
                    if ( isset( $args[0] ) && ( $args[1]) ?? false ) {
                        $refName = $args[0];
                        $folder = $args[1];
                        return $this->sendList($tag, $refName, $folder);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);



            case static::CMD_LSUB:

                $commandArgs = $this->parseRawCommandString($restArgs, 1);

                if ( $this->isAuthenticated() ) {
                    if (isset($commandArgs[0]) && $commandArgs[0]) {
                        return $this->sendLsub($tag);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);


            case static::CMD_SEARCH:

                if ( $this->isAuthenticated() ) {

                    if ($restArgs ?? false) {
                        if ($this->selectedFolder) {
                            return $this->sendSearch($tag, $restArgs);
                        }

                        return $this->sendNo('No mailbox selected.', $tag);
                    }

                    return $this->sendBad('Arguments invalid.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);


            case static::CMD_UID:

                if ( $this->isAuthenticated() ) {

                    if ( $this->selectedFolder ) {
                        return $this->sendUid($tag, $restArgs);
                    }
                    return $this->sendNo('No mailbox selected.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);

            case static::CMD_EXPUNGE:

                if ( $this->isAuthenticated() ) {
                    if ($this->selectedFolder) {
                        return $this->sendExpunge($tag);
                    }

                    return $this->sendNo('No mailbox selected.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);


            case static::CMD_CLOSE:


                if ( $this->isAuthenticated() ) {
                    if ( $this->selectedFolder ) {
                        return $this->sendClose($tag);
                    }

                    return $this->sendNo('No mailbox selected.', $tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);

                
            case static::CMD_CHECK:
                
                if ( $this->isAuthenticated() ) {
                    return $this->sendCheck($tag);
                }

                return $this->sendNo($commandCmp . ' failure', $tag);
                

                // ======================================
                // TODO: Implement rest of commands.
                // ======================================




            default:

                /**
                 * Handle the auth continued from stream.
                 * Here, $tag is the actual data passed in console.
                 */
                if( $this->state->is( static::STATE_AUTH_STEP, 1 ) ){
                    $this->state->set( static::STATE_AUTH_STEP, 2);
                    return $this->sendAuthenticate( $this->parsePlainCredentials( $tag ) );

                } elseif ($this->state->get( static::STATE_APPEND_STEP ) >= 1) {
                    return $this->sendAppend($line);

                } else {
                    return $this->sendBad(sprintf('Not implemented: "%s" "%s"', $tag, $command), $tag);
                }

                // TODO: Add other default handlers.


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
    protected function parseRawCommandString(string $str, $argsMax = null): array
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
        return (bool) $this->user();
    }

    /**
     * Get currently authenticated user.
     *
     * @return mixed|null
     */
    public function user()
    {
        return $this->state->get(static::STATE_AUTH_USER);
    }

    /**
     * @return Storage
     */
    public function storage(): Storage
    {
        return Storage::make( $this->user() );
    }


    /**
     * Select a folder.
     *
     * @param string $folder
     * @return bool
     */
    public function select(string $folder)
    {
        if ( $this->storage()->folderExists( $folder ) ) {
            $this->selectedFolder = $folder;
            return true;
        }

        $this->selectedFolder = '';
        return false;

    }


    /**
     * Checks if credentials are correct, if yes then
     * set the user to current session.
     *
     * @param string $username
     * @param string $password
     * @return boolean
     */
    public function authenticateUser($username = '', $password = ''): bool
    {

        if( $username && $password ) {

            $db = DbHelper::getInstance();
            $user = $db->getUserForUsername( $username, '`id`, `password`' );

            if( $user && password_verify( $password, $user['password'] ) ){
                $this->state->set( static::STATE_AUTH_USER, $user['id'] );
                return true;
            }
        }

        $this->state->set( static::STATE_AUTH_USER, null);
        return false;

    }


    /**
     *
     * @param string $hash
     * @return array|null
     */
    public function parsePlainCredentials(string $hash): array
    {
        $raw = base64_decode( $hash );
        $cred = explode("\0", $raw);

        if( count( $cred ) === 3 ){
            return [
                'username' => $cred[ 1 ],
                'password' => $cred[ 2 ],
            ];
        }

        return [];
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
        if ($this->selectedFolder) {
            $this->sendSelectedFolderInfos();
        }

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
     * Authenticate the user by the credentials.
     * @param mixed $credentials
     * @return string
     */
    public function sendAuthenticate(array $credentials = []): string
    {

        if( $this->state->is( static::STATE_AUTH_STEP, 1 ) ){
            return $this->sendData('+');
        }

        if( $this->state->is( static::STATE_AUTH_STEP, 2 ) ){

            $this->state->set(static::STATE_AUTH_STEP, 0);

            if($this->authenticateUser(
                $credentials['username'] ?? '',
                $credentials['password'] ?? ''
            )) {
                $msg = sprintf('%s authentication successful', $this->state->get( static::STATE_AUTH_METHOD ) );
                return $this->sendOk($msg, $this->state->get( static::STATE_AUTH_TAG ));
            } else {
                $msg = sprintf('%s authentication failure.', $this->state->get( static::STATE_AUTH_METHOD ) );
                return $this->sendNo($msg, $this->state->get( static::STATE_AUTH_TAG ));
            }

        }

        return '';
        
    }


    /**
     * Send LOGIN Message
     *
     * @param string $tag
     * @param array $credentials
     * @return string
     */
    public function sendLogin(string $tag, $credentials = []): string
    {

        if($this->authenticateUser(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        )){
            return $this->sendOk('LOGIN completed', $tag);
        }

        return $this->sendNo('LOGIN failed.', $tag);

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

        if ( $this->select( $folder ) ) {
            $rv = $this->sendSelectedFolderInfos();
            $rv .= $this->sendOk('SELECT completed', $tag, 'READ-WRITE');
            return $rv;
        }

        return $this->sendNo('"' . $folder . '" no such mailbox', $tag);

    }

    public function sendCreate(string $tag, string $folder): string
    {

        if ( strpos($folder, '/') !== false) {
            $msg = 'invalid name';
            $msg .= ' - no directory separator allowed in folder name';
            return $this->sendNo('CREATE failure: ' . $msg, $tag);
        }

        if ($this->storage()->addFolder($folder)) {
            return $this->sendOk('CREATE completed', $tag);
        }

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
        if ( $this->storage()->folderExists( $folder ) ) {
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

        if ($this->storage()->folderExists($folder)) {
            // @NOTICE NOT_IMPLEMENTED

            $index = array_search( $folder, $this->subscriptions );
            if( $index !== false ) unset( $this->subscriptions[ $index ] );

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

        $folders = Storage::make( $this->user() )->folders;

        $rv = '';
        if ( count( $folders ) ) {
            foreach ($folders as $f) {
                $rv .= $this->sendData('* LIST (\\HasNoChildren) "." "' . $f . '"');
            }
        } else {
            if ( in_array( $folder, $folders ) ) {
                $rv .= $this->sendData('* LIST (\\HasNoChildren) "." "' . $folder . '"');
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

    public function sendUid(string $tag, string $argsStr): string
    {

        $args = $this->parseRawCommandString($argsStr, 2);
        $command = $args[0];
        $commandcmp = strtolower($command);

        if (isset($args[1])) {
            $args = $args[1];
        } else {
            return $this->sendBad('Arguments invalid.', $tag);
        }

        if ($commandcmp == 'copy') {

            // @NOTICE NOT_IMPLEMENTED

        } elseif ($commandcmp == 'fetch') {
            $args = $this->parseRawCommandString($args, 2);
            $seq = $args[0];
            $name = $args[1] ?? '';

            $rv = $this->sendFetchRaw($tag, $seq, $name, true);
            $rv .= $this->sendOk('UID FETCH completed', $tag);

            return $rv;
        } elseif ($commandcmp == 'store') {

            // @NOTICE NOT_IMPLEMENTED

        } elseif ($commandcmp == 'search') {
            $criteriaStr = $args;

            try {
                $rv = $this->sendSearchRaw($criteriaStr, true);
            } catch (\Exception $e) { }

            $rv .= $this->sendOk('UID SEARCH completed', $tag);

            return $rv;
        }

        return $this->sendBad('Arguments invalid.', $tag);

    }


    /**
     * @param string $msgRaw
     * @param int $level
     * @return array
     */
    public function getParenthesizedMessageList(string $msgRaw, int $level = 0): array
    {
        $rv = [];
        $rvc = 0;
        if ($msgRaw) {
            if ($msgRaw[0] == '(' && substr($msgRaw, -1) != ')' || $msgRaw[0] != '(' && substr($msgRaw, -1) == ')') {
                $msgRaw = '(' . $msgRaw . ')';
            }
            if ($msgRaw[0] == '(' || $msgRaw[0] == '[') {
                $msgRaw = substr($msgRaw, 1);
            }
            if (substr($msgRaw, -1) == ')' || substr($msgRaw, -1) == ']') {
                $msgRaw = substr($msgRaw, 0, -1);
            }

            $msgRawLen = strlen($msgRaw);
            while ($msgRawLen) {
                if ($msgRaw[0] == '(' || $msgRaw[0] == '[') {
                    $pair = ')';
                    if ($msgRaw[0] == '[') {
                        $pair = ']';
                    }

                    // Find ')'
                    $pos = strlen($msgRaw);
                    while ($pos > 0) {
                        if (substr($msgRaw, $pos, 1) == $pair) {
                            break;
                        }
                        $pos--;
                    }

                    $rvc++;
                    $rv[$rvc] = $this->getParenthesizedMessageList(substr($msgRaw, 0, $pos + 1), $level + 1);
                    $msgRaw = substr($msgRaw, $pos + 1);
                    $rvc++;
                } else {
                    if (!isset($rv[$rvc])) {
                        $rv[$rvc] = '';
                    }
                    $rv[$rvc] .= $msgRaw[0];
                    $msgRaw = substr($msgRaw, 1);
                }

                $msgRawLen = strlen($msgRaw);
            }
        }

        $rv2 = [];
        foreach ($rv as $n => $item) {
            if (is_string($item)) {
                foreach ($this->parseRawCommandString( $item ) as $j => $sitem) {
                    $rv2[] = $sitem;
                }
            } else {
                $rv2[] = $item;
            }
        }

        return $rv2;
    }


    /**
     *
     * Send RAW Message.
     *
     * @param string $tag
     * @param string $seq
     * @param string $name
     * @param bool $isUid
     * @return string
     */
    public function sendFetchRaw(string $tag, string $seq, string $name, bool $isUid = false)
    {

        $msgItems = [];
        if ($isUid) {
            $msgItems['uid'] = '';
        }

        if ( $name ) {
            $wanted = $this->getParenthesizedMessageList($name);
            foreach ($wanted as $n => $item) {
                if (is_string($item)) {
                    $itemcmp = strtolower($item);
                    if ($itemcmp == 'body.peek') {
                        $next = $wanted[$n + 1];
                        $nextr = [];
                        if (is_array($next)) {
                            $keys = [];
                            $vals = [];
                            foreach ($next as $x => $val) {
                                if ($x % 2 == 0) {
                                    $keys[] = strtolower($val);
                                } else {
                                    $vals[] = $val;
                                }
                            }
                            $nextr = array_combine($keys, $vals);
                        }
                        $msgItems[$itemcmp] = $nextr;
                    } else {
                        $msgItems[$itemcmp] = '';
                    }
                }
            }
        }

        $rv = '';
        $msgSeqNums = $this->createSequenceSet($seq, $isUid);

        // Process collected msgs.
        foreach ($msgSeqNums as $msgSeqNum) {
            $mailId = $this->storage()->getMsgIdBySeq($msgSeqNum, $this->selectedFolder);
            if (!$mailId) {
//                $this->logger->error('Can not get ID for seq num ' . $msgSeqNum . ' from root storage.');
                continue;
            }

            $mail = $this->storage()->getMailById($mailId);
            if (!$mail) {
                continue;
            }

            $flags = $this->storage()->getFlagsById($mailId);

            $output = [];
            $outputHasFlag = false;
            $outputBody = '';
            foreach ($msgItems as $item => $val) {
                if ($item == 'flags') {
                    $outputHasFlag = true;
                } elseif ($item == 'body' || $item == 'body.peek') {
                    $peek = $item == 'body.peek';
                    $section = '';

                    $msgStr = $mail->getData();
                    if (isset($val['header'])) {
                        $section = 'HEADER';
                        $msgStr = implode( static::DELIMITER, $mail->getHeaders() );
                    } elseif (isset($val['header.fields'])) {
                        $section = 'HEADER';
                        $msgStr = '';

                        $headers = $mail->getHeaders();

                        $headerStrs = [];

                        // TODO: Check how headers are set, so return specific part.
                        foreach ($val['header.fields'] as $fieldNum => $field) {
                            $fieldHeader = $headers->get($field);
                            if ($fieldHeader !== false) {
                                $msgStr .= $fieldHeader->toString() . static::DELIMITER;
                            }
                        }
                    }

                    $msgStr .= static::DELIMITER;
                    $msgStrLen = strlen($msgStr);
                    #$output[] = 'BODY['.$section.'] {'.$msgStrLen.'}'.Headers::EOL.$msgStr.Headers::EOL;
                    $outputBody = 'BODY[' . $section . '] {' . $msgStrLen . '}' . static::DELIMITER . $msgStr;
                } elseif ($item == 'rfc822.size') {
                    $size = strlen($mail->getData());
                    $output[] = 'RFC822.SIZE ' . $size;
                } elseif ($item == 'uid') {
                    $output[] = 'UID ' . $mailId;
                }
            }

            if ($outputHasFlag) {
                $output[] = 'FLAGS (' . join(' ', $flags) . ')';
            }
            if ($outputBody) {
                $output[] = $outputBody;
            }

            $rv .= $this->sendData('* ' . $msgSeqNum . ' FETCH (' . join(' ', $output) . ')');

            unset( $flags[ Storage::FLAG_RECENT ]);
            $this->storage()->setFlagsById($mailId, $flags);
        }

        return $rv;

    }


    /**
     *
     * @param string $setStr
     * @param false $isUid
     * @return array|int[]
     */
    public function createSequenceSet(string $setStr, $isUid = false): array
    {

        // Collect messages with sequence-sets.
        $setStr = trim($setStr);

        /** @var int[] $msgSeqNums */
        $msgSeqNums = [];
        foreach (preg_split('/,/', $setStr) as $seqItem) {
            
            $seqItem = trim($seqItem);

            $seqMin = 0;
            $seqMax = 0;
            //$seqLen = 0;
            $seqAll = false;

            $items = preg_split('/:/', $seqItem, 2);
            $items = array_map('trim', $items);

            /** @var int[] $nums */
            $nums = [];
            $count = $this->storage()->getCountMailsByFolder($this->selectedFolder);
            if (!$count) {
                return [];
            }

            // Check if it's a range.
            if (count($items) == 2) {
                $seqMin = (int)$items[0];
                if ($items[1] == '*') {
                    if ($isUid) {
                        // Search the last msg
                        for ($msgSeqNum = 1; $msgSeqNum <= $count; $msgSeqNum++) {
                            $uid = $this->storage()->getMsgIdBySeq($msgSeqNum, $this->selectedFolder);

                            if ($uid > $seqMax) {
                                $seqMax = $uid;
                            }
                        }
                    } else {
                        $seqMax = $count;
                    }
                } else {
                    $seqMax = (int) $items[1];
                }
            } else {
                if ($isUid) {
                    if ($items[0] == '*') {
                        $seqAll = true;
                    } else {
                        $seqMin = $seqMax = (int) $items[0];
                    }
                } else {
                    if ($items[0] == '*') {
                        $seqMin = 1;
                        $seqMax = $count;
                    } else {
                        $seqMin = $seqMax = (int) $items[0];
                    }
                }
            }

            if ($seqMin > $seqMax) {
                $tmp = $seqMin;
                $seqMin = $seqMax;
                $seqMax = $tmp;
            }

            $seqLen = $seqMax + 1 - $seqMin;

            if ($isUid) {
                if ($seqLen >= 1) {
                    for ($msgSeqNum = 1; $msgSeqNum <= $count; $msgSeqNum++) {
                        $uid = $this->storage()->getMsgIdBySeq($msgSeqNum, $this->selectedFolder);

                        if ($uid >= $seqMin && $uid <= $seqMax || $seqAll) {
                            $nums[] = $msgSeqNum;
                        }
                        if (count($nums) >= $seqLen && !$seqAll) {
                            break;
                        }
                    }
                }
            } else {
                if ($seqLen == 1) {
                    if ($seqMin > 0 && $seqMin <= $count) {
                        $nums[] = $seqMin;
                    }
                } elseif ($seqLen >= 2) {
                    for ($msgSeqNum = 1; $msgSeqNum <= $count; $msgSeqNum++) {
                        if ($msgSeqNum >= $seqMin && $msgSeqNum <= $seqMax) {
                            $nums[] = $msgSeqNum;
                        }

                        if (count($nums) >= $seqLen) {
                            break;
                        }
                    }
                }
            }

            $msgSeqNums = array_merge($msgSeqNums, $nums);
        }

        sort($msgSeqNums, SORT_NUMERIC);

        return $msgSeqNums;

    }


    /**
     * @return string
     */
    public function sendSelectedFolderInfos(): string
    {

        $nextId = $this->storage()->getNextMsgId();
        $count  = $this->storage()->getCountMailsByFolder($this->selectedFolder);
        $recent = $this->storage()->getCountMailsByFolder($this->selectedFolder, [ Storage::FLAG_RECENT ]);

        $firstUnseen = 0;
        for ($msgSeqNum = 1; $msgSeqNum <= $count; $msgSeqNum++) {
            $flags = $this->storage()->getFlagsBySeq($msgSeqNum, $this->selectedFolder);
            if (!in_array(Storage::FLAG_SEEN, $flags) && !$firstUnseen) {
                $firstUnseen = $msgSeqNum;
                break;
            }
        }

        $rv = '';
        foreach ($this->expunge as $msgSeqNum) {
            $rv .= $this->sendData('* ' . $msgSeqNum . ' EXPUNGE');
        }

        $rv .= $this->sendData('* ' . $count . ' EXISTS');
        $rv .= $this->sendData('* ' . $recent . ' RECENT');
        $rv .= $this->sendOk('Message ' . $firstUnseen . ' is first unseen', null, 'UNSEEN ' . $firstUnseen);

        #$rv .= $this->dataSend('* OK [UIDVALIDITY 3857529045] UIDs valid');

        if ($nextId) {
            $rv .= $this->sendOk('Predicted next UID', null, 'UIDNEXT ' . $nextId);
        }
        $availableFlags = [
//            Storage::FLAG_ANSWERED,
//            Storage::FLAG_FLAGGED,
//            Storage::FLAG_DELETED,
//            Storage::FLAG_SEEN,
//            Storage::FLAG_DRAFT,
        ];
        $rv .= $this->sendData('* FLAGS (' . join(' ', $availableFlags) . ')');

        $permanentFlags = sprintf('PERMANENTFLAGS (%s %s \*)', Storage::FLAG_DELETED, Storage::FLAG_SEEN);
        $rv .= $this->sendOk('Limited', null, $permanentFlags);

        return $rv;

    }


    /**
     * @param string $criteriaStr
     * @param bool $isUid
     * @return string
     * @throws \Exception
     */
    public function sendSearchRaw(string $criteriaStr, bool $isUid = false): string
    {
        $criteria = $this->getParenthesizedMessageList($criteriaStr);
        $criteria = $this->parseSearchKeys($criteria);

        $tree = new CriteriaTree($criteria);
        $tree->build();

        if (!$tree->getRootGate()) {
            return '';
        }

        $ids = [];
        $msgSeqNums = $this->createSequenceSet('*');
        foreach ($msgSeqNums as $msgSeqNum) {

            // $this->logger->debug('client ' . $this->id . ' check msg: ' . $msgSeqNum);

            $mail = $this->storage()->getMailBySeq($msgSeqNum, $this->selectedFolder);

            if ($mail) {
                /** @var Gate $rootGate */
                $rootGate = clone $tree->getRootGate();

                $uid = $this->storage()->getMsgIdBySeq($msgSeqNum, $this->selectedFolder);

                $add = $this->parseSearchMessage($mail, $msgSeqNum, $uid, $isUid, $rootGate);
                if ($add) {
                    if ($isUid) {
                        $ids[] = $uid;
                    } else {
                        // @NOTICE NOT_IMPLEMENTED
                        $ids[] = $msgSeqNum;
                    }
                }
            }
        }

        sort($ids);

        $rv = '';
        while ($ids) {
            $sendIds = array_slice($ids, 0, 30);
            $ids = array_slice($ids, 30);

            $rv .= $this->sendData('* SEARCH ' . join(' ', $sendIds) . '');
        }
        return $rv;
    }


    /**
     * @param array $list
     * @param int $posOffset
     * @param int $maxItems
     * @param bool $addAnd
     * @param int $level
     * @return array
     */
    public function parseSearchKeys(array $list, int &$posOffset = 0, int $maxItems = 0, bool $addAnd = true, int $level = 0): array
    {
        $len = count($list);
        $rv = [];

        if ($len <= 1) {
            return $list;
        }

        $itemsC = 0;
        $pos = 0;
        for ($pos = 0; $pos < $len; $pos++) {
            $orgpos = $pos;
            $item = $list[$pos];
            $itemWithArgs = '';

            $and = true;
            $offset = 0;

            if (is_array($item)) {
                $subPosOffset = 0;
                $itemWithArgs = [$this->parseSearchKeys($item, $subPosOffset, 0, true, $level + 1)];
            } else {
                $itemcmp = strtolower($item);
                if ($itemcmp == 'all'
                    || $itemcmp == 'answered'
                    || $itemcmp == 'deleted'
                    || $itemcmp == 'draft'
                    || $itemcmp == 'flagged'
                    || $itemcmp == 'new'
                    || $itemcmp == 'old'
                    || $itemcmp == 'recent'
                    || $itemcmp == 'seen'
                    || $itemcmp == 'unanswered'
                    || $itemcmp == 'undeleted'
                    || $itemcmp == 'undraft'
                    || $itemcmp == 'unflagged'
                    || $itemcmp == 'unseen'
                ) {
                    $itemWithArgs = $item;
                } elseif ($itemcmp == 'bcc'
                    || $itemcmp == 'before'
                    || $itemcmp == 'body'
                    || $itemcmp == 'cc'
                    || $itemcmp == 'from'
                    || $itemcmp == 'keyword'
                    || $itemcmp == 'larger'
                    || $itemcmp == 'on'
                    || $itemcmp == 'sentbefore'
                    || $itemcmp == 'senton'
                    || $itemcmp == 'sentsince'
                    || $itemcmp == 'since'
                    || $itemcmp == 'smaller'
                    || $itemcmp == 'subject'
                    || $itemcmp == 'text'
                    || $itemcmp == 'to'
                    || $itemcmp == 'uid'
                    || $itemcmp == 'unkeyword'
                ) {
                    $itemWithArgs = $item . ' ' . $list[$pos + 1];
                    $offset++;
                } elseif ($itemcmp == 'header') {
                    $itemWithArgs = $item . ' ' . $list[$pos + 1] . ' ' . $list[$pos + 2];
                    $offset += 2;
                } elseif ($itemcmp == 'or') {
                    $rest = array_slice($list, $pos + 1);
                    $subPosOffset = 0;
                    $sublist = $this->parseSearchKeys($rest, $subPosOffset, 2, false, $level + 1);
                    $itemWithArgs = [[$sublist[0], 'OR', $sublist[1]]];

                    $offset += $subPosOffset;
                } elseif ($itemcmp == 'and') {
                    $and = false;
                } elseif ($itemcmp == 'not') {
                    $rest = array_slice($list, $pos + 1);
                    $subPosOffset = 0;
                    $sublist = $this->parseSearchKeys($rest, $subPosOffset, 1, false, $level + 1);
                    $itemWithArgs = [$item, $sublist[0]];
                    $offset += $subPosOffset;
                } elseif (is_numeric($itemcmp)) {
                    $itemWithArgs = $item;
                }
            }

            if ($pos <= 0) {
                $and = false;
            }

            if ($addAnd && $and) {
                $rv[] = 'AND';
                //$and = false;
            }
            if ($itemWithArgs) {
                if (is_array($itemWithArgs)) {
                    $rv = array_merge($rv, $itemWithArgs);
                } else {
                    $rv[] = $itemWithArgs;
                }
            }

            $pos += $offset;
            $itemsC++;
            if ($maxItems && $itemsC >= $maxItems) {
                break;
            }
        }

        $posOffset = $pos + 1;

        return $rv;
    }


    /**
     * @param Parser $mail
     * @param int $messageSeqNum
     * @param int $messageUid
     * @param bool $isUid
     * @param $gate
     * @param int $level
     * @return bool
     * @throws \Exception
     */
    public function parseSearchMessage(Parser $mail, int $messageSeqNum, int $messageUid, bool $isUid, $gate, int $level = 1): bool
    {

        /** @var Obj[]|int[]|string[] $subGates */
        $subGates = [];

        if ($gate instanceof Gate) {
            if ($gate->getObj1()) {
                $subGates[] = $gate->getObj1();
            }
            if ($gate->getObj2()) {
                $subGates[] = $gate->getObj2();
            }
        } elseif ($gate instanceof Obj) {
            $val = $this->searchMessageCondition($mail, $messageSeqNum, $messageUid, $gate->getValue());
            $gate->setValue($val);
        }

        foreach ($subGates as $subGate) {
            if ($subGate instanceof AndGate) {
                $this->parseSearchMessage($mail, $messageSeqNum, $messageUid, $isUid, $subGate, $level + 1);
            } elseif ($subGate instanceof OrGate) {
                $this->parseSearchMessage($mail, $messageSeqNum, $messageUid, $isUid, $subGate, $level + 1);
            } elseif ($subGate instanceof NotGate) {
                $this->parseSearchMessage($mail, $messageSeqNum, $messageUid, $isUid, $subGate, $level + 1);
            } elseif ($subGate instanceof Obj) {
                $val = $this->searchMessageCondition($mail, $messageSeqNum, $messageUid, $subGate->getValue());
                $subGate->setValue($val);
            }
        }

        return $gate->getBool();

    }


    /**
     *
     * Checks a message by a given condition.
     *
     * @param Parser $mail
     * @param int $messageSeqNum
     * @param int $messageUid
     * @param string $searchKey
     * @return bool
     * @throws \Exception
     */
    public function searchMessageCondition(Parser $mail, int $messageSeqNum, int $messageUid, string $searchKey): bool
    {

        $items = preg_split('/ /', $searchKey, 3);
        $itemcmp = strtolower($items[0]);

        $flags = $this->storage()->getFlagsById($messageUid);

        $rv = false;
        switch ($itemcmp) {
            case 'all':
                return true;

            case 'answered':
                return in_array(Storage::FLAG_ANSWERED, $flags);

            case 'bcc':
                $searchStr = strtolower($items[1]);
                $bccAddressList = MailHelper::parseEmailsFromHeader( $mail->getHeader('bcc'), true );
                if (count($bccAddressList)) {
                    foreach ($bccAddressList as $bcc) {
                        return strpos( strtolower( $bcc ), $searchStr) !== false;
                    }
                }
                break;

            case 'before':
                // @NOTICE NOT_IMPLEMENTED
                break;

            case 'body':
            case 'text':
                $searchStr = strtolower($items[1]);
                return strpos( strtolower( $mail->getMessageBody() ), $searchStr) !== false;

            case 'cc':
                $searchStr = strtolower($items[1]);
                $ccAddressList = MailHelper::parseEmailsFromHeader( $mail->getHeader('cc'), true );
                if (count($ccAddressList)) {
                    foreach ($ccAddressList as $from) {
                        return strpos(strtolower($from), $searchStr) !== false;
                    }
                }
                break;

            case 'deleted':
                return in_array(Storage::FLAG_DELETED, $flags);

            case 'draft':
                return in_array(Storage::FLAG_DRAFT, $flags);

            case 'flagged':
                return in_array(Storage::FLAG_FLAGGED, $flags);

            case 'from':
                $searchStr = strtolower($items[1]);
                $fromAddressList = MailHelper::parseEmailsFromHeader( $mail->getHeader('from'), true );
                if (count($fromAddressList)) {
                    foreach ($fromAddressList as $from) {
                        return strpos(strtolower($from), $searchStr) !== false;
                    }
                }
                break;

            case 'header':
                $searchStr = strtolower($items[2]);
                $fieldName = $items[1];
                $header = $mail->getHeaders()->get($fieldName);
                $val = $header->getFieldValue();
                return strpos(strtolower($val), $searchStr) !== false;

            case 'keyword':
                // @NOTICE NOT_IMPLEMENTED
                break;

            case 'larger':
                return strlen( $mail->getMessageBody() ) > (int) $items[1];

            case 'new':
                return in_array(Storage::FLAG_RECENT, $flags) && !in_array(Storage::FLAG_SEEN, $flags);

            case 'old':
                return !in_array(Storage::FLAG_RECENT, $flags);

            case 'on':
                $checkDate = new \DateTime($items[1]);
                $dateStr = $mail->getHeader('date');
                if( !$dateStr ) break;

                $messageDate = new \DateTime( $dateStr );
                return $messageDate->format('Y-m-d') == $checkDate->format('Y-m-d');

            case 'recent':
                return in_array(Storage::FLAG_RECENT, $flags);

            case 'seen':
                return in_array(Storage::FLAG_SEEN, $flags);

            case 'sentbefore':

                $checkDate = new \DateTime( $items[1] );
                $dateStr = $mail->getHeader('date');
                if( !$dateStr ) break;

                $messageDate = new \DateTime( $dateStr );
                return $messageDate < $checkDate;

            case 'senton':
                $checkDate = new \DateTime( $items[1] );
                $dateStr = $mail->getHeader('date');
                if( !$dateStr ) break;

                $messageDate = new \DateTime( $dateStr );
                return $messageDate == $checkDate;

            case 'sentsince':
                $checkDate = new \DateTime( $items[1] );
                $dateStr = $mail->getHeader('date');
                if( !$dateStr ) break;

                $messageDate = new \DateTime( $dateStr );
                return $messageDate >= $checkDate;

            case 'since':
                // @NOTICE NOT_IMPLEMENTED
                break;

            case 'smaller':
                return strlen($mail->getMessageBody()) < (int)$items[1];

            case 'subject':
                if (isset($items[2])) {
                    $items[1] .= ' ' . $items[2];
                    unset($items[2]);
                }
                $searchStr = strtolower($items[1]);
                return strpos(strtolower( $mail->getHeader('subject') ), $searchStr) !== false;

            case 'to':
                $searchStr = strtolower($items[1]);
                $toAddressList = MailHelper::parseEmailsFromHeader( $mail->getHeader('to'), true );
                if (count($toAddressList)) {
                    foreach ($toAddressList as $to) {
                        return strpos(strtolower($to->getEmail()), $searchStr) !== false;
                    }
                }
                break;

            case 'uid':
                $searchId = (int) $items[1];
                return $searchId == $messageUid;

            case 'unanswered':
                return !in_array(Storage::FLAG_ANSWERED, $flags);

            case 'undeleted':
                return !in_array(Storage::FLAG_DELETED, $flags);

            case 'undraft':
                return !in_array(Storage::FLAG_DRAFT, $flags);

            case 'unflagged':
                return !in_array(Storage::FLAG_FLAGGED, $flags);

            case 'unkeyword':
                // @NOTICE NOT_IMPLEMENTED
                break;

            case 'unseen':
                return !in_array(Storage::FLAG_SEEN, $flags);

            default:
                if (is_numeric($itemcmp)) {
                    $searchId = (int) $itemcmp;
                    return $searchId == $messageSeqNum;
                }
        }

        return false;

    }


    /**
     * @param string $tag
     * @param string $criteriaStr
     * @return string
     */
    public function sendSearch(string $tag, string $criteriaStr): string
    {
        $rv = '';

        try {
            $rv .= $this->sendSearchRaw($criteriaStr, false);
        } catch (\Exception $e) {}

        $rv .= $this->sendOk('SEARCH completed', $tag);

        return $rv;
    }


    /**
     * @param string $tag
     * @return string
     */
    public function sendClose(string $tag): string
    {
        $this->sendExpungeRaw();
        $this->selectedFolder = '';
        return $this->sendOk('CLOSE completed', $tag);
    }

    public function sendExpungeRaw(): array
    {

        $msgSeqNumsExpunge = [];
        $expungeDiff = 0;

        /** @var int[] $msgSeqNums */
        $msgSeqNums = $this->createSequenceSet('*');

        foreach ($msgSeqNums as $msgSeqNum) {
            $expungeSeqNum = $msgSeqNum - $expungeDiff;

            $flags = $this->storage()->getFlagsBySeq($expungeSeqNum, $this->selectedFolder);
            if (in_array(Storage::FLAG_DELETED, $flags)) {
                $this->storage()->removeMailBySeq($expungeSeqNum, $this->selectedFolder);
                $msgSeqNumsExpunge[] = $expungeSeqNum;
                $expungeDiff++;
            }
        }

        return $msgSeqNumsExpunge;
    }


    /**
     * Send EXPUNGE Message
     *
     * @param string $tag
     * @return string
     */
    public function sendExpunge(string $tag): string
    {
        $rv = '';

        $msgSeqNumsExpunge = $this->sendExpungeRaw();
        foreach ($msgSeqNumsExpunge as $msgSeqNum) {
            $rv .= $this->sendData('* ' . $msgSeqNum . ' EXPUNGE');
        }
        $rv .= $this->sendOk('EXPUNGE completed', $tag);

        $this->expunge = [];

        return $rv;
    }

    /**
     * Send CHECK Message
     *
     * @param string $tag
     * @return string
     */
    private function sendCheck(string $tag): string
    {
        if ($this->selectedFolder) {
            return $this->sendOk('CHECK completed', $tag);
        } else {
            return $this->sendNo('No mailbox selected.', $tag);
        }
    }

}