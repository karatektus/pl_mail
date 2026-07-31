<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Logging;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Persists Monolog records to the log_entry table so every container's
 * logs aggregate in one queryable place for the admin dashboard.
 *
 * Uses DBAL directly (not the ORM): log writes frequently happen in error
 * paths where the EntityManager is closed or mid-rollback, and must never
 * depend on its state. Insert failures are swallowed — a broken DB must
 * not cascade into a logging loop, and stderr logging still exists.
 *
 * Minimum level comes from APP_DB_LOG_LEVEL (default: warning); the source
 * container name from APP_CONTAINER_NAME (set per service in compose).
 */
final class DoctrineLogHandler extends AbstractProcessingHandler
{
    private const int MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'APP_CONTAINER_NAME')]
        private readonly string     $source = 'app',
        #[Autowire(env: 'APP_DB_LOG_LEVEL')]
        string                      $minimumLevel = 'warning',
    ) {
        parent::__construct(Level::fromName(ucfirst(strtolower($minimumLevel))), true);
    }

    /**
     * Is this a client asking for something that is not there?
     *
     * Symfony logs an unmatched route at error level, so these land in the
     * dashboard looking like faults in the application. They are not: a browser
     * requesting /favicon.ico on a page that declares no icon, a bot probing
     * /wp-login.php, a stale bookmark. The dashboard is what an operator checks
     * when something is wrong, and it stops being read the moment it is mostly
     * this.
     *
     * The other handlers already drop them — monolog.yaml sets
     * `excluded_http_codes: [404, 405]` on the fingers_crossed handler feeding
     * stderr — but that option belongs to fingers_crossed and this handler is a
     * plain service, so it never applied here. Hence doing it by hand.
     *
     * Only the two codes that mean "no such thing". A 403 stays: someone
     * reaching for what they may not have is worth an operator's attention.
     */
    private static function isNotFound(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;

        return $exception instanceof NotFoundHttpException
            || $exception instanceof MethodNotAllowedHttpException;
    }

    protected function write(LogRecord $record): void
    {
        if (self::isNotFound($record)) {
            return;
        }

        try {
            $context = json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            $this->connection->insert('log_entry', [
                'channel'    => mb_substr($record->channel, 0, 64),
                'level'      => $record->level->value,
                'level_name' => mb_substr($record->level->getName(), 0, 32),
                'message'    => mb_substr($record->message, 0, self::MAX_MESSAGE_LENGTH),
                'context'    => false !== $context ? $context : null,
                'source'     => mb_substr($this->source, 0, 64),
                'created_at' => $record->datetime->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Never let logging failures cascade.
        }
    }
}
