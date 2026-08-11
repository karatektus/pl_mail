<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The two dates that let a sync notice mail leaving.
 *
 * `message.vanished_at` is when a folder listing last failed to produce a row
 * where it says it is, and `mailbox.swept_at` is when that folder was last
 * listed in full. Neither is a deletion on its own — the first is evidence and
 * the second is coverage, and only both together, plus a per-row confirmation
 * from the server, let VanishedMessageReconciler erase anything.
 *
 * Both nullable, and null is the safe reading of both. A row that has never
 * gone missing has no vanished_at; a folder that has never been listed in full
 * has no swept_at, and MailboxRepository::earliestSweepAcross() answers null
 * for the whole account while even one of them is in that state, which suspends
 * reaping until the first complete pass has happened. So an install that takes
 * this migration deletes nothing until it has read every folder at least once,
 * without any special case for the upgrade.
 *
 * down() drops both columns. That is genuinely reversible: they are derived
 * state which the next sweep rebuilds from the server, and nothing else reads
 * them.
 */
final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a message went missing from its folder and when each folder was last listed in full';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD vanished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD swept_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // The reaper's only query: rows of one account that carry a mark,
        // oldest first. Without this it is a sequential scan of every message
        // in the account on every poll of every folder.
        $this->addSql('CREATE INDEX idx_message_vanished ON message (account_id, vanished_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_vanished');
        $this->addSql('ALTER TABLE message DROP vanished_at');
        $this->addSql('ALTER TABLE mailbox DROP swept_at');
    }
}
