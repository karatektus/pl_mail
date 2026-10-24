<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow every Outlook folder again, with a delta that reports a read.
 *
 * A folder is followed by a delta link, and the link carries the $select the
 * chain began with. Until now that left out isRead and flag, and Exchange
 * does not send an entry for a message whose only change is one of those, so a
 * message read or unread in Outlook, on a phone or by a rule was never heard
 * of here. New chains now ask for both (GraphApiClient::DELTA_SELECT); the
 * ones already stored cannot be widened, only replaced.
 *
 * Clearing the links is how: a folder without one is the import's, the next
 * sync plans it, and the import lists the folder from the top with the new
 * select and ends by storing a link that has it. What is already stored is
 * recognised and not fetched again, so a mailbox that lost nothing costs its
 * listing pages. Every listed message also has its read state and star
 * compared with the server's, which settles the rows that drifted while
 * nothing was listening.
 *
 * Until a folder's listing has finished the account shows as importing, which
 * is what it is, and mail that arrives meanwhile is still picked up (see
 * GraphApiSyncer::receivedSince()).
 */
final class Version20261014110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hand every Outlook folder back to the import once, so it is followed by a delta that reports read state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE account
            SET graph_delta_links = '{}'
            WHERE auth_type = 'oauth2' AND oauth_provider = 'microsoft'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to put back: the old links were the ones that could not hear
        // a read, and a listing that has run again is no less complete.
    }
}
