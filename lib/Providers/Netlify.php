<?php

namespace PBMail\Providers;


class Netlify implements ProviderInterface {

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
            if (strpos($subject, 'verify your email') !== false) {
                $code = null;
                preg_match('/verify_token=(.*?)&/', $body,$code);
                return $code[1];
            }
        } catch (\Throwable $th) {
            return '';
        }
    }
}