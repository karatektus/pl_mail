<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calendar::$syncTokenRejections: how many runs in a row found the stored sync
 * token refused. A read-only calendar whose provider refuses every token (a
 * Google holiday calendar) is then read every six hours instead of every
 * fifteen minutes.
 *
 * Zero for every row that exists, which is the honest starting point: two more
 * runs on such a calendar put it in the slow lane.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds calendar.sync_token_rejections, for calendars whose provider refuses every sync token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD sync_token_rejections INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar DROP sync_token_rejections');
    }
}
