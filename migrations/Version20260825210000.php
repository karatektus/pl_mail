<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a mail account say that it has stopped syncing.
 *
 * It could not. A calendar has recorded its sync failures — a message, a count,
 * a backoff — for as long as it has synced; the mailbox, which is the thing the
 * application is for, had a `last_synced_at` column that nothing wrote and
 * nothing read. An IMAP server refusing connections for a week looked exactly
 * like one that synced a minute ago.
 *
 * The count starts at zero for every existing account, which is the honest
 * value: nobody has been counting, so nobody knows, and a first failure after
 * this lands will start from one and be reported on its own terms.
 */
final class Version20260825210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.last_sync_error and account.sync_failure_count.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD last_sync_error TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD sync_failure_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP last_sync_error');
        $this->addSql('ALTER TABLE account DROP sync_failure_count');
    }
}
