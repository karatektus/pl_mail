<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * Why one value out of a backup cannot be applied by clicking a button.
 *
 * The reason this enum exists rather than a boolean: "plMail cannot write this"
 * is true of a dozen values for four genuinely different causes, and an admin
 * who is told only that will do the wrong repair. Somebody who sees
 * "APP_ENCRYPTION_KEY: cannot write" edits .env.local and restarts, and every
 * credential this instance has stored since becomes unreadable — because the
 * real answer was not "cannot", it was "must not, unless you do it before
 * anything is stored".
 *
 * So each case names the cause, and the translation attached to it says what to
 * do about that cause. There is no `default` anywhere that matches on this: a
 * fifth reason has to be given a sentence before it can be shown.
 */
enum ConfigBackupObstacle: string
{
    /**
     * A variable the process was started with. Nothing PHP does now changes it,
     * and the file it would be written into is read at container start.
     */
    case ProcessEnvironment = 'process-environment';

    /**
     * APP_ENCRYPTION_KEY, and only it. Writing the backup's key here would make
     * every credential this instance has already encrypted — including the ones
     * this very import is about to write — unreadable on the next start. The
     * decrypted-inside-the-envelope design exists precisely so this does not
     * have to happen: the values are re-encrypted under the key in force.
     */
    case EncryptionKeyInUse = 'encryption-key-in-use';

    /**
     * A value that also has to change in something plMail does not administer —
     * POSTGRES_PASSWORD is a role in a database, not a string in a file, and
     * writing the file alone produces an instance that cannot start.
     */
    case ExternalSystem = 'external-system';

    /**
     * The path is right and this process cannot write it. Read-only mount,
     * wrong uid, or a secrets volume the web service was never given.
     */
    case NotWritable = 'not-writable';

    public function transKey(): string
    {
        return 'admin.config_backup.obstacle.' . $this->value;
    }
}
