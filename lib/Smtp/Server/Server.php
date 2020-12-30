<?php


namespace PBMail\Smtp\Server;

use PBMail\Helpers\DbHelper;
use React\EventLoop\LoopInterface;
use PBMail\Smtp\Server\Auth\MethodInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class Server
 * @package Smalot\Smtp\Server
 */
class Server extends \React\Socket\Server
{
    /**
     * @var int
     */
    public $recipientLimit = 100;

    /**
     * @var int
     */
    public $bannerDelay = 0;

    /**
     * @var array
     */
    public $authMethods = [];

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
     */
    public function __construct(LoopInterface $loop, EventDispatcherInterface $dispatcher)
    {
        parent::__construct($loop);

        // We need to save $loop here since it is private for some reason.
        $this->loop = $loop;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param resource $socket
     * @return Connection
     */
    public function createConnection($socket)
    {
        $connection = new Connection($socket, $this->loop, $this, $this->dispatcher);

        $connection->recipientLimit = $this->recipientLimit;
        $connection->bannerDelay = $this->bannerDelay;
        $connection->authMethods = $this->authMethods;

        return $connection;
    }

    /**
     * @param Connection $connection
     * @param \Smalot\Smtp\Server\Auth\MethodInterface $method
     * @return bool
     */
    public function checkAuth(Connection $connection, MethodInterface $method)
    {
        // Find out auth type
        $authType = $method->getType();
        // If auth type is LOGIN then user must send username (username is email) and password with third party applications.
        // If auth type is CRAM-MD5 then this is used in requests
        if($authType == Connection::AUTH_METHOD_LOGIN){
            // get provided username and password
            $providedUsername = $method->getUsername();
            $providedPassword = $method->getPassword();
            // find the password hash for the username
            $userPassword = $this->getPasswordForUsername($providedUsername);
            // validate the username and password
            return $this->validateIdentityForLogin($providedPassword, $userPassword);
        }elseif($authType == Connection::AUTH_METHOD_CRAM_MD5){
            // get provided username. password is handled by CramMd5Method
            $providedUsername = $method->getUsername();
            // find the password hash for the username
            $userPassword = $this->getPasswordForUsername($providedUsername);
            // validate the login
            return $method->validateIdentity($userPassword);
        }else{
            return true;
        }
    }

    /**
     * @param string $username
     * @return string
     */
    protected function getPasswordForUsername($username)
    {
        try{
            // We call the singleton object. Because we cannot create an instance explicitly.
            $dbHelper = DbHelper::getInstance();
            $dbHelper->connect();
            $password = $dbHelper->getPasswordForUsername($username);
            $dbHelper->disconnect();
            return $password;
        }catch(\Exception $e){
            return false;
        }
    }

    /**
     * Validate username (email) and password for users that are trying to send email via smtp.
     * Users must use LOGIN auth type where they authenticate with username and password.
     *
     * @param $providedPassword
     * @param $userPasswordHash
     * @return bool
     */
    public function validateIdentityForLogin($providedPassword, $userPasswordHash)
    {
        return password_verify($providedPassword, $userPasswordHash);
    }
}
