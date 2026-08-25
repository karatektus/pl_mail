<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which scopes a provider actually granted.
 *
 * Google's consent screen lets a user decline calendar access and still return
 * a working token; a Microsoft tenant can withhold the same permission. Neither
 * fails the handshake, so the shortfall was only ever discovered days later as
 * calendars that "stopped syncing" with a 403.
 *
 * Nullable, and null means "not known" rather than "nothing granted" —
 * accounts connected before this migration have no record, and nothing may be
 * reported as missing on the strength of a null.
 */
final class Version20260825170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.oauth_granted_scopes, the scopes the provider actually gave us.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD oauth_granted_scopes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP oauth_granted_scopes');
    }
}
