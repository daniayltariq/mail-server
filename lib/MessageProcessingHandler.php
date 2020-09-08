<?php

namespace PBMail;

use PBMail\Providers\Facebook;
use PBMail\Providers\Shopify;

class MessageProcessingHandler {

    /**
     * @param String $from
     * @param String $subject
     * @param String $body
     */
    public function processEmail($from, $to, $subject, $body)
    {
        $code = '';
        $from_domain = explode('@', $from)[1];
        switch ($from_domain) {
            case 'facebookmail.com':
                $code = Facebook::process($from, $to, $subject, $body);
                break;
            case 'shopify.com':
                $code = Shopify::process($from, $to, $subject, $body);
                break;
            default:
                # code...
                break;
        }

        $this->store($from, $to, $subject, $body, $code);
    }

    private function store($from, $to, $subject, $body, $code)
    {
        $postdata = http_build_query(
            array(
                'key' => 'pass',
                'from' => $from,
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'code' => $code
            )
        );
        
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postdata
            )
        );
        
        $context  = stream_context_create($opts);
        
        $result = file_get_contents(getenv('PBMAIL_DBAPI'), false, $context);
    }
}