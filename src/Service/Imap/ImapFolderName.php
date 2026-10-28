<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Exception\InvalidFolderNameException;

/**
 * Turns a folder name a person typed into the path an IMAP server takes.
 *
 * Two rules, and each has already bitten somebody:
 *
 *   - **Modified UTF-7 exactly once** (RFC 3501 §5.1.3). Mailbox::$fullPath is
 *     stored as it travels, already encoded, so a path read off a row must go
 *     to the server untouched; only the name the person typed is encoded, and
 *     only here. webklex's Client::createFolder() encodes whatever it is given
 *     unless told otherwise, which turns an encoded "Entw&APw-rfe" into
 *     "Entw&-APw-rfe" — a second folder with a name nobody asked for. Callers
 *     therefore talk to the connection, not to Client::createFolder().
 *
 *   - **The separator is not part of a name.** IMAP has no way to escape it:
 *     "v1.2" on a server whose separator is "." is a folder "2" inside a
 *     folder "v1", created without a word. Refused rather than replaced,
 *     because any replacement is a name the person did not type, and the
 *     separator is the server's (Mailbox::$delimiter, from LIST), not a guess.
 *
 * Also refused: the LIST wildcards `*` and `%`, which RFC 3501 §5.1 says a name
 * should not contain because no client can list it reliably, control
 * characters, and "INBOX" at the top level, which every server reserves.
 */
final class ImapFolderName
{
    /** Mailbox::$name is 255 characters; Mailbox::$fullPath is 500. */
    private const int MAX_NAME = 255;

    private const int MAX_PATH = 500;

    /**
     * The path of a new folder called $name, inside $parentPath or at the top
     * of the namespace when that is null.
     *
     * @param string|null $parentPath the parent's raw path, exactly as LIST gave it,
     *                                or the namespace prefix without its separator
     *                                ("INBOX" on a Courier-style server); null or ''
     *                                for the top level
     *
     * @throws InvalidFolderNameException
     */
    public static function childPath(?string $parentPath, string $separator, string $name): string
    {
        $name = self::validate($name, $separator, null === $parentPath || '' === $parentPath);

        $encoded = self::encode($name);
        $path    = null === $parentPath || '' === $parentPath
            ? $encoded
            : $parentPath . $separator . $encoded;

        if (self::MAX_PATH < strlen($path)) {
            throw new InvalidFolderNameException(InvalidFolderNameException::TOO_LONG, $separator);
        }

        return $path;
    }

    /**
     * The name, trimmed, if it can be a folder name on a server with this
     * separator.
     *
     * @throws InvalidFolderNameException
     */
    public static function validate(string $name, string $separator, bool $topLevel): string
    {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidFolderNameException(InvalidFolderNameException::EMPTY, $separator);
        }

        if ('' !== $separator && str_contains($name, $separator)) {
            throw new InvalidFolderNameException(InvalidFolderNameException::SEPARATOR, $separator);
        }

        if (str_contains($name, '*') || str_contains($name, '%')) {
            throw new InvalidFolderNameException(InvalidFolderNameException::WILDCARD, $separator);
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/u', $name) || false === mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidFolderNameException(InvalidFolderNameException::CONTROL, $separator);
        }

        if (self::MAX_NAME < mb_strlen($name)) {
            throw new InvalidFolderNameException(InvalidFolderNameException::TOO_LONG, $separator);
        }

        if (true === $topLevel && 0 === strcasecmp($name, 'INBOX')) {
            throw new InvalidFolderNameException(InvalidFolderNameException::RESERVED, $separator);
        }

        return $name;
    }

    /**
     * One name segment, UTF-8 in, modified UTF-7 out — "Köln" becomes
     * "K&APY-ln", and "R&D" becomes "R&-D". Never call it on a stored path.
     */
    public static function encode(string $name): string
    {
        return (string) mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }
}
