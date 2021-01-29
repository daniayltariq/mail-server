<?php

namespace PBMail;

use PBMail\Helpers\DbHelper;
use PBMail\Helpers\MailHelper;
use PBMail\Providers\Facebook;
use PBMail\Providers\Netlify;
use PBMail\Providers\Shopify;
use PBMail\Providers\Twitter;

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
     * @param String $messageId
     * @param String $inReplyTo
     * @param String $references
     * @param string $rawEmail
     */
    public function processEmail($from, $fromName, $to, $cc, $bcc, $subject, $body, $username, $messageId, $inReplyTo, $references, $rawEmail = '', $mail_attachments = null)
    {
        $fromDomain  = false;
        $toDomain = false;
        $dbHelper = DbHelper::getInstance();
        $fromDomain = $dbHelper->findDomainShort($from);

        $outgoingEmails = [];
        $incomingEmails = [];

        foreach ($to as $to_single_domain) {

            $toDomain = $dbHelper->findDomainShort($to_single_domain);
            if($toDomain){
               $incomingEmails[] = $to_single_domain;
            }else{
                $outgoingEmails[] = $to_single_domain;
            }
        }

        if($fromDomain){
            // Email is sent from our domain
            // First of all check if user has permission to send emails from this domain.
            // Continue only when user has permission.
            if($dbHelper->checkUserPermission($username, $from)){

                if($incomingEmails){
                    // Email is sent to our domain.
                    // In this case save email directly to db
                    $this->store($from, $incomingEmails, $cc, $bcc, $subject, $body,'', $messageId, $inReplyTo, $references, $rawEmail, $mail_attachments);
                }
                
                if($outgoingEmails){

                    // Email is sent to another domain.
                    // Relay email in this case.
                    // MailHelper:sendMail will return Message-ID, we should save this to emails table.
                    // Message-ID will be used to identify replies to emails we sent.
                    $sent = MailHelper::sendMail($from, $fromName, $outgoingEmails, $cc, $bcc, $subject, $body, $inReplyTo, $references);
                    if(!$sent){
                        echo json_encode('EMAIL IS NOT SENT').PHP_EOL;
                        // An error occurred
                    }else{
                        echo json_encode('EMAIL IS SENT').PHP_EOL;
                        // Mail sent
                        $this->store($from, $incomingEmails, $cc, $bcc, $subject, $body, '', $sent, $inReplyTo, $references, $rawEmail, $mail_attachments);
                        echo json_encode('EMAIL IS SAVED').PHP_EOL;
                    }

                }
            }
        }else{
            // Email is not sent from our domains.
            // This is an incoming email.
            // Check if email comes to one of our domains. If receiver is not one of our domains then dont to anything.
            // If receiver is one of our domains then process the email for possible verification code and then save it.
            if($incomingEmails){
                $code = $this->extractVerificationCode($from, $incomingEmails, $subject, $body);
                $this->store($from, $to, $cc, $bcc, $subject, $body, $code, $messageId, $inReplyTo, $references, $rawEmail, $mail_attachments);
                echo json_encode('EMAIL SAVED').PHP_EOL;
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
            if($from == 'verify@twitter.com'){
                $code = Twitter::process($from, $to, $subject, $body);
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
     * @param String $messageId
     * @param String $inReplyTo
     * @param String $references
     * @param string $rawEmail
     * @return bool
     */

    private function store($from, $to, $cc, $bcc, $subject, $body, $code, $messageId, $inReplyTo, $references, $rawEmail = '',$mail_attachments)
    {
        try{
            // We call the singleton object. Because we cannot create an instance explicitly.
            $dbHelper = DbHelper::getInstance();
            $dbHelper->connect();
            $dbHelper->storeEmail($from, $to, $cc, $bcc, $subject, $body, $code, $messageId, $inReplyTo, $references, $rawEmail,$mail_attachments);
            $dbHelper->disconnect();
            return true;
        }catch(\Exception $e){
            echo json_encode('An error occurred while saving email to db').PHP_EOL;
            return false;
        }
    }
}