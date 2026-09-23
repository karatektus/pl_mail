<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The unread count every folder sync ends with, without scanning the folder.
 *
 * MessageSyncer recounts `mailbox_id = ? AND seen_at IS NULL` after every
 * sync of every folder. The unique (mailbox_id, imap_uid) index finds the
 * folder's rows but then has to visit each one to test seen_at; a partial
 * index holds only the unread rows, which in a well-kept mailbox is almost
 * none of them. The total count already has an index to use.
 *
 * Migration-only, like the other partial indexes on this table: the ORM
 * mapping cannot express the WHERE clause.
 */
final class Version20260923101400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial index on message(mailbox_id) for unread rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_message_unseen_mailbox ON message (mailbox_id) WHERE seen_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_unseen_mailbox');
    }
}
