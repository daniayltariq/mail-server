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

        var_dump("CMD: $line\n");

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

                    $this->setState(static::STATE_AUTH_STEP, 1);
                    $this->setState(static::STATE_AUTH_TAG, $tag);
                    $this->setState(static::STATE_AUTH_METHOD, $authMethod);

                    return $this->sendAuthenticate();
                }

                // TODO: Support any other authentication methods.


        }

        return '';

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


    public function sendHello()
    {
        $this->sendOk('IMAP4rev1 Service Ready');
    }

    /**
     * @param string $text
     * @param string|null $tag
     * @param string|null $code
     * @return string
     */
    public function sendOk(string $text, string $tag = null, string $code = null): string
    {
        if ($tag === null) {
            $tag = '*';
        }
        return $this->sendData($tag . ' OK' . ($code ? ' [' . $code . ']' : '') . ' ' . $text);
    }


    /**
     * @param string $msg
     * @return string
     */
    public function sendData(string $msg): string
    {

        $output = $msg . static::DELIMITER;

//        $tmp = $msg;
//        $tmp = str_replace("\r", '', $tmp);
//        $tmp = str_replace("\n", '\\n', $tmp);

        $this->write($output);

        return $output;

    }


    /**
     * @param string $address
     * @return string
     */
    protected function parseAddress($address): string
    {
        return trim(substr($address, 0, strrpos($address, ':')), '[]');
    }


    /**
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->parseAddress(stream_socket_get_name($this->stream, true));
    }


    /**
     * Mutate the STATE.
     *
     * @param string $name
     * @param mixed $value
     */
    public function setState(string $name, $value)
    {
        $this->state[$name] = $value;
    }


    /**
     * Get the STATE.
     *
     * @param string $name
     * @return mixed|null
     */
    public function getState(string $name)
    {
        if (array_key_exists($name, $this->state)) {
            return $this->state[$name];
        }
        return null;
    }


    /**
     * Get the STATE.
     *
     * @param string $name
     * @return mixed|null
     */
    public function isState(string $name)
    {
        if (array_key_exists($name, $this->state)) {
            return $this->state[$name];
        }
        return null;
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
    public function sendNoop(string $tag)
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
    public function sendAuthenticate()
    {

        if( $this->isState() )

        if ($this->getState(static::STATE_AUTH_STEP) == 1) {
            return $this->sendData('+');
        } elseif ($this->getStatus('authStep') == 2) {
            $this->setStatus('hasAuth', true);
            $this->setStatus('authStep', 0);

            $text = sprintf('%s authentication successful', $this->getStatus('authMechanism'));
            return $this->sendOk($text, $this->getStatus('authTag'));
        }

        return '';
        
    }
}