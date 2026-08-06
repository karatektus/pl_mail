<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Enum\Backup\ConfigBackupObstacle;
use App\Infrastructure\Setup\GeneratedSecretsFile;

/**
 * The environment half of an instance's configuration: which variables a
 * backup carries, where each one's current value is read from, and why not one
 * of them can be applied by clicking a button.
 *
 * **The list is explicit and not derived from .env.** Parsing .env would look
 * cleverer and would be wrong twice over: it would sweep in APP_ENV,
 * APP_DEV_USER_PASSWORD and the container's own name — settings that belong to
 * the machine rather than to the installation, and that a restore onto a
 * different machine must not carry — and it would silently start exporting any
 * variable a future .env gained, including one whose value is a path into a
 * layout the target does not have. So the set is named here, and adding to it
 * is a decision somebody makes.
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
 *
 * **Nothing here is ever written by an import**, and the class says so per
 * variable rather than leaving it to a comment. A PHP process cannot change its
 * own environment in any way the next request would see, and the file the
 * entrypoint reads at container start is not the same promise as "applied".
 * The obstacle each variable carries is what the review page shows.
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
        'DATABASE_URL',
        'POSTGRES_PASSWORD',
        'MERCURE_JWT_SECRET',
        'MERCURE_PUBLIC_URL',
        'JWT_PASSPHRASE',
        'MAILER_DSN',
        'MESSENGER_TRANSPORT_DSN',
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
     * The two that are not merely unwritable but actively dangerous to write,
     * and the reason each carries its own obstacle rather than the generic one.
     *
     * @var array<string, ConfigBackupObstacle>
     */
    private const array SPECIAL_OBSTACLES = [
        'APP_ENCRYPTION_KEY' => ConfigBackupObstacle::EncryptionKeyInUse,
        'POSTGRES_PASSWORD'  => ConfigBackupObstacle::ExternalSystem,
        'DATABASE_URL'       => ConfigBackupObstacle::ExternalSystem,
    ];

    public function __construct(
        private GeneratedSecretsFile $generated,
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
     * PublicUrlSetting::current() states: an operator who manages a value
     * themselves never has a generated one substituted underneath them.
     */
    public function current(string $name): ?string
    {
        $fromEnvironment = trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''));

        if ('' !== $fromEnvironment) {
            return $fromEnvironment;
        }

        $stored = trim($this->generated->read()[$name] ?? '');

        return '' === $stored ? null : $stored;
    }

    public function obstacleFor(string $name): ConfigBackupObstacle
    {
        return self::SPECIAL_OBSTACLES[$name] ?? ConfigBackupObstacle::ProcessEnvironment;
    }

    /**
     * The line to paste, in the form the target actually reads it.
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
}
