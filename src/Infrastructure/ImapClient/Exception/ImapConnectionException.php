<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

/**
 * The connection itself failed: it could not be made, secured, or kept.
 *
 * The ordinary weather of a long-held connection. Whatever was in flight is
 * lost and the connection is unusable, but a new one may well work.
 */
class ImapConnectionException extends ImapException
{
}
