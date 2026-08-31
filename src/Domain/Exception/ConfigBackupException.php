<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Enum\Backup\ConfigBackupFailure;
use RuntimeException;

/**
 * A config backup that could not be opened, carrying what the admin should do
 * about it.
 *
 * One class rather than four, because the recovery is always the same shape —
 * show a sentence, change nothing, let them try again — and the part that
 * varies is which sentence. That variation lives in the enum, which the
 * template translates; the exception exists to carry it out of the cipher and
 * the parser without either of them knowing what a template is.
 *
 * The message is deliberately never shown to the user. It is for the log, and
 * it says more than the enum does — a leaked "expected 16 salt bytes, got 3"
 * on the page would be a small oracle over a file somebody uploaded, and it
 * would not help anyone holding the right file anyway.
 */
final class ConfigBackupException extends RuntimeException
{
    public function __construct(
        public readonly ConfigBackupFailure $failure,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notABackup(string $detail): self
    {
        return new self(ConfigBackupFailure::NotABackup, $detail);
    }

    public static function unsupportedVersion(int $version, int $supported): self
    {
        return new self(ConfigBackupFailure::UnsupportedVersion, sprintf(
            'Backup declares format version %d; this plMail reads up to %d.',
            $version,
            $supported,
        ));
    }

    public static function malformed(string $detail): self
    {
        return new self(ConfigBackupFailure::MalformedDocument, $detail);
    }

    /**
     * A write inside the import hit a unique index.
     *
     * The email names the user being restored when it happened, which is the
     * only handle an operator has on it — the colliding row is a hash or a
     * digest and naming that would help nobody. The underlying exception goes
     * in the message, for the log.
     */
    public static function collision(string $email, string $detail): self
    {
        return new self(ConfigBackupFailure::Collision, sprintf(
            'Restoring %s hit a unique constraint: %s',
            $email,
            $detail,
        ));
    }

    public static function wrongPassword(): self
    {
        return new self(
            ConfigBackupFailure::WrongPassword,
            'secretbox refused the ciphertext: wrong password, or the file has been altered.',
        );
    }
}
