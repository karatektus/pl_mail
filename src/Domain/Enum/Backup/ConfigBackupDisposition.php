<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * What actually becomes of one value out of a config backup.
 *
 * **This replaces an enum called `ConfigBackupObstacle`, and the rename is the
 * substance of the change rather than a tidy-up.** The old one asked "why can
 * plMail not write this?" and answered "it is an environment variable" for two
 * dozen values, which produced a review page that was a wall of lines to paste
 * into `.env.local`. That was built on a premise that is wrong for the
 * deployment plMail actually ships: **nobody hand-edits these.** They are minted
 * on first run by `frankenphp/generate-secrets.sh` into
 * `var/secrets/generated.env` on the `app_secrets` volume, every service mounts
 * that volume, and the app process can write it. So "plMail cannot apply this"
 * was false; the true statement is the narrower "this takes effect at the next
 * container start".
 *
 * The five cases are the five genuinely different fates, and each one exists
 * because an operator who was told the wrong one would do the wrong thing:
 *
 *   - {@see self::Applied} — live now. Nothing to do.
 *   - {@see self::AppliedOnRestart} — written where the entrypoint reads it,
 *     live at the next start. One restart, said once for the whole plan, not
 *     once per value.
 *   - {@see self::ShadowedByCompose} — written, and something in the process
 *     environment will still win over it. The honest residue of the old
 *     instruction wall, and per compose.yaml it is a handful of values at most.
 *   - {@see self::External} — plMail cannot make this true on its own, because
 *     the other half of the change lives in a system it is only a client of.
 *   - {@see self::KeptDeliberately} — not written, and that is the correct
 *     outcome rather than a limitation. APP_ENCRYPTION_KEY, and a user this
 *     install already has.
 *   - {@see self::NotWritable} — the path refused. Measured, never assumed.
 *
 * There is no `default` anywhere matching on this: a sixth fate has to be given
 * a sentence before it can be shown.
 */
enum ConfigBackupDisposition: string
{
    /**
     * Written, and in force from this moment.
     *
     * The database rows plMail owns outright: the Firebase project, the mail
     * OAuth registrations, the integration providers. Re-encrypted with *this*
     * install's key on the way in, which is the whole reason the envelope
     * carries them decrypted.
     */
    case Applied = 'applied';

    /**
     * Written into `var/secrets/generated.env`, or over one of the files beside
     * it, and read the next time the stack starts.
     *
     * Not a weaker promise than {@see self::Applied} — the value is on disk, in
     * the one place `frankenphp/docker-entrypoint.sh` loads before it execs the
     * server — but a different one, because the process answering this request
     * read its own environment at boot and cannot be told about the change.
     */
    case AppliedOnRestart = 'applied-on-restart';

    /**
     * Written, and a value in the process environment will override it at the
     * next start anyway.
     *
     * The entrypoint's `load_generated_secrets` skips any name `printenv`
     * already answers for — deliberately, so an operator who manages a secret
     * themselves never has a generated one substituted underneath them. The
     * same rule makes a compose file that pins a name authoritative over
     * anything restored here. plMail's own `compose.yaml` pins three names to
     * non-empty defaults (`MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`,
     * `MERCURE_PUBLIC_URL`); everything else it passes through as `${NAME:-}`,
     * an empty string, which counts as absent. Of those three only
     * MERCURE_PUBLIC_URL is still exported — the other two are {@see
     * self::External} now, because a value plMail refuses to write cannot
     * meaningfully be shadowed.
     */
    case ShadowedByCompose = 'shadowed-by-compose';

    /**
     * Not written, because writing it alone would break the install.
     *
     * `POSTGRES_PASSWORD` and the `postgres_password` file beside it, and the
     * `DATABASE_URL` assembled from them. `generate-secrets.sh` rewrites that
     * file from `generated.env` on *every* boot — but the Postgres image only
     * reads `POSTGRES_PASSWORD_FILE` at initdb, when the data directory is
     * created. On a database that already exists, the role keeps the password
     * it was created with, so restoring another one produces a stack whose app
     * cannot authenticate against its own database.
     *
     * `MAILER_DSN` and `MESSENGER_TRANSPORT_DSN` reach this the same way, once
     * removed: they are the operator's compose file's to state, plMail is only
     * a client of the relay and the queue they name, and no export carries them
     * any more — the classification exists for the backups that already do.
     */
    case External = 'external';

    /**
     * Two things, and for the same reason: leaving them alone is the answer,
     * not the compromise.
     *
     * **APP_ENCRYPTION_KEY** — deliberately left as this install has it,
     * because the import re-encrypts the backup's credentials under the key in
     * force here. Writing the backup's key would make every one of those rows —
     * the ones this very import just wrote — unreadable at the next start,
     * which `app:secrets:init` would then refuse to start on. Nothing is asked
     * of the operator; the line is offered only for the one who is also
     * restoring the old *database*, whose rows are encrypted with it.
     *
     * **A user this install already has**, matched by email. The file's copy of
     * that person — their password hash, their TOTP secret, their mailbox
     * credentials — is not written over the live one. An import is a restore of
     * what a backup holds, and a backup is a photograph of a moment that has
     * passed: applying a three-month-old one to a live account is how today's
     * password quietly becomes February's, with the person who changed it
     * locked out and nothing on any page saying what happened. The user's
     * configuration is skipped with them, whole — see ConfigBackupUserRestorer
     * for why it is all-or-nothing rather than a merge.
     */
    case KeptDeliberately = 'kept-deliberately';

    /**
     * The path is right and this process could not write it. A read-only
     * secrets mount, the wrong uid, a full disk, or a service that was never
     * given the volume.
     */
    case NotWritable = 'not-writable';

    public function transKey(): string
    {
        return 'admin.config_backup.disposition.' . $this->value;
    }

    /** Whether the import puts the value on disk itself. */
    public function isWritten(): bool
    {
        return match ($this) {
            self::Applied, self::AppliedOnRestart, self::ShadowedByCompose => true,
            self::External, self::KeptDeliberately, self::NotWritable      => false,
        };
    }

    /** Whether this value only becomes real once the stack is restarted. */
    public function needsRestart(): bool
    {
        return match ($this) {
            self::AppliedOnRestart, self::ShadowedByCompose                          => true,
            self::Applied, self::External, self::KeptDeliberately, self::NotWritable => false,
        };
    }

    /**
     * Whether the operator has to go and do something.
     *
     * The count of these is the number the whole rework is judged by: on the
     * supported deployment, restoring onto a fresh instance should produce
     * zero.
     */
    public function needsOperator(): bool
    {
        return match ($this) {
            self::ShadowedByCompose, self::External, self::NotWritable    => true,
            self::Applied, self::AppliedOnRestart, self::KeptDeliberately => false,
        };
    }

    /**
     * Whether this is worth stating without asking for anything — the review
     * shows these quietly, apart from the list of chores.
     */
    public function isNote(): bool
    {
        return self::KeptDeliberately === $this;
    }
}
