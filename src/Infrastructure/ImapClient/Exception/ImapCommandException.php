<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

use App\Infrastructure\ImapClient\ImapResponse;

/**
 * The server answered a command with NO or BAD.
 *
 * The connection is still good; only this command failed. The response is
 * kept whole, because its code — `[NONEXISTENT]`, `[OVERQUOTA]`, `[ALERT]` —
 * is usually the useful part.
 */
class ImapCommandException extends ImapException
{
    public function __construct(
        public readonly string       $command,
        public readonly ImapResponse $response,
    ) {
        parent::__construct(sprintf('%s failed: %s %s', $command, $response->status, $response->text));
    }
}
