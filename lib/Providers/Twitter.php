<?php

namespace PBMail\Providers;


class Twitter implements ProviderInterface {

    /**
     * Finds and return verification code from facebook.
     *
     * @param string $from
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return Event|string
     */
    public static function process($from, $to, $subject, $body)
    {
        try {
            if (strpos($subject, 'is your Twitter verification code') !== false) {
                // Subject contains verification code in the following format:
                // subject: 530441 is your Twitter verification code
                return str_replace(" is your Twitter verification code", "", $subject);
            }
        } catch (\Throwable $th) {
            return '';
        }
    }
}