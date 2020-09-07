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
    public function processEmail($from, $subject, $body)
    {
        $from_domain = explode('@', $from)[1];
        switch ($from_domain) {
            case 'facebookmail.com':
                Facebook::process($from, $subject, $body);
                break;
            case 'shopify.com':
                Shopify::process($from, $subject, $body);
                break;
            default:
                # code...
                break;
        }
    }
}