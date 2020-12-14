<?php

namespace PBMail;

use PBMail\Helpers\DbHelper;
use PBMail\Providers\Facebook;
use PBMail\Providers\Netlify;
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
        // Check if emails comes from addresses that platforms use tho send verification email not to run
        // extra unnecessary processes.
        if($from == 'team@netlify.com'){
            $code = Netlify::process($from, $to, $subject, $body);
        }
        $from_domain = explode('@', $from)[1];
        switch ($from_domain) {
            case 'netlify.com':
                $code = Netlify::process($from, $to, $subject, $body);
                break;
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
        try{
            // We call the singleton object. Because we cannot create an instance explicitly.
            $dbHelper = DbHelper::getInstance();
            $dbHelper->connect();
            $dbHelper->storeEmail($from, $to, $subject, $body, $code);
            $dbHelper->closeConnection();
        }catch(\Exception $e){

        }
    }
}