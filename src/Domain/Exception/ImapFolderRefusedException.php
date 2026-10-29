<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Infrastructure\ImapClient\Exception\ImapCommandException;

/**
 * The IMAP server said no to a folder command — CREATE, RENAME or DELETE.
 *
 * Carries the server's own words in `$reason`, trimmed of the status word and
 * of the library's framing, because that is what a person can act on: "[CANNOT]
 * Permission denied", "[OVERQUOTA] …", "Invalid mailbox name". The folder
 * actions in Settings show it as it came; the background moves that create
 * folders on demand catch it and log it, as they always did, because a
 * Messenger retry would only ask the same question again.
 *
 * "Already exists" is never this: for CREATE it is the request already met.
 */
final class ImapFolderRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $folder,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('The IMAP server refused "%s": %s', $folder, $reason), 0, $previous);
    }

    /**
     * The server's answer, as the tagged response gave it: its response code,
     * if any, and its text — "[CANNOT] Permission denied". Anything that is
     * not a command the server answered (a connection lost on the way) keeps
     * its own message.
     */
    public static function fromServer(string $folder, \Throwable $e): self
    {
        if ($e instanceof ImapCommandException) {
            $code   = $e->response->code;
            $reason = trim((null === $code ? '' : '[' . $code . '] ') . $e->response->text);

            return new self($folder, '' === $reason ? $e->getMessage() : $reason, $e);
        }

        return new self($folder, trim($e->getMessage()), $e);
    }
}
