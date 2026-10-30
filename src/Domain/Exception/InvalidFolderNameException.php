<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * A folder name a person typed that cannot become an IMAP folder as typed.
 *
 * Thrown by ImapFolderName before anything is sent to the server, so the
 * answer is about the name and never about the server: an empty name, one with
 * the server's hierarchy separator in it, a LIST wildcard. `$reason` is the
 * last part of a translation key under `settings.accounts.folders.invalid.`,
 * so the form can say which of these it was.
 */
final class InvalidFolderNameException extends \InvalidArgumentException
{
    public const string EMPTY     = 'empty';
    public const string SEPARATOR = 'separator';
    public const string WILDCARD  = 'wildcard';
    public const string CONTROL   = 'control';
    public const string TOO_LONG  = 'too_long';
    public const string RESERVED  = 'reserved';

    public function __construct(
        public readonly string $reason,
        public readonly string $separator = '',
    ) {
        parent::__construct(sprintf('Invalid folder name: %s', $reason));
    }
}
