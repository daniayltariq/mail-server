<?php

namespace PBMail\Helpers;

class DbHelper
{
    // $instance will hold the instance of DbHelper (Singleton)
    private static $instance = null;

    // local variables
    protected $config;

    /** @var \PDO|null */
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
            'users_table' => getenv('DB_USERS_TABLE'),
            'emails_table' => getenv('DB_EMAILS_TABLE'),
            'domains_table' => getenv('DB_DOMAINS_TABLE'),
            'account_domain_emails_table' => getenv('DB_ACCOUNT_DOMAIN_EMAILS_TABLE'),
            'webhook_api' => getenv('WEBHOOK_API'),
        ];
    }

    /**
     * Get the underlying connection.
     * @return \PDO
     * @throws \Exception
     */
    public function connection(): \PDO
    {
        if( !isset( $this->connection ) ) throw new \Exception('Database not connected.');
        return $this->connection;
    }


    /**
     * Returns if the database is already connected.
     * @return bool
     */
    public function isConnected(): bool
    {
        return (bool) $this->connection;
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
    public function disconnect(){
        unset( $this->connection );
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
     * @param $messageId
     * @param $inReplyTo
     * @param $references
     * @param string $rawEmail
     * @return false|string
     */

    public function storeEmail($from, $to, $cc, $bcc, $subject, $body, $code, $messageId, $inReplyTo, $references, $rawEmail = '', $mail_attachments=null){
        // Users are associated with domains, and all emails are associated with domain.
        // Therefore, we need to find out the related domain for the incoming email.
        // If domain is not found, then this is outgoing email. set domain id to null.
        $domainId = false;
        foreach ($to as $to_single_domain) {
            $domainId = $this->findDomain($to_single_domain);
            if($domainId){
                break;
            } 
        }
        
        $domain = null;
        if($domainId){
            $domain = $domainId;
        }
        try{

            $email_to = [];
            // Save Out Going Email in Multiple Rows
            if(is_array($to)){
                $email_to = $to;
            }else{
                $email_to[] = $to;
            }
            
            // Save email to emails table
            foreach ($email_to as $em_to) {
                $preparedStatement = $this->connection->prepare(
                    sprintf("
                        INSERT INTO %s (
                            email_from, email_to, cc, bcc, subject, body, raw_email, code, domain_id,
                            message_id, in_reply_to, reference, created_at, updated_at, attachments
                        )VALUES (:from, :to, :cc, :bcc, :subject, :body, :rawEmail, :code, :domain, :messageId, :inReplyTo, :reference, :created, :updated, :attachments)
                    ",
                        $this->config['emails_table']
                    )
                );
                $preparedStatement->execute([
                    'from' => strtolower($from),
                    'to' => $em_to,
                    'cc' => json_encode($cc),
                    'bcc' => json_encode($bcc),
                    'subject' => $subject,
                    'body' => $body,
                    'rawEmail' => $rawEmail,
                    'code' => $code,
                    'domain' => $domain,
                    'messageId' => $messageId,
                    'inReplyTo' => $inReplyTo,
                    'reference' => $references,
                    'created' => date("Y-m-d H:i:s"),
                    'updated' => date("Y-m-d H:i:s"),
                    'attachments'=>$mail_attachments
                ]);
            }

            $emailId = $this->connection->lastInsertId();

            //set the groupId
            if ($inReplyTo) {
                //if inReplyTo exists then set the parent's groupId as a groupId
                $groupId  = $this->getParentGroupId($inReplyTo);
            } else {
                //if inReplyTo not exists then set email's Id as a groupId
                $groupId = $emailId;
            }
            $this->setGroupId($emailId, $groupId);

            // If domain id is found then this is an email that comes to one of our domain.
            // User may setup webhook and we have to process. Therefore, trigger the webhook.
            // If domain id is not found this mean this is an outgoing email that goes to unknown domain.
            // Return true in this case.
            if($domainId){
                return $this->_triggerWebhook($emailId);
            }
            return true;
        }catch (\Throwable $th){
            return false;
        }
    }

    /**
     * Find user and return password.
     * This function will be used to authenticate smtp requests to send emails.
     * $username is 'email'
     *
     * @param $username
     * @return false
     */
    public function getPasswordForUsername($username){
        try{
            // Get user password
            $preparedStatement = $this->connection->prepare(
                sprintf(
                    "SELECT password FROM %s WHERE email=:username",
                    $this->config['users_table']
                )
            );
            $preparedStatement->execute([
                'username' => strtolower($username)
            ]);
            $userPassword = $preparedStatement->fetch();
            if(!$userPassword){
                return false;
            }
            return $userPassword['password'];
        }catch (\Throwable $th){
            return false;
        }
    }


    /**
     * Find user by its username.
     * $username is 'email'
     *
     * @param $username
     * @param string $fields
     * @return array|false
     */
    public function getUserForUsername($username, $fields = '*'){
        try{

            return $this->autoConnect(function () use ($username, $fields){
                // Get user password
                $preparedStatement = $this->connection->prepare(
                    sprintf(
                        "SELECT $fields FROM %s WHERE email=:username",
                        $this->config['users_table']
                    )
                );
                $preparedStatement->execute([
                    'username' => strtolower($username)
                ]);

                return $preparedStatement->fetch();

            });

        }catch (\Throwable $th){
            return false;
        }
    }

    /**
     * Find the related domain name for the given email address and return domain id.
     * Domain id will be used to associate emails to domains.
     * This function returns false if an error occurs or if domain name cannot be found.
     *
     * @param $emailAddress
     * @return false
     */
    public function findDomain($emailAddress){
        try{
            // select only id because only id is needed.
            // set limit to 1 to make it more efficient.
            $preparedStatement = $this->connection->prepare(
                sprintf("SELECT id FROM %s WHERE name=:name AND is_configured=1 LIMIT 1", $this->config['domains_table'])
            );
            // explode $to and get domain name
            $preparedStatement->execute([
                'name' => strtolower(explode('@', $emailAddress)[1])
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
     * Find the related domain name for the given email address and return domain id.
     * Domain id will be used to associate emails to domains.
     * This function returns false if an error occurs or if domain name cannot be found.
     *
     * @param $emailAddress
     * @return false
     */
    public function findDomainShort($emailAddress){
        try{
            $this->connect();
            // select only id because only id is needed.
            // set limit to 1 to make it more efficient.
            $preparedStatement = $this->connection->prepare(
                sprintf("SELECT id FROM %s WHERE name=:name AND is_configured=1 LIMIT 1", $this->config['domains_table'])
            );
            // explode $to and get domain name
            $preparedStatement->execute([
                'name' => strtolower(explode('@', $emailAddress)[1])
            ]);
            $domain = $preparedStatement->fetch();
            if(!$domain){
                return false;
            }
            $this->disconnect();
            return $domain['id'];
        }catch (\Throwable $th){
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Find the related domain dkim rsa private key string for the given domain name.
     * Domain rsa private key string will be used to sign outgoing emails.
     * This function returns false if an error occurs or if domain name cannot be found.
     *
     * @param $domainName
     * @return false
     */
    public function findDomainDKIM($domainName){
        try{
            $this->connect();
            // select only id because only id is needed.
            // set limit to 1 to make it more efficient.
            $preparedStatement = $this->connection->prepare(
                sprintf("SELECT rsa_private FROM %s WHERE name=:name AND is_configured=1 LIMIT 1", $this->config['domains_table'])
            );
            // explode $to and get domain name
            $preparedStatement->execute([
                'name' => $domainName
            ]);
            $domain = $preparedStatement->fetch();
            if(!$domain){
                return false;
            }
            $this->disconnect();
            return $domain['rsa_private'];
        }catch (\Throwable $th){
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Check if user has permission to send email.
     *
     * @param $username
     * @param $emailAddress
     * @return bool
     */
    public function checkUserPermission($username, $emailAddress){
        try{
            // Find user by username (email) and if user does not exists then return false.
            $user = $this->_findUserByEmail($username);
            if(!$user){
                return false;
            }
            // Check if user mailbox user or not
            $userCreatedBy = $user['created_by_id'];
            $domain = explode('@', $emailAddress)[1];
            if($userCreatedBy && $userCreatedBy > 1){
                // >1 because to be a mailbox user, created by should not be admin
                // User is mailbox user. Check assigned emails.
                // Chek if main use who created mailbox user owns this domain
                // If owner has not such domain or if it is not configured,
                // then mailbox user has no permission to send email from this domain
                $domainExists = $this->_checkDomainByCreatedById($domain, $userCreatedBy);
                if(!$domainExists){
                    return false;
                }
                // Check if assigned email exists for the mailbox user.
                // If it does not exist then return false. User has no permission to send email.
                $assignedEmailExists = $this->_checkAssignedEmailByUserId($emailAddress, $user['id']);
                if(!$assignedEmailExists){
                    return false;
                }
            }else{
                // User is main user. Check his own configured domains.
                // If domain name is found for the user and if ti is configured,
                // then main user has permission to send email.
                $domainExists = $this->_checkDomainByCreatedById($domain, $user['id']);
                if(!$domainExists){
                    return false;
                }
            }
            // If we did not return false till here, this means user has permission to send email from this domain.
            return true;
        }catch (\Exception $e){
            return false;
        }
    }

    /**
     * Check if domain name is configured and created by the given user id.
     *
     * @param $domain
     * @param $createdById
     * @return bool
     */
    private function _checkDomainByCreatedById($domain, $createdById){
        try{
            $this->connect();
            // Find domain name.
            $preparedStatement = $this->connection->prepare(
                sprintf(
                    "SELECT id FROM %s WHERE name=:domain AND is_configured=1 AND created_by_id=:userid LIMIT 1",
                    $this->config['domains_table']
                )
            );
            $preparedStatement->execute([
                'domain' => strtolower($domain),
                'userid' => $createdById
            ]);
            $domainExists = $preparedStatement->fetch();
            $this->disconnect();
            // If domain name is not found then return false.
            if(!$domainExists){
                return false;
            }
            return true;
        }catch (\Exception $e){
            $this->disconnect();
            return false;
        }
    }

    /**
     * Check if assigned email exists for mailbox user.
     *
     * @param $email
     * @param $userId
     * @return bool
     */
    private function _checkAssignedEmailByUserId($email, $userId){
        try{
            $this->connect();
            // Find domain name.
            $preparedStatement = $this->connection->prepare(
                sprintf(
                    "SELECT id FROM %s WHERE email=:email AND user_id=:userid LIMIT 1",
                    $this->config['account_domain_emails_table']
                )
            );
            $preparedStatement->execute([
                'email' => strtolower($email),
                'userid' => $userId
            ]);
            $assignedEmailExists = $preparedStatement->fetch();
            // If assigned email is not found then return false.
            $this->disconnect();
            if(!$assignedEmailExists){
                return false;
            }
            return true;
        }catch (\Exception $e){
            $this->disconnect();
            return false;
        }
    }

    /**
     * Find user by email.
     * Return id and created_by_id only if user is found. Otherwise return false.
     *
     * @param $email
     * @return bool
     */
    private function _findUserByEmail($email){
        try{
            $this->connect();
            // Find user
            $preparedStatement = $this->connection->prepare(
                sprintf(
                    "SELECT id,created_by_id FROM %s WHERE email=:email LIMIT 1",
                    $this->config['users_table']
                )
            );
            $preparedStatement->execute([
                'email' => strtolower($email)
            ]);
            $user = $preparedStatement->fetch();
            $this->disconnect();
            if(!$user){
                return false;
            }
            return $user;
        }catch (\Exception $e){
            $this->disconnect();
            return false;
        }
    }


    /**
     * Auto-Connect to database before doing operation &
     * disconnect after completion.
     *
     * Returns the result of the operation.
     *
     * @param callable $operation The requested operation.
     * @param mixed ...$args Any arguments passed will be passed on to the operation.
     * @return mixed
     */
    public function autoConnect(callable $operation, ...$args){

        $alreadyConnected = $this->isConnected();

        // Do not re-connect, if DB is already connected.
        if( !$alreadyConnected ){
            $this->connect();
        }

        // Run the operation as requested & store the result to return.
        $result = $operation(...$args);

        // Disconnect only if DB was already connected earlier.
        if( !$alreadyConnected ){
            $this->disconnect();
        }

        return $result;

    }

    /**
     * Get the domains that the user has created.
     *
     * @param $userId
     * @param bool $includeNotConfigured Include domains that are not configured yet.
     * @return array|null
     */
    public function getDomainsCreatedByUser($userId, $includeNotConfigured = false ): ?array
    {

        try{

            $domains = $this->autoConnect(function() use ( $userId, $includeNotConfigured ) {

                // Find user
                /** @var \PDOStatement $preparedStatement */
                $preparedStatement = $this->connection->prepare(
                    sprintf(
                        "SELECT `name` FROM `%s` WHERE created_by_id=:userId" . ($includeNotConfigured ? '' : ' AND `is_configured`'),
                        $this->config['domains_table']
                    )
                );

                $preparedStatement->execute([
                    'userId' => $userId
                ]);

                return $preparedStatement->fetchAll( \PDO::FETCH_COLUMN );

            });

            if(!empty( $domains )){
                return $domains;
            }

        }catch (\Exception $e){}

        return null;

    }


    /**
     * Get the accessible domains and/or emails of a user.
     * Result may contain both domains & emails or one of
     * them or also each of them can be empty.
     *
     * Returning Array Shape:
     *
     *     [
     *         'domains' => [
     *              'domain1.com',
     *              'domain2.com',
     *              ...
     *          ],
     *          'emails' => [
     *              'email@domain1.com',
     *              'email@domain2.com',
     *              ...
     *          ]
     *     ]
     *
     * @param $userId
     * @param bool $includeNotConfigured Include domains that are not configured yet.
     * @return array
     */
    public function getAccessibleDomainsAndEmails( $userId, $includeNotConfigured = false ): array
    {

        return $this->autoConnect(function() use ( $userId, $includeNotConfigured ){

            /**
             * Currently either 'domains' or 'emails' will be filled
             * but, at a later stage, if the application gets more
             * complex, it may have both.
             */

            $accessible = [
                'domains'   => [],
                'emails'    => [],
            ];

            $stmt = $this->connection->prepare(
                sprintf(
                    "SELECT `id`, `created_by_id` FROM `%s` WHERE id=:userId LIMIT 1",
                    $this->config['users_table']
                )
            );

            $stmt->execute(['userId' => $userId]);
            $user = $stmt->fetch( \PDO::FETCH_ASSOC );

            /**
             * If the user doesn't exists, we'll return from here.
             */
            if( !$user ){
                return $accessible;
            }

            /**
             * If the user is an owner, we'll send associated domains.
             * Otherwise, we'll look for associated emails.
             */

            if( empty( $user['created_by_id'] ) ){
                $accessible['domains'] = $this->getDomainsCreatedByUser( $userId, $includeNotConfigured ) ?? [];

            }else{

                /**
                 * LIMIT is purposefully not used as the relationship can be
                 * one-to-many, so there could be multiple associated emails.
                 */
                $stmt = $this->connection->prepare(
                    sprintf(
                        "SELECT `email` FROM %s WHERE user_id=:userid",
                        $this->config['account_domain_emails_table']
                    )
                );

                $stmt->execute([ 'userid' => $userId ]);

                $probableEmails = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                if( !empty( $probableEmails ) ){

                    /**
                     * Map emails with corresponding domain to avoid DB Calls in a loop.
                     */
                    $emails = [];
                    foreach ($probableEmails as $email){
                        $domain = strtolower( explode('@', $email, 2)[1] ?? '' );
                        if( ( $emails[ $domain ] ?? false) ) $emails[ $domain ] = [];
                        $emails[ $domain ][] = $email;
                    }

                    $domains = array_keys( $emails );

                    /**
                     * We may have multiple domains, however not all might be configured,
                     * So, we need to reduce that based on which are configured & exists
                     * in our database as per $includeNotConfigured.
                     *
                     * We are expecting, all domains are validated before the were
                     * saved in database.
                     */
                    $stmt = $this->connection->prepare(
                        sprintf("
                                SELECT `name` FROM %s
                                WHERE `name` IN ('". implode("','", $domains) ."')
                            " . ($includeNotConfigured ? '' : ' AND `is_configured`'),
                            $this->config['domains_table']
                        )
                    );

                    $stmt->execute();

                    /**
                     * Get only accessible domains
                     */
                    $domains = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                    $emails = array_intersect_key( $emails, array_flip( $domains ));

                    /**
                     * Flatten the array to return only emails as array and not 2-dimensional array.
                     */
                    foreach ( $emails as $item ){
                        $accessible['emails'] = array_merge( $accessible['emails'], $item );
                    }

                }

            }

            return $accessible;

        });

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

    // Get parent groupId belongs to current email
    protected function getParentGroupId($inReplyTo)
    {
        $stmt = $this->connection->prepare("SELECT group_id FROM emails WHERE message_id=?");
        $stmt->bind_param("s", $inReplyTo);
        $stmt->execute();
        $stmt->bind_result($parentGroupId);
        $stmt->fetch();
        return $parentGroupId;
    }

    // Set groupId
    protected function setGroupId($emailId, $groupId){
        $stmt = $this->connection->prepare("UPDATE emails SET group_id=? WHERE id=?");
        $stmt->bind_param("ii", $groupId,$emailId);
        $stmt->execute();
        return true;
    }

}
