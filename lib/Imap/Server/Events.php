<?php


namespace PBMail\Imap\Server;


/**
 * Class Events
 * @package PBMail\IMAP\Server
 */
final class Events
{
    const CONNECTION_CHANGE_STATE = 'imap_server.connection.change_state';

    const CONNECTION_HELO_RECEIVED = 'imap_server.connection.helo_received';

    const CONNECTION_LINE_RECEIVED = 'smtp_server.connection.line_received';

}