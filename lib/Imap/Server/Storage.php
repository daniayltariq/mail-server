<?php


namespace PBMail\Imap\Server;


use PBMail\Helpers\DbHelper;
use PBMail\Imap\Server\Traits\Makeable;
use PhpMimeMailParser\Parser;

/**
 * Class Storage
 * @package PBMail\Imap\Server
 */
class Storage
{

    use Makeable;

    // Supported Pre-set folders ======================
    const FOLDER_INBOX = 'INBOX';
    const FOLDER_SENT = 'Sent';
    const FOLDER_ALL    = 'ALL';

    // maildir and IMAP flags, using IMAP names, where possible to be able to distinguish between IMAP
    // system flags and other flags
    const FLAG_PASSED   = 'Passed';
    const FLAG_SEEN     = '\Seen';
    const FLAG_UNSEEN   = '\Unseen';
    const FLAG_ANSWERED = '\Answered';
    const FLAG_FLAGGED  = '\Flagged';
    const FLAG_DELETED  = '\Deleted';
    const FLAG_DRAFT    = '\Draft';
    const FLAG_RECENT   = '\Recent';


    public $folders = [ self::FOLDER_INBOX, self::FOLDER_SENT ];
    public $userId;


    // Table References ====================
    protected $emails_table;
    protected $users_table;
    protected $domains_table;


    /**
     * Storage constructor.
     * @param $userId
     */
    public function __construct( $userId ){
        $this->userId = $userId;
        $this->users_table = getenv('DB_USERS_TABLE');
        $this->emails_table = getenv('DB_EMAILS_TABLE');
        $this->domains_table = getenv('DB_DOMAINS_TABLE');
    }

    /**
     * Get the Database Instance
     */
    public function db(): DbHelper
    {
        return DbHelper::getInstance();
    }


    /**
     * @param string $folder
     * @param string $select
     * @param string $where
     * @param string $extra
     * @return false|\PDOStatement
     * @throws \Exception
     */
    public function prepareSQL(string $folder = self::FOLDER_INBOX, $select = '*', $where = '', $extra = ''){

        /**
         *
         * If domain id is present, means it is a receiving email. If its
         * empty, then its sending email.
         *
         * But, as the email that are sent is not connected via any foreign
         * key, we must use like query for the domains on `email_from` column.
         *
         * For Received emails, no such check is required as the connected
         * domain tells the same story.
         *
         */

        $sql = "SELECT $select";

        $domains = $this->db()->getDomainsCreatedByUser( $this->userId );
        if( empty( $domains ) ) return null;


        if( in_array( $folder, $this->folders ) ){
            $field = $folder === static::FOLDER_INBOX ? 'email_to' : 'email_from';
            $likes = implode(' OR ', array_map(fn($domain) => "`$field` LIKE '%@$domain'", $domains) );
        }else if( $folder === static::FOLDER_ALL ){
            $likes = implode(' OR ', array_map(fn($domain) => "`email_from` LIKE '%@$domain' OR `email_to` LIKE '%@$domain'", $domains) );
        }

        $sql .= "
                FROM `$this->emails_table` 
                WHERE EXISTS (
                    SELECT * FROM `$this->domains_table`
                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
                ) AND ( $likes )
            ";

//        if( $folder === static::FOLDER_INBOX ){
//
//            $sql .= "
//                FROM `$this->emails_table`
//                WHERE EXISTS(
//                    SELECT * FROM `$this->domains_table`
//                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
//                    AND EXISTS(
//                        SELECT * FROM `$this->users_table`
//                        WHERE `$this->users_table`.`id` = :userId
//                        AND `$this->domains_table`.`created_by_id` = `$this->users_table`.`id`
//                    )
//                )
//            ";
//
//        } else {
//
//            $domains = $this->db()->getDomainsCreatedByUser( $this->userId );
//            if( empty( $domains ) ) return null;
//
//            $likes = implode(
//                ' OR ',
//                array_map(fn($domain) => "`email` LIKE '%@$domain'", $domains)
//            );
//
//            $sql .= "
//                FROM `$this->emails_table`
//                WHERE EXISTS (
//                    SELECT * FROM `$this->domains_table`
//                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
//                ) AND ( $likes )
//            ";
//
//        }

        if( $where ){
            $sql .= " AND ($where)";
        }

        if( $extra ){
            $sql .= " $extra";
        }

        return $this->db()->connection()->prepare( $sql );
    }


    /**
     * Check if a folder exists.
     *
     * @param string $folder
     * @return bool
     */
    public function folderExists(string $folder): bool
    {
        return in_array( $folder, $this->folders );
    }


    /**
     * Add Folder
     * @param string $folder
     * @return bool
     */
    public function addFolder(string $folder): bool
    {
        // @NOTICE NOT_IMPLEMENTED
        return false;
    }


    /**
     * @param int $msgSeqNum
     * @param string $selectedFolder
     * @return int
     */
    public function getMsgIdBySeq(int $msgSeqNum, $selectedFolder = self::FOLDER_ALL): int
    {

        try{

            $this->db()->connect();

            $stmt = $this->prepareSQL(
                $selectedFolder,
                'id',
                null,
                "LIMIT ". ($msgSeqNum - 1) .", 1"
            );

            $stmt->execute([
                'userId' => $this->userId
            ]);

            $id = intval( $stmt->fetchColumn() );
            $this->db()->disconnect();

            return $id;

        }catch (\Throwable $e){
            $this->db()->disconnect();
            return 0;
        }
    }


    /**
     * @param string $selectedFolder
     * @param array $flags
     * @return int
     */
    public function getCountMailsByFolder(string $selectedFolder, array $flags = []): int
    {

        try{

            $this->db()->connect();

            $stmt = $this->prepareSQL(
                $selectedFolder,
                'COUNT(*)',
            );

            $stmt->execute([
                'userId' => $this->userId
            ]);
            $count = intval( $stmt->fetchColumn() );

            $this->db()->disconnect();
            return $count;

        }catch (\Throwable $e){
            $this->db()->disconnect();
            return 0;
        }

    }


    /**
     * @param int $msgId
     * @param array $flags
     */
    public function setFlagsById(int $msgId, array $flags)
    {
        // @NOTICE NOT_IMPLEMENTED
    }


    /**
     * @param int $mailId
     * @return Parser
     */
    public function getMailById(int $mailId): ?Parser
    {

        try{

            $this->db()->connect();

            $stmt = $this->prepareSQL(
                static::FOLDER_ALL,
                'raw_email',
                '`id` = :mailId',
                'LIMIT 1'
            );
            $stmt->execute([ 'mailId' => $mailId ]);


            $rawEmail = $stmt->fetchColumn();

            if ( !$rawEmail ) throw new \Exception("Mail #u-$mailId not found.");
            $this->db()->disconnect();

            $mail = new \PhpMimeMailParser\Parser();
            $mail->setText( $rawEmail );

            return $mail;

        }catch (\Throwable $e){
            $this->db()->disconnect();
            return null;
        }

    }

    public function getFlagsById(int $msgId): array
    {
        // @NOTICE NOT_IMPLEMENTED
        return [];
    }


    /**
     * Get the next MSG ID.
     * @return int
     */
    public function getNextMsgId(): int
    {
        return 0;
    }

    public function getFlagsBySeq(int $msgSeqNum, string $selectedFolder)
    {
        return [ static::FLAG_RECENT ];
    }

    public function getMailBySeq(int $msgSeqNum, string $selectedFolder)
    {

        try{

            $this->db()->connect();

            $stmt = $this->prepareSQL(
                $selectedFolder,
                'raw_email',
                null,
                "LIMIT ". ($msgSeqNum - 1) .", 1"
            );

            $stmt->execute();
            $rawEmail = $stmt->fetchColumn();

            if ( !$rawEmail ) return false;
            $this->db()->disconnect();

            $mail = new \PhpMimeMailParser\Parser();
            $mail->setText( $rawEmail );

            return $mail;

        } catch (\Throwable $e){
            $this->db()->disconnect();
            return null;
        }
    }


}