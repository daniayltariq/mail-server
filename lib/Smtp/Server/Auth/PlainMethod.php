<?php

namespace PBMail\Smtp\Server\Auth;

/**
 * Class PlainMethod
 * @package Smalot\Smtp\Server\Auth
 */
class PlainMethod implements MethodInterface
{
    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * PlainMethod constructor.
     */
    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function getType()
    {
        return 'PLAIN';
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param string $token
     * @return $this
     */
    public function decodeToken($token)
    {
        $parts = explode("\000", base64_decode($token));

        $this->username = $parts[1];
        $this->password = $parts[2];

        return $this;
    }

    /**
     * @param string $password
     * @return bool
     */
    public function validateIdentity($password)
    {
        return $password == $this->password;
    }
}
