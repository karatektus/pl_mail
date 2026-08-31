<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * Why a config backup could not be opened, or could not be written.
 *
 * Five cases, because the recovery differs for each and the page has to say
 * which one happened. "Could not read the file" is the message that
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

    /**
     * The file opened and was understood, and a row in it collides with one
     * this install already has under a different name.
     *
     * The odd one out: every case above happens before anything is written,
     * which is why they all end "Nothing has been changed." This one happens
     * during the write — and still ends the same way, because the whole import
     * runs in one transaction that rolls back. What it costs is an explanation,
     * since the collision is between rows the review page never compares: an
     * app password's hash, a share link's digest. Those are unique across the
     * whole install, not per user, so a document can be entirely reasonable
     * about the person it describes and still be refused because of a row
     * belonging to somebody else — usually somebody removed, whose tokens a
     * soft delete leaves behind.
     *
     * Without this the violation escaped as a 500 with no message at all, and
     * an operator following the handbook's own advice got a blank error page.
     */
    case Collision = 'collision';

    /** Translation key for the sentence shown to the admin. */
    public function transKey(): string
    {
        return 'admin.config_backup.error.' . $this->value;
    }
}
