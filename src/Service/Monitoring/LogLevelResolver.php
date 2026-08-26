<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Doctrine\DBAL\Connection;
use Monolog\Level;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * The lowest level this installation keeps in its log table, right now.
 *
 * The admin page writes a row; `APP_DB_LOG_LEVEL` is the default when there is
 * none. This is what turns those two into one answer, and it is consulted on
 * EVERY log record — which sets everything about how it is written.
 *
 * WHY IT CACHES, AND WHY ONLY BRIEFLY
 * ───────────────────────────────────
 * A query per log line would put a database round-trip inside the thing that
 * runs when the database is in trouble. A value cached for the life of the
 * process would be worse in the other direction: workers are long-lived, so an
 * admin lowering the level would see the web container obey and every worker
 * ignore it until the next deploy — and the worker logs are usually the ones
 * they lowered it for.
 *
 * So: a few seconds. Cheap enough to be free at any sane log volume, short
 * enough that "I changed it and nothing happened" is never true for long.
 *
 * WHY IT USES DBAL AND CANNOT THROW
 * ─────────────────────────────────
 * Same reason DoctrineLogHandler does: this is reached from error paths where
 * the EntityManager may be closed or mid-rollback, and where the table may not
 * exist at all — an install booting for the first time logs before its
 * migrations have run. Every failure falls back to the environment's value,
 * because a logger that throws while deciding whether to log is worse than one
 * that is briefly out of date.
 */
final class LogLevelResolver
{
    /**
     * How long a resolved level is trusted.
     *
     * Deliberately short. See the class docblock: this is the delay between an
     * admin changing the setting and a background worker honouring it.
     */
    private const int TTL_SECONDS = 10;

    private ?Level $cached = null;

    private float $cachedAt = 0.0;

    /**
     * Re-entrancy guard. The lookup below talks to the database, and anything
     * the database driver logs would arrive back here asking what the level is.
     * Monolog's channel list already keeps `doctrine` away from the handler
     * that calls this, so this is the belt to that pair of braces.
     */
    private bool $resolving = false;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'APP_DB_LOG_LEVEL')]
        private readonly string $fallback = 'warning',
    ) {
    }

    public function level(): Level
    {
        if (null !== $this->cached && (microtime(true) - $this->cachedAt) < self::TTL_SECONDS) {
            return $this->cached;
        }

        $resolved = $this->fromDatabase() ?? $this->fromEnvironment();

        $this->cached   = $resolved;
        $this->cachedAt = microtime(true);

        return $resolved;
    }

    /**
     * Forget what was resolved, so the next record asks again.
     *
     * Called by the admin action that writes the setting, so the process the
     * change was made in obeys it immediately rather than after the TTL. Other
     * processes still wait, which is what the TTL is for.
     */
    public function forget(): void
    {
        $this->cached = null;
    }

    private function fromDatabase(): ?Level
    {
        if (true === $this->resolving) {
            return null;
        }

        $this->resolving = true;

        try {
            $name = $this->connection->fetchOne(
                'SELECT minimum_level FROM log_settings ORDER BY id ASC LIMIT 1',
            );

            return false === is_string($name) ? null : self::parse($name);
        } catch (Throwable) {
            // No table yet, no database, or one that is refusing. The
            // environment's value is a perfectly good answer and this is not
            // the place to complain about any of it.
            return null;
        } finally {
            $this->resolving = false;
        }
    }

    private function fromEnvironment(): Level
    {
        return self::parse($this->fallback) ?? Level::Warning;
    }

    /** A Monolog level from its name, or null if it is not one. */
    private static function parse(string $name): ?Level
    {
        try {
            return Level::fromName(ucfirst(strtolower(trim($name))));
        } catch (Throwable) {
            return null;
        }
    }
}
