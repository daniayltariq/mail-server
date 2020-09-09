<?php

namespace PBMail\Providers;

use PHPHtmlParser\Dom;

class Facebook implements ProviderInterface {

    /**
     * @param String $from
     * @param String $subject
     * @param String $body
     */
    public static function process($from, $to, $subject, $body)
    {
        try {
            if (strpos($subject, 'Facebook confirmation code') !== false) {
                $dom = new Dom;
                $dom->loadStr($body);
                
                $code = $dom->find('.mb_text td')[0]->innerText;
                
                return $code;
            }
        } catch (\Throwable $th) { }
        return '';
    }
}