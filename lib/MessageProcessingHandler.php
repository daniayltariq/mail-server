<?php

namespace PBMail;

use PBMail\Helpers\DbHelper;
use PBMail\Helpers\MailHelper;
use PBMail\Providers\Facebook;
use PBMail\Providers\Netlify;
use PBMail\Providers\Shopify;

class MessageProcessingHandler {

    /**
     * Detects email sender.
     * If emails comes to our domains then extract the verification code and save email to db.
     * If emails goes from our domains then relay the email.
     * If both sender and receiver is from our domains, then save directly to db
     *
     * @param String $from
     * @param String $fromName
     * @param String $to
     * @param String $subject
     * @param String $body
     * @param String $username
     */
    public function processEmail($from, $fromName, $to, $subject, $body, $username)
    {
        $dbHelper = DbHelper::getInstance();
        $fromDomain = $dbHelper->findDomainShort($from);
        $toDomain = $dbHelper->findDomainShort($to);

        if($fromDomain){
            // Email is sent from our domain
            // First of all check if user has permission to send emails from this domain.
            // Continue only when user has permission.
            if($dbHelper->checkUserPermission($username, $from)){
                if($toDomain){
                    // Email is sent to our domain.
                    // In this case save email directly to db
                    $this->store($from, $to, $subject, $body,'');
                }else{
                    // Email is sent to another domain.
                    // Relay email in this case.
                    $sent = MailHelper::sendMail($from, $fromName, $to, $subject, $body);
                    if(!$sent){
                        // An error occurred
                    }else{
                        // Mail sent
                        $this->store($from, $to, $subject, $body, '');
                    }
                }
            }
        }else{
            // Email is not sent from our domains.
            // This is an incoming email.
            // Check if email comes to one of our domains. If receiver is not one of our domains then dont to anything.
            // If receiver is one of our domains then process the email for possible verification code and then save it.
            if($toDomain){
                $code = $this->extractVerificationCode($from, $to, $subject, $body);
                $this->store($from, $to, $subject, $body, $code);
            }
        }
    }

    /**
     * If email has a potential to contain verification code, try to extract the code.
     *
     * @param $from
     * @param $to
     * @param $subject
     * @param $body
     * @return mixed|Providers\Event|string
     */
    private function extractVerificationCode($from, $to, $subject, $body){
        try {
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
            return $code;
        }catch (\Exception $e){
            return '';
        }
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
            $dbHelper->disconnect();
            return true;
        }catch(\Exception $e){
            return false;
        }
    }
}