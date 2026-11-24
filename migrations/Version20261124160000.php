<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * iCloud calendars and address books read again from the start (#637).
 *
 * Until #637 the CalDAV and CardDAV drivers sent absolute URLs in the hrefs
 * of a multiget. iCloud answers those with a 404 for every event, so every
 * iCloud calendar "synced" with no events in it and stored the sync token all
 * the same. From that token on, polls asked only for what changed after it,
 * so the events that were never read would not arrive even with the drivers
 * fixed. Clearing the token makes the next sync a full read, which brings
 * them in. iCloud address books refused the multiget outright (400), so most
 * have no token to clear; one that does is read again too, for the same
 * reason.
 *
 * Matched by host, because CalDAV connections have no provider of their own:
 * iCloud is a plain CalDAV connection to caldav.icloud.com, and the calendars
 * it discovers live on partition hosts (pNN-caldav.icloud.com,
 * pNN-contacts.icloud.com), which is what remote_id holds.
 *
 * Harmless for a calendar that was fine: a full read replaces what is there
 * with what the server has, and events made in plMail were already pushed to
 * iCloud, so they are in that listing.
 */
final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Read iCloud calendars and address books again from the start, after the multiget fix (#637)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE calendar SET sync_token = NULL
             WHERE sync_token IS NOT NULL AND remote_id ~* '^https?://([^/]*\.)?icloud\.com(:[0-9]+)?/'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE address_book SET sync_token = NULL
             WHERE sync_token IS NOT NULL AND remote_id ~* '^https?://([^/]*\.)?icloud\.com(:[0-9]+)?/'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the next sync stores a new token.
    }
}
