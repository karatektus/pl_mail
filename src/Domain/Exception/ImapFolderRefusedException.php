<?php

declare(strict_types=1);

namespace App\Domain\Exception;

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
     * The server's answer out of whatever the library threw.
     *
     * ImapServerErrorException carries the tagged response line ("NO [CANNOT]
     * Permission denied"); ResponseException wraps its causes in a paragraph of
     * its own ("Command failed to process: Causes: - …"). Both are reduced to
     * the part the server wrote.
     */
    public static function fromServer(string $folder, \Throwable $e): self
    {
        $message = trim($e->getMessage());

        if (1 === preg_match('/^\s*-\s*(.+)$/m', $message, $cause) && str_starts_with($message, 'Command failed')) {
            $message = trim($cause[1]);
        }

        $reason = trim((string) preg_replace('/^(NO|BAD|BYE)\b\s*/i', '', $message));

        return new self($folder, '' === $reason ? $message : $reason, $e);
    }
}
