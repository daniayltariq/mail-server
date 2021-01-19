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
     * @param $inReplyTo
     * @param $references
     * @return bool
     */
    public static function sendMail($from, $fromName, $to, $cc, $bcc, $subject, $htmlBody, $inReplyTo, $references){
        try{
            $mail = new PHPMailer(true);
            foreach ($to as $to_addr) {
                $mail->addAddress($to_addr);
            }
            foreach ($cc as $cc_addr) {
                $mail->addCC($cc_addr);
            }
            foreach ($bcc as $bcc_addr) {
                $mail->addBCC($bcc_addr);
            }
            
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->isHTML(true);
            // get domain from email
            $domain = explode('@', $from)[1];
            $mail->addReplyTo($from, $fromName);
            $mail->setFrom($from, $fromName);

            // If $inReplyTo and $references are provided, this means email will be sent as a reply to another email.
            // Therefore, add In-Reply-To and References headers to the mail in this case.
            // In-Reply-To and References headers will contain the same value,
            // but we use two different variables to have more control over them just in case.
            if(isset($inReplyTo) && isset($references) && $inReplyTo != '' && $references != ''){
                $mail->addCustomHeader('In-Reply-To', $inReplyTo);
                $mail->addCustomHeader('References', $references);
            }

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
            echo json_encode(sprintf('Email sent from <%s> to <%s>.', $from, json_encode($to))).PHP_EOL;
            return $mail->getLastMessageID();
        }catch (\Exception $e){
            echo json_encode(sprintf('Email is not sent from <%s> to <%s>.', $from, json_encode($to))).PHP_EOL;
            return false;
        }
    }


    /**
     *
     * Returns an array of each item containing an
     * array of email address' components.
     *
     * Emails that have
     * no corresponding name, the email name will
     * be used.
     *
     * Array Shape:
     *
     *     [
     *         [email, name],
     *         [email, name],
     *         ...
     *     ]
     *
     * If Address only, then the shape will become like this:
     *
     *    [ email, email, email, ... ]
     *
     *
     * Example 1: `name@example.com --> [ name@example.com, name ]`
     *
     * Example 2: `My Name <name@example.com> --> [ name@example.com, My Name ]`
     *
     * @param $headerStr
     * @param bool $addressOnly Return the address only ignore name
     * @param bool $single Return only a single array, not an array of array.
     * @return array|string
     */
    public static function parseEmailsFromHeader($headerStr, $addressOnly = false, $single = false)
    {
        $result = [];
        $items = explode( ',', $headerStr );
        foreach ( $items as $item ){
            $matches = [];
            if( strpos( $item, '<' ) !== false ){
                preg_match('/([^<.]*)<(.*@.*)>/', $item, $matches);
                $result[] = $addressOnly ? $matches[2] : [ $matches[2], trim( $matches[1] ) ];
            }else{
                preg_match('/<?((.*)@.*)>?/', $item, $matches);
                $result[] = $addressOnly ? $matches[1] : [ $matches[1], $matches[2] ];
            }

            if( $single ) break;

        }

        return $single ? reset( $result ) : $result;
    }

}