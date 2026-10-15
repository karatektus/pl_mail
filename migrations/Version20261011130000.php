<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether the inbox tabs show their unread count. See
 * App\Entity\Embeddable\Appearance::$tabUnreadCount.
 *
 * True for every existing user: the count is new in the same release, and it
 * is on unless somebody turns it off.
 */
final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-user switch for the unread count on inbox tabs, on by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_tab_unread_count BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_tab_unread_count');
    }
}
