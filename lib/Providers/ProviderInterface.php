<?php

namespace PBMail\Providers;

interface ProviderInterface
{
    /**
     * Process an incoming email
     *
     * @param string     $from      The email address that sent the email
     * @param string     $to        The email address that received the email
     * @param string     $subject   The subject of the email
     * @param string     $body      The html of the body of the email
     *
     * @return Event
     */
    public static function process($from, $to, $subject, $body);

}
