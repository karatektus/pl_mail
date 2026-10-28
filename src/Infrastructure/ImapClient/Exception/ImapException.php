<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

/**
 * Anything that went wrong talking IMAP.
 *
 * The subclasses are shaped by what the caller does next, not by where the
 * failure came from: a connection that broke is worth another try soon, a
 * login the server refused is not, and a command the server turned down says
 * something about that command and nothing about the connection.
 */
class ImapException extends \RuntimeException
{
}
