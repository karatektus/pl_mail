<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

/**
 * The server said something that is not IMAP, or not at that point.
 *
 * A connection failure for the caller's purposes: once the two sides disagree
 * about where the conversation is, nothing more on that connection can be
 * trusted.
 */
final class ImapProtocolException extends ImapConnectionException
{
}
