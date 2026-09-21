<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ledger for one-time upgrade tasks — see UpgradeTaskInterface.
 *
 * A repair that has to happen once per installation had nowhere to record that
 * it had, so the only two options were a migration doing service-dependent
 * work, or a nightly sweep for a job that is finished after the first night.
 * This is the third: a row per named task, the same shape as Doctrine's own
 * migration ledger, readable from psql when the application will not start.
 *
 * `name` is unique because the claim has to be atomic — four containers reach
 * the scheduler within milliseconds of each other, and every check done in PHP
 * happens before its own INSERT and therefore before anybody else's.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ledger of one-time upgrade tasks, so each runs once per installation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE upgrade_task_run (
                id SERIAL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_upgrade_task_run_name ON upgrade_task_run (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE upgrade_task_run');
    }
}
