<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * The three places an instance's configuration actually lives.
 *
 * They are separate cases and not one flat list because the answer to "can
 * plMail write this?" is decided per place, not per value: the database it owns
 * outright, the secrets volume it may or may not be able to write depending on
 * how the volume was mounted, and the environment it can never write at all —
 * a running PHP process cannot change the variables it was started with, and
 * writing them somewhere for the NEXT start is a different promise from the one
 * an admin clicking "apply" thinks they are getting.
 *
 * The review page groups by this, so the grouping is a domain fact rather than
 * a template's arrangement.
 */
enum ConfigBackupSection: string
{
    /** Environment variables: .env.local, compose, or the shell. */
    case Environment = 'env';

    /** Files under the shared secrets directory — the JWT keypair and friends. */
    case SecretsFile = 'files';

    /** Rows plMail owns: the Firebase project and the provider registrations. */
    case Database = 'database';

    public function transKey(): string
    {
        return 'admin.config_backup.section.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Environment => 'fa-solid fa-terminal',
            self::SecretsFile => 'fa-solid fa-file-shield',
            self::Database    => 'fa-solid fa-database',
        };
    }
}
