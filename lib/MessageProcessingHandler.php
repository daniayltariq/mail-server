<?php

namespace PBMail;

use PBMail\Helpers\DbHelper;
use PBMail\Providers\Facebook;
use PBMail\Providers\Shopify;

class MessageProcessingHandler {

    /**
     * Detects email sender, extracts the verification code and saves email to db.
     *
     * @param String $from
     * @param String $to
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

    /**
     * Uses singleton DbHelper to save emails to db.
     *
     * @param String $from
     * @param String $to
     * @param String $subject
     * @param String $body
     * @param String $code
     */
    private function store($from, $to, $subject, $body, $code)
    {
        // We call the singleton object. Because we cannot create an instance explicitly.
        $dbHelper = DbHelper::getInstance();
        $dbHelper->storeEmail($from, $to, $subject, $body, $code);
    }
}