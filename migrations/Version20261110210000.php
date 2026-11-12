<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Look through every IMAP folder once for mail the sync gave up on (#546).
 *
 * Before Version20261110200000 capped the search vector, a new message with
 * megabytes of text could not be stored: the trigger's tsvector went over
 * Postgres's 1 MB limit, the sync tried the message five times and then
 * passed over it for good. Nothing asked for that UID again, so the mail was
 * missing with no way back.
 *
 * Each folder's history import is planned again from the top, which is what
 * MailboxRepository::reopenImport() does and app:mail:fetch-missing runs on
 * demand. The import asks the server which UIDs exist and fetches only those
 * not stored here, leaving out mail plMail has deleted and the server not yet
 * (#427); on a folder with nothing missing it is one search and done. The
 * same conditions as reopenImport(), in SQL, because a migration does not
 * reach the ORM — including leaving out a folder that never held mail
 * (last_seen_uid 0), whose floor would otherwise be 1 (#578).
 *
 * It runs in the background from each account's next sync, and the topbar
 * shows it for as long as there is something to fetch.
 */
final class Version20261110210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan every IMAP folder\'s import again from the top, to fetch mail the sync gave up on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE mailbox
               SET import_floor_uid = last_seen_uid + 1,
                   import_total = NULL,
                   import_remaining = NULL,
                   import_page_attempts = 0
             WHERE is_sync_enabled = true
               AND is_selectable = true
               AND missing_since IS NULL
               AND last_seen_uid > 0
               AND import_floor_uid IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: an import that finds nothing missing finishes at once.
    }
}
