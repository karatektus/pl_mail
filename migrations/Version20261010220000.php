<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a folder's first import has got to. See
 * App\Entity\Mail\Mailbox::$importFloorUid.
 *
 * Four columns, and one statement that matters: every folder that has already
 * finished a sync is marked as having nothing left to import. Left null it
 * would read as "nobody has looked yet", and the first sync after upgrading
 * would plan an import of every folder of every account — harmless, since a
 * stored message is not fetched twice, but a walk of every UID of every folder
 * to find that out.
 *
 * A folder that was still in its first, oldest-first pass when the upgrade
 * landed has no synced_at and stays null. It is planned again from the top,
 * newest first, and what it had already stored is skipped.
 */
final class Version20261010220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mailbox import markers, and mark every synced folder as imported';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD import_floor_uid INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD import_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD import_remaining INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD import_page_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE mailbox SET import_floor_uid = 0 WHERE synced_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP import_floor_uid');
        $this->addSql('ALTER TABLE mailbox DROP import_total');
        $this->addSql('ALTER TABLE mailbox DROP import_remaining');
        $this->addSql('ALTER TABLE mailbox DROP import_page_attempts');
    }
}
