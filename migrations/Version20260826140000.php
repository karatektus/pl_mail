<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let the log level be set from the admin page instead of the environment.
 *
 * `APP_DB_LOG_LEVEL` remains the default and remains supported; this is the
 * override an administrator can reach without editing a file on the host and
 * restarting the stack. The moment the level actually needs changing is the
 * moment that is most awkward — something is wrong and the answer is one level
 * further down — which is how installs end up running on `info` for months.
 *
 * A null level means "follow the environment", which is deliberately different
 * from storing the environment's current value: an install that has never
 * chosen keeps following its configuration, including later changes to it.
 */
final class Version20260826140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the log_settings singleton row for the admin-set log level.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE log_settings (
                id SERIAL NOT NULL,
                singleton INT DEFAULT 1 NOT NULL,
                minimum_level VARCHAR(16) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // One row, enforced where it can actually be enforced. Every check done
        // in PHP happens before the insert, and therefore before a concurrent
        // request's insert.
        $this->addSql('CREATE UNIQUE INDEX uniq_log_settings_singleton ON log_settings (singleton)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE log_settings');
    }
}
