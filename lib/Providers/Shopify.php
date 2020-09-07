<?php

namespace PBMail\Providers;

use PHPHtmlParser\Dom;

class Shopify implements ProviderInterface {

    /**
     * @param String $from
     * @param String $subject
     * @param String $body
     */
    public static function process($from, $subject, $body)
    {
        $dom = new Dom;
        $dom->loadStr($body);
        $a = $dom->find('a')[0];
        echo 'Detected Link :' . $a;
    }
}