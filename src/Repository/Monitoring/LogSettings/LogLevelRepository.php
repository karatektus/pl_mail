<?php

declare(strict_types=1);

namespace App\Repository\Monitoring\LogSettings;

use Doctrine\DBAL\Connection;

/**
 * The minimum log level an administrator stored, for LogLevelResolver.
 *
 * Raw DBAL and apart from LogSettingsRepository for the same reason the log
 * handler writes raw: it is asked from inside logging, where the ORM may be
 * the thing that is failing.
 */
final readonly class LogLevelRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** The stored level's name, or false when nothing is stored. */
    public function storedMinimumLevel(): mixed
    {
        return $this->connection->fetchOne('SELECT minimum_level FROM log_settings ORDER BY id ASC LIMIT 1');
    }
}
