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
         * As the email that are sent is not connected via any foreign key, we must
         * use like query for the domains on `email_from` column. Likewise, for the
         * received we'll check on `email_from` column.
         *
         * For email only accessibility, we'll use IN(...) query as we have the whole
         * email address to check.
         */


        $accessible = $this->db()->getAccessibleDomainsAndEmails( $this->userId );

        /**
         * If both domains & emails are empty, we should return from here.
         */

        if( empty( $accessible['domains'] ) && empty( $accessible['emails'] ) ){
            return null;
        }


        /**
         * If we use specific folder, then we should set it in our field
         * otherwise set it to null.
         */

        $field = in_array( $folder, $this->folders ) ? (
            $folder === static::FOLDER_INBOX ? 'email_to' : 'email_from'
        ) : null;


        /**
         * We are not escaping anything at this stage with the expectation
         * that emails & domains are validated before storing into database.
         */

        $emails = implode( "','", $accessible['emails'] );


        /**
         * If a field is set, we'll use that for condition, otherwise we'll
         * use both fields (email_to, email_from) to check.
         */

        $conditions = [];

        if( $field ){
            $conditions[] = implode(' OR ', array_map(
                fn($domain) => "`$field` LIKE '%@$domain'", $accessible['domains'])
            );
            if( !empty( $emails ) ){
                $conditions[] = "`$field` IN ('$emails')";
            }

        }else{
            $conditions[] = implode(' OR ', array_map(
                fn($domain) => "`email_from` LIKE '%@$domain' OR `email_to` LIKE '%@$domain'", $accessible['domains'])
            );
            if( !empty( $emails ) ){
                $conditions[] = "`email_from` IN ('$emails') OR `email_to` IN ('$emails')";
            }

        }

        $sql  = "SELECT $select";
        $sql .= "
                FROM `$this->emails_table` 
                WHERE EXISTS (
                    SELECT * FROM `$this->domains_table`
                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
                ) AND ( ". implode( " OR ", $conditions ) ." )
            ";

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

    /**
     * Get flags by sequence number.
     * 
     * @param int $msgSeqNum
     * @param string $selectedFolder
     * @return array
     */
    public function getFlagsBySeq(int $msgSeqNum, string $selectedFolder): array
    {
        return [];
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

    public function removeMailBySeq(int $expungeSeqNum, string $selectedFolder)
    {
        // @NOTICE NOT_IMPLEMENTED
    }


}