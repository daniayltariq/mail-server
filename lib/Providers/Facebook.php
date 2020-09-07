<?php

namespace PBMail\Providers;

use PHPHtmlParser\Dom;

class Facebook implements ProviderInterface {

    /**
     * @param String $from
     * @param String $subject
     * @param String $body
     */
    public static function process($from, $subject, $body)
    {
        $dom = new Dom;
        $dom->loadStr($body);
        
        $code = $dom->find('.mt_text td')[0]->innerText;
        
        Facebook::webhook($code);
    }

    public static function webhook($data)
    {
        echo 'Send WebHook with code :' . $data;
    }
}