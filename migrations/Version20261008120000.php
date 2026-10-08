<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What Admin → Performance needs to say how late mail arrived and why: when
 * the provider accepted each message, what started the sync that stored it,
 * and where it was filed at the time. See Message::$providerAcceptedAt.
 *
 * Three nullable columns with no default, which rewrite no rows, and one
 * index, which does read the table once. It is the index both arrival queries
 * start from — they used idx_message_account_received_at while they measured
 * from received_at, and no longer do.
 *
 * Not filled in here. The accept time is in the stored headers and has to be
 * parsed out of them, which is work for PHP and not for a migration; the
 * upgrade task `message-arrival` does it for the last week of mail, which is
 * as far back as the page looks. Nothing can recover what started a sync that
 * has already run, and the page shows a dash.
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record how each message arrived: message.provider_accepted_at, arrived_by, arrived_in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD provider_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD arrived_by VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD arrived_in VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_account_accepted_at ON message (account_id, provider_accepted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_account_accepted_at');
        $this->addSql('ALTER TABLE message DROP provider_accepted_at');
        $this->addSql('ALTER TABLE message DROP arrived_by');
        $this->addSql('ALTER TABLE message DROP arrived_in');
    }
}
