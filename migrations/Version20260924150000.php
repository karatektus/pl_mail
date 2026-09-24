<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin → Updates: the channel an installation follows, and the last check of
 * it.
 *
 * A new singleton table, additive. An installation that never visits the page
 * gets its row from the first scheduled check, with the channel left null so it
 * follows the build it is running.
 */
final class Version20260924150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the update_check singleton: the update channel and the last check of it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE update_check (
                id SERIAL NOT NULL,
                singleton INT DEFAULT 1 NOT NULL,
                channel VARCHAR(16) DEFAULT NULL,
                checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                error TEXT DEFAULT NULL,
                status JSONB DEFAULT NULL,
                notified_revision VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_update_check_singleton ON update_check (singleton)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE update_check');
    }
}
