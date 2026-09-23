<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the sidebar badges, the list order and the snooze sweep.
 *
 * Every mail page runs about a dozen aggregate queries over message_thread —
 * countUnreadPerRole(), countUnreadPerUserLabel(), countUnreadInUserLabels(),
 * countUnreadForStarred(), the countNew*() family, the category tab counts —
 * and the lists under them sort on last_message_at. The table had an index on
 * account_id and nothing else those queries could use, so every badge was an
 * account-wide scan filtered row by row.
 *
 *   • unread: `(account_id) WHERE unread_count > 0`. Every unread badge asks
 *     "this account's threads holding something unread", and that is a small
 *     fraction of a mailbox — a partial index is those rows and nothing else.
 *
 *   • list order: `(account_id, last_message_at DESC, id DESC)`, the exact
 *     ORDER BY of ListSortOrder::applyTo() behind the account filter, so a page
 *     of fifty is read in order instead of sorted out of the whole account.
 *     Scanned backwards it serves the oldest-first order too.
 *
 *   • starred: `(account_id) WHERE starred_at IS NOT NULL`, for the Starred
 *     list and its badge — a handful of rows per account.
 *
 *   • snoozed: `(snoozed_until) WHERE snoozed_until IS NOT NULL`. findDueSnoozed()
 *     runs every minute from the scheduler with `snoozed_until <= now ORDER BY
 *     snoozed_until`; without this it scanned every thread of every user to
 *     find the few that are snoozed at all.
 *
 * Partial indexes live here only, like idx_message_flags_touched_at: the ORM
 * mapping cannot express a WHERE clause.
 */
final class Version20260923141700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial and composite message_thread indexes for badges, list order and the snooze sweep';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_message_thread_account_unread ON message_thread (account_id) WHERE unread_count > 0');
        $this->addSql('CREATE INDEX idx_message_thread_account_last_message ON message_thread (account_id, last_message_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_message_thread_account_starred ON message_thread (account_id) WHERE starred_at IS NOT NULL');
        $this->addSql('CREATE INDEX idx_message_thread_snoozed_until ON message_thread (snoozed_until) WHERE snoozed_until IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_thread_account_unread');
        $this->addSql('DROP INDEX idx_message_thread_account_last_message');
        $this->addSql('DROP INDEX idx_message_thread_account_starred');
        $this->addSql('DROP INDEX idx_message_thread_snoozed_until');
    }
}
