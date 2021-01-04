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
    public function getMsgIdBySeq(int $msgSeqNum, string $selectedFolder): int
    {
        try{

            $this->db()->connect();

            $stmt = $this->db()->connection()->prepare("
                SELECT id FROM `$this->emails_table`
                WHERE EXISTS(
                    SELECT * FROM `$this->domains_table`
                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
                    AND EXISTS(
                        SELECT * FROM `$this->users_table`
                        WHERE `$this->users_table`.`id` = ?
                    )
                )
                LIMIT ". ($msgSeqNum - 1) .", 1
            ");

            $stmt->execute([ $this->userId ]);
            $count = intval( $stmt->fetchColumn() );

            $this->db()->disconnect();

            return $count;

        }catch (\Throwable $e){
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

            $stmt = $this->db()->connection()->prepare("
                SELECT COUNT(*) FROM `$this->emails_table`
                WHERE EXISTS(
                    SELECT * FROM `$this->domains_table`
                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
                    AND EXISTS(
                        SELECT * FROM `$this->users_table`
                        WHERE `$this->users_table`.`id` = ?
                    )
                )
            ");

            $stmt->execute([ $this->userId ]);
            $count = intval( $stmt->fetchColumn() );

            $this->db()->disconnect();

            return $count;

        }catch (\Throwable $e){
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
    public function getMailById(int $mailId)
    {

        try{

            $this->db()->connect();

            $stmt = $this->db()->connection()->prepare("
                SELECT `raw_email` FROM `$this->emails_table`
                WHERE `id` = ?
                AND EXISTS(
                    SELECT * FROM `$this->domains_table`
                    WHERE `$this->domains_table`.`id` = `$this->emails_table`.`domain_id`
                    AND EXISTS(
                        SELECT * FROM `$this->users_table`
                        WHERE `$this->users_table`.`id` = ?
                    )
                )
                LIMIT 1
            ");

            $stmt->execute([ $mailId, $this->userId ]);
            $rawEmail = $stmt->fetchColumn();

            if ( !$rawEmail ) return false;
            $this->db()->disconnect();

            $mail = new \PhpMimeMailParser\Parser();
            $mail->setText( $rawEmail );

            return $mail;

        }catch (\Throwable $e){}
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


}