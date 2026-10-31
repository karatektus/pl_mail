<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

/**
 * The server did not answer in time.
 *
 * A connection failure like any other — the conversation is out of step once
 * an answer is missing, so the connection is given up — but kept apart so a
 * log can tell a silent server from one that hung up.
 */
final class ImapTimeoutException extends ImapConnectionException
{
}
