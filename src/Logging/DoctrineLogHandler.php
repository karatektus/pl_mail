<?php

declare(strict_types=1);

namespace App\Logging;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

    protected function write(LogRecord $record): void
    {
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
