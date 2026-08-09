<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Infrastructure\Setup\ProcessEnvironment;
use Throwable;

/**
 * The environment half of an instance's configuration: which variables a backup
 * carries, where each one's current value is read from, and — the part this
 * class exists for — where an import puts them back.
 *
 * ## The entrypoint contract, because everything here follows from it
 *
 * plMail's supported deployment does not have hand-edited `.env` entries for
 * any of this. `frankenphp/generate-secrets.sh` mints APP_SECRET,
 * APP_ENCRYPTION_KEY, POSTGRES_PASSWORD and MERCURE_JWT_SECRET on first run
 * into `var/secrets/generated.env` on the `app_secrets` volume;
 * `app:secrets:init` adds the VAPID keypair to the same file;
 * `frankenphp/docker-entrypoint.sh` loads that file into the environment before
 * it execs the server. Every service mounts the volume, and the app runs as
 * root inside its container, so **the app can write it**. That is what makes an
 * import able to apply an environment value at all, and it is why this class
 * writes rather than printing lines to paste.
 *
 * Precedence, highest first, and identical in the entrypoint's shell
 * (`load_generated_secrets` skips any name `printenv` answers for) and in
 * `config/bootstrap_generated_secrets.php` (which skips any name `$_SERVER`
 * already has):
 *
 *   1. the real process environment — compose, the shell, `docker run -e`;
 *   2. `var/secrets/generated.env`;
 *   3. `.env.local` and `.env`, which for these names are deliberately empty.
 *
 * An empty value counts as absent at every level, because compose passes
 * `${APP_SECRET:-}` through as `""` when nobody set one.
 *
 * So a restored value takes effect at the next start **unless** something in
 * the real process environment sets the same name to something non-empty. That
 * is the one case an import still has to warn about, and
 * {@see self::isShadowed()} is how it is detected.
 *
 * ## The list is explicit and not derived from .env
 *
 * Parsing .env would look cleverer and would be wrong twice over: it would
 * sweep in APP_ENV, APP_DEV_USER_PASSWORD and the container's own name —
 * settings that belong to the machine rather than to the installation, and that
 * a restore onto a different machine must not carry — and it would silently
 * start exporting any variable a future .env gained. So the set is named here,
 * and adding to it is a decision somebody makes.
 *
 * **Deliberately absent**, each for a stated reason:
 *   - APP_ENV, APP_DEBUG — what this container is, not what the install is.
 *   - APP_DEV_USER_EMAIL / _PASSWORD — a development fixture.
 *   - APP_CONTAINER_NAME, APP_SECRETS_FILE, JWT_SECRET_KEY, JWT_PUBLIC_KEY,
 *     APP_STORAGE_DIR, APP_SHARE_DIR — filesystem and topology layout. The
 *     target install has its own, and overwriting it with the source's is how
 *     an instance ends up looking for its attachments on a volume that does not
 *     exist. The JWT key CONTENTS travel; the paths they live at do not.
 *   - MERCURE_URL — the in-network address of a sibling container, which is the
 *     target stack's business. MERCURE_PUBLIC_URL does travel: it is what a
 *     browser is told to connect to, and it belongs to the installation.
 */
final readonly class ConfigBackupEnvironment
{
    /**
     * Every variable a config backup carries.
     *
     * Order is the order the review page lists them in, which is roughly "what
     * breaks worst first": the two that make stored data unreadable, then the
     * infrastructure credentials, then the provider registrations, then the
     * things that are merely settings.
     *
     * @var list<string>
     */
    private const array VARIABLES = [
        'APP_ENCRYPTION_KEY',
        'APP_SECRET',
        // DATABASE_URL and POSTGRES_PASSWORD are deliberately NOT here. They
        // are machine-local infrastructure: generated before any user exists,
        // consumed by initdb once, and never applicable on a restore target,
        // whose database has its own working credentials. Exporting them made
        // every plan carry two "external" rows nobody could act on. An OLD
        // backup that still contains them imports fine - unknown env names are
        // classified, not refused - and stays inert.
        'MERCURE_JWT_SECRET',
        'MERCURE_PUBLIC_URL',
        'JWT_PASSPHRASE',
        // MAILER_DSN and MESSENGER_TRANSPORT_DSN are deliberately NOT here
        // either, and for DATABASE_URL's reason rather than a new one: they are
        // machine-local deployment choices. plMail's own compose.yaml supplies
        // a default for both, so every install has one whether or not anybody
        // chose it, and the target's is the one that matches the containers
        // actually running beside it - its relay, its queue. Carrying the
        // source's meant every plan on a stock stack opened with two
        // "shadowed" rows whose only honest instruction was "change this in the
        // compose file you already control". An OLD backup that still contains
        // them imports fine: they are in REFUSED below, classified External and
        // left alone.
        'APP_PUBLIC_URL',
        'VAPID_SUBJECT',
        'VAPID_PUBLIC_KEY',
        'VAPID_PRIVATE_KEY',
        'GOOGLE_OAUTH_CLIENT_ID',
        'GOOGLE_OAUTH_CLIENT_SECRET',
        'GMAIL_PUBSUB_TOPIC',
        'GMAIL_PUBSUB_VERIFICATION_TOKEN',
        'MICROSOFT_OAUTH_CLIENT_ID',
        'MICROSOFT_OAUTH_CLIENT_SECRET',
        'MICROSOFT_OAUTH_TENANT',
        'INTEGRATIONS_ALLOW_HTTP',
        'INTEGRATIONS_ALLOWED_HOSTS',
        'TRUSTED_PROXIES',
        'APP_DEFAULT_TIMEZONE',
        'APP_DB_LOG_LEVEL',
        'DEFAULT_URI',
    ];

    /**
     * The names an import writes into the secrets file and the ones it refuses
     * to, with the refusal's reason attached — so the planner and the applier
     * cannot come to different answers.
     *
     * Five names, and each is a fate the deployment forces rather than a
     * caution:
     *
     *   - APP_ENCRYPTION_KEY is {@see ConfigBackupDisposition::KeptDeliberately}
     *     because the import re-encrypts the backup's credentials under the key
     *     in force here. Writing the backup's key would make the rows this very
     *     import just wrote unreadable at the next start, and `app:secrets:init`
     *     would then refuse to start on them.
     *   - POSTGRES_PASSWORD is {@see ConfigBackupDisposition::External} because
     *     the other half of that change is a ROLE inside Postgres.
     *     `generate-secrets.sh` does rewrite the `postgres_password` file from
     *     `generated.env` on every boot — but the Postgres image reads
     *     `POSTGRES_PASSWORD_FILE` only at initdb, so on a database that
     *     already exists the role keeps the password it was created with.
     *     Restoring another one is a stack that cannot authenticate.
     *   - DATABASE_URL, for the same reason once removed: every backup carries
     *     one, because `bootstrap_generated_secrets.php` assembles it from the
     *     source's POSTGRES_PASSWORD and the exporter finds it in `$_SERVER`.
     *     Written here it would be a DSN pointing at another host's database
     *     with another host's password, and — because it carries a password —
     *     it would suppress the target's own assembly.
     *   - MAILER_DSN and MESSENGER_TRANSPORT_DSN, which stopped being exported
     *     for the reasons stated above VARIABLES and are listed here so the
     *     backups that still carry them are classified rather than acted on.
     *     External and not ShadowedByCompose, although compose is usually what
     *     pins them: the two words say different things, and the true one is
     *     the stronger. Shadowed means "plMail wrote this and something will
     *     beat it" — which would have plMail putting another install's relay
     *     into this one's secrets file to sit there waiting for the pin to come
     *     off. External means "the other half of this lives somewhere plMail is
     *     only a client of", and for a deployment's own compose file that is
     *     exactly right.
     *
     * Three of the five are named by OLD backups only. A document written by
     * this build carries none of them, and the entries stay because a backup
     * outlives the version that wrote it.
     *
     * @var array<string, ConfigBackupDisposition>
     */
    private const array REFUSED = [
        'APP_ENCRYPTION_KEY'      => ConfigBackupDisposition::KeptDeliberately,
        'POSTGRES_PASSWORD'       => ConfigBackupDisposition::External,
        'DATABASE_URL'            => ConfigBackupDisposition::External,
        'MAILER_DSN'              => ConfigBackupDisposition::External,
        'MESSENGER_TRANSPORT_DSN' => ConfigBackupDisposition::External,
    ];

    public function __construct(
        private GeneratedSecretsFile $generated,
        private ProcessEnvironment $processEnvironment,
    ) {
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        return self::VARIABLES;
    }

    /**
     * The values worth putting in a backup: every variable in the inventory
     * that this instance actually has one for.
     *
     * Empty ones are dropped rather than exported as "". A blank line in a
     * backup is indistinguishable from a value somebody cleared on purpose, and
     * the review would show two dozen rows of nothing on an install that
     * configures three things.
     *
     * @return array<string, string>
     */
    public function export(): array
    {
        $values = [];

        foreach (self::VARIABLES as $name) {
            $value = $this->current($name);

            if (null !== $value) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * What this instance is running with right now, or null when nothing set it.
     *
     * Real environment first, generated file second — the same precedence the
     * entrypoint applies when it loads that file, and the same one
     * PublicUrlSetting::current() states.
     */
    public function current(string $name): ?string
    {
        $fromEnvironment = $this->processEnvironment->get($name);

        if (null !== $fromEnvironment) {
            return $fromEnvironment;
        }

        $stored = trim($this->stored()[$name] ?? '');

        return '' === $stored ? null : $stored;
    }

    /**
     * What will become of one restored value.
     *
     * Order of the tests is the order of the reasons' weight: a value plMail
     * must not write is refused before anybody asks whether the file is
     * writable, and a writable file is no argument for writing it.
     */
    public function dispositionFor(string $name): ConfigBackupDisposition
    {
        if (null !== $refused = self::REFUSED[$name] ?? null) {
            return $refused;
        }

        if (false === $this->generated->isWritable()) {
            return ConfigBackupDisposition::NotWritable;
        }

        return $this->isShadowed($name)
            ? ConfigBackupDisposition::ShadowedByCompose
            : ConfigBackupDisposition::AppliedOnRestart;
    }

    /**
     * Whether a value in the real process environment will win over one written
     * into the generated secrets file.
     *
     * The subtlety, and the reason this is not simply "is it in getenv": the
     * entrypoint *exports* the generated file's own contents into the process
     * environment before it execs the server, so in a running container
     * `getenv('APP_SECRET')` answers even when nothing pinned it. What
     * distinguishes the two is where the answer came from — and the file is
     * still sitting there to be compared against:
     *
     *   - the live value equals what the file holds → this is the entrypoint's
     *     own export, and rewriting the file changes what the next start
     *     exports. Not shadowed.
     *   - the live value differs from the file's, or the file has no entry for
     *     the name at all → nothing in the file could have produced it, so
     *     something else in the environment did: compose's `x-app-env`, a
     *     `.env` beside the compose file, or the operator's shell. Shadowed.
     *
     * On plMail's own compose.yaml that identifies exactly the three names
     * pinned to non-empty defaults — MAILER_DSN, MESSENGER_TRANSPORT_DSN and
     * MERCURE_PUBLIC_URL — and clears everything passed through as
     * `${NAME:-}`, which resolves to the empty string and counts as absent. Two
     * of those three are refused before this is ever asked, so MERCURE_PUBLIC_URL
     * is the one name a stock stack can still reach ShadowedByCompose by. The
     * test keeps asserting all three: this method answers a question about the
     * environment, and it has to keep answering it correctly for names the
     * inventory no longer carries.
     *
     * **The one case it cannot see**: an operator who pinned a name in compose
     * to the very same string the generated file already holds. The two are
     * then indistinguishable from here, and the restored value would be
     * shadowed without a warning. It requires having copied a generated secret
     * into the compose file by hand, and it is stated in the documentation
     * rather than guessed at here.
     */
    public function isShadowed(string $name): bool
    {
        $live = $this->processEnvironment->get($name);

        if (null === $live) {
            return false;
        }

        $stored = $this->stored()[$name] ?? null;

        return null === $stored || false === hash_equals(trim($stored), $live);
    }

    /**
     * Write the restored values into the file the entrypoint owns, in one pass.
     *
     * Returns the names that went in, so the caller reports what it did rather
     * than what it intended. A failure is a RuntimeException from
     * GeneratedSecretsFile and belongs to the caller — see
     * ConfigBackupImporter, which demotes the whole batch to
     * {@see ConfigBackupDisposition::NotWritable} rather than aborting an import
     * whose database half has already been committed.
     *
     * @param array<string, string> $values
     */
    public function apply(array $values): void
    {
        $this->generated->setMany($values);
    }

    /**
     * The line to paste, in the form the target actually reads it.
     *
     * Still offered — for the three fates where an operator does have somewhere
     * to put it: a value pinned in compose that has to be changed there, one
     * that belongs to an external system, and one on an install whose secrets
     * volume this process cannot write.
     *
     * Quoted whenever the value contains anything a shell or a dotenv parser
     * would take an interest in — a DSN with a `#` in the password is the case
     * that bites, because Symfony's dotenv treats it as a comment and the
     * operator gets a truncated password with no error anywhere.
     */
    public function instructionFor(string $name, string $value): string
    {
        $needsQuoting = 1 === preg_match('/[\s#"\'$`\\\\]/', $value);

        return sprintf('%s=%s', $name, $needsQuoting ? '"' . addcslashes($value, '"\\$`') . '"' : $value);
    }

    /**
     * The generated file's contents, or an empty set when it cannot be read.
     *
     * Swallowed rather than propagated: a review page that 500s because the
     * secrets volume is missing tells an operator nothing, whereas a plan in
     * which every environment value reads "not writable" tells them exactly
     * what is wrong.
     *
     * @return array<string, string>
     */
    private function stored(): array
    {
        try {
            return $this->generated->read();
        } catch (Throwable) {
            return [];
        }
    }
}
