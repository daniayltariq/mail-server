<?php


namespace PBMail\Helpers;


use PHPMailer\PHPMailer\PHPMailer;

class MailHelper
{

    /**
     * Send signed emails.
     *
     * @param $from
     * @param $fromName
     * @param $to
     * @param $subject
     * @param $htmlBody
     * @return bool
     */
    public static function sendMail($from, $fromName, $to, $subject, $htmlBody){
        try{
            $mail = new PHPMailer(true);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->isHTML(true);
            // get domain from email
            $domain = explode('@', $from)[1];
            $mail->setFrom($from, $fromName);
            $mail->addReplyTo($from, $fromName);
            //This should be the same as the domain of your From address
            $mail->DKIM_domain = $domain;
            //Find private key from domains table
            $dbHelper = DbHelper::getInstance();
            $domainDKIM = $dbHelper->findDomainDKIM($domain);
            if(!$domainDKIM){
                echo json_encode(sprintf('DKIM RSA key not found for <%s>.', $from)).PHP_EOL;
                return false;
            }
            $mail->DKIM_private_string = $domainDKIM;
            //Set the selector. we are setting selector as 'default'
            $mail->DKIM_selector = 'default';
            //The identity you're signing as - usually your From address
            $mail->DKIM_identity = $from;
            //Suppress listing signed header fields in signature, defaults to true for debugging purpose
            $mail->DKIM_copyHeaderFields = false;
            $mail->send();
            echo json_encode(sprintf('Email sent from <%s> to <%s>.', $from, $to)).PHP_EOL;
            return true;
        }catch (\Exception $e){
            echo json_encode(sprintf('Email is not sent from <%s> to <%s>.', $from, $to)).PHP_EOL;
            return false;
        }
    }

}