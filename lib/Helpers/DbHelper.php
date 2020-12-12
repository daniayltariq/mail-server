<?php

namespace PBMail\Helpers;


class DbHelper
{
    // $instance will hold the instance of DbHelper (Singleton)
    private static $instance = null;

    // local variables
    protected $config;
    protected $connection;

    /**
     * private DbHelper constructor.
     * It cannot be created outside.
     * Constructor creates db connection and with singleton design pattern our goal is to keep only one connection.
     *
     */
    private function __construct(){
        // Singleton pattern: private constructor
        $this->config = [
            'host' => getenv('DB_HOST'),
            'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'emails_table' => getenv('DB_EMAILS_TABLE'),
            'domains_table' => getenv('DB_DOMAINS_TABLE'),
            'webhook_api' => getenv('WEBHOOK_API'),
        ];
    }

    /**
     * connect to db.
     */
    public function connect(){
        $this->connection = new \PDO(
            sprintf("mysql:host=%s;dbname=%s", $this->config['host'], $this->config['database']),
            $this->config['username'],
            $this->config['password']
        );
    }

    /**
     * close connection
     */
    public function closeConnection(){
        $this->connection == null;
    }

    /**
     * public static function to create and return DbHelper instance if it does not exist.
     * If the instance exists already then it will return without creating new instance.
     *
     * @return DbHelper|null
     */
    public static function getInstance(){
        if(!isset(self::$instance)){
            self::$instance = new DbHelper();
        }
        return self::$instance;
    }

    /**
     * Save incoming email to emails table.
     * This function finds the domain id and then saves the email by associating with domain id.
     * If it cannot get domain id, or an error occurs, it returns false.
     *
     * @param $from
     * @param $to
     * @param $subject
     * @param $body
     * @param $code
     * @return false|string
     */
    public function storeEmail($from, $to, $subject, $body, $code){
        // Users are associated with domains, and all emails are associated with domain.
        // Therefore, we need to find out the related domain for the incoming email.
        $domainId = $this->_findDomainFromIncomingEmail($to);
        if(!$domainId){
            // Early return:
            // If domain id cannot be found there is no need to continue. Because, domain_id in emails table
            // cannot be null.
            return false;
        }
        try{
            // Save email to emails table
            $preparedStatement = $this->connection->prepare(
                sprintf(
                    "INSERT INTO %s (email_from, email_to, subject, body, code, domain_id, created_at, updated_at)
                    VALUES (:from, :to, :subject, :body, :code, :domain, :created, :updated)",
                    $this->config['emails_table']
                )
            );
            $preparedStatement->execute([
                'from' => strtolower($from),
                'to' => strtolower($to),
                'subject' => $subject,
                'body' => $body,
                'code' => $code,
                'domain' => $domainId,
                'created' => date("Y-m-d H:i:s"),
                'updated' => date("Y-m-d H:i:s"),
            ]);
            $emailId = $this->connection->lastInsertId();
            return $this->_triggerWebhook($emailId);
        }catch (\Throwable $th){
            return false;
        }
    }

    /**
     * Find the related domain name from incoming email's target email address ($to) and return domain id.
     * Domain id will be used to associate emails to domains.
     * This function returns false if an error occurs or if domain name cannot be found.
     *
     * @param $to
     * @return false
     */
    private function _findDomainFromIncomingEmail($to){
        try{
            // select only id because only id is needed.
            // set limit to 1 to make it more efficient.
            $preparedStatement = $this->connection->prepare(
                sprintf("SELECT id FROM %s WHERE name=:name LIMIT 1", $this->config['domains_table'])
            );
            // explode $to and get domain name
            $preparedStatement->execute([
                'name' => strtolower(explode('@', $to)[1])
            ]);
            $domain = $preparedStatement->fetch();
            if(!$domain){
                return false;
            }
            return $domain['id'];
        }catch (\Throwable $th){
            return false;
        }
    }

    /**
     * Notify web server about new email.
     *
     * @param $emailId
     * @return bool
     */
    private function _triggerWebhook($emailId){
        try{
            $result = file_get_contents($this->config['webhook_api'].'?id='.$emailId);
            return true;
        }catch (\Exception $e){
            return false;
        }
    }

}
