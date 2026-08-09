<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * The four places an instance's configuration actually lives.
 *
 * They are separate cases and not one flat list because *when* a restored value
 * takes effect is decided per place, not per value: the database is live the
 * moment the transaction commits, the secrets volume and the generated
 * environment file are both read at container start, and both of the latter can
 * be mounted somewhere this process cannot write.
 *
 * `Users` is a fourth place in the review even though its rows sit in the same
 * database as `Database`'s, and the split is deliberate: those two answer
 * different questions and are read by different people. `Database` is the
 * operator's provider registrations, one row per provider, and a restore
 * overwrites them. `Users` is people and everything they configured, one entry
 * per person, and a restore never overwrites an existing one. Folding them
 * together would put "Nextcloud" and "anna@example.org" in one list under one
 * verb, and the verb would be wrong for one of them.
 *
 * `Environment` used to mean "plMail can never touch this". It does not: on the
 * supported deployment those values are not hand-edited `.env` entries at all
 * but lines in `var/secrets/generated.env`, minted on first run by
 * `frankenphp/generate-secrets.sh` and loaded by the entrypoint — a file every
 * service mounts and the app can write. See
 * {@see \App\Domain\Enum\Backup\ConfigBackupDisposition}, which is where the
 * "when" now lives.
 *
 * The review page groups by this, so the grouping is a domain fact rather than
 * a template's arrangement.
 */
enum ConfigBackupSection: string
{
    /** Environment variables: `var/secrets/generated.env`, compose, or the shell. */
    case Environment = 'env';

    /** Files under the shared secrets directory — the JWT keypair and friends. */
    case SecretsFile = 'files';

    /** Rows plMail owns: the Firebase project and the provider registrations. */
    case Database = 'database';

    /**
     * People, and everything each of them configured: mailboxes and their
     * credentials, aliases, integrations, filters, labels, calendars and the
     * links they published.
     */
    case Users = 'users';

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
            self::Users       => 'fa-solid fa-users',
        };
    }
}
