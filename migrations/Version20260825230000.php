<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which scopes a connected service actually granted.
 *
 * The mail accounts gained this in v0.1.28 after a partial Google grant broke
 * calendars and mail writes in silence. The file stores and photo libraries had
 * exactly the same blindness and none of the attention.
 *
 * Nullable, and null means "not known" rather than "nothing granted".
 */
final class Version20260825230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add integration.oauth_granted_scopes, the scopes the service actually gave us.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration ADD oauth_granted_scopes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration DROP oauth_granted_scopes');
    }
}
