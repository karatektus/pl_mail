<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * Why a config backup could not be opened.
 *
 * Four cases and no fifth, because the recovery differs for each and the page
 * has to say which one happened. "Could not read the file" is the message that
 * makes an admin try the same password twice; "that password does not open this
 * file" is the one that makes them go and find the right one.
 *
 * Deliberately coarse about the difference between a corrupt envelope and a
 * wrong password. secretbox cannot tell them apart — a wrong key and a flipped
 * byte both fail the Poly1305 tag — so the enum does not pretend to. What it
 * separates is the checks that happen BEFORE decryption (is this even a plMail
 * backup, is the version one we know) from the one that happens during it,
 * because those genuinely are distinguishable and mean different things.
 */
enum ConfigBackupFailure: string
{
    /** The file is not a plMail backup envelope at all. */
    case NotABackup = 'not-a-backup';

    /** It is one, from a future plMail that writes a format this cannot read. */
    case UnsupportedVersion = 'unsupported-version';

    /** The envelope opened with nothing inside it that we recognise. */
    case MalformedDocument = 'malformed-document';

    /** The password did not open it — or the ciphertext has been altered. */
    case WrongPassword = 'wrong-password';

    /** Translation key for the sentence shown to the admin. */
    public function transKey(): string
    {
        return 'admin.config_backup.error.' . $this->value;
    }
}
