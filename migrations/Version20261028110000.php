<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Outlook categories a message was last seen with on Exchange (#221 step
 * 4): a mirror for telling a change made in Outlook from one plMail made. See
 * Message::$graphCategories.
 *
 * Nullable with no default: existing rows read "not known", nothing is written
 * to them, and the first delta that mentions their categories fills it in
 * without changing any label. Dropping it loses nothing but that.
 */
final class Version20261028110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mirror of a message\'s Outlook categories: message.graph_categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD graph_categories JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP graph_categories');
    }
}
