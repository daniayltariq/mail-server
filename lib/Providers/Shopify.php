<?php

namespace PBMail\Providers;

use PHPHtmlParser\Dom;

class Shopify implements ProviderInterface {

    /**
     * Find verification code from shopify email.
     * Returns the very first link found.
     *
     * @param string $from
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return mixed|Event|string
     */
    public static function process($from, $to, $subject, $body)
    {
        try{
            $dom = new Dom;
            $dom->loadStr($body);
            // returns first link
            return $dom->find('a')[0];
        }catch (\Throwable $th){
            return '';
        }
    }
}