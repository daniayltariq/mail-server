<?php

namespace PBMail\Providers;

use PHPHtmlParser\Dom;

class Facebook implements ProviderInterface {

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
            if (strpos($subject, 'Facebook confirmation code') !== false) {
                $dom = new Dom;
                $dom->loadStr($body);
                return $dom->find('.mb_text td')[0]->innerText;
            }
        } catch (\Throwable $th) {
            return '';
        }
    }
}