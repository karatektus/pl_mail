<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A folder that drops out of the server's LIST is marked, not deleted.
 *
 * MailboxSyncer used to remove any mailbox row the listing did not name, and
 * message.mailbox_id cascades — so one partial or empty LIST, or a rename done
 * in another client, deleted every message of the folder. The mark and the
 * counter let the syncer wait for the absence to last before believing it.
 */
final class Version20260923101100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox.missing_since and mailbox.missing_syncs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD missing_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD missing_syncs INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP missing_since');
        $this->addSql('ALTER TABLE mailbox DROP missing_syncs');
    }
}
