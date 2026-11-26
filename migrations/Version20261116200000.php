<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An account colour of the user's own choosing (#638). Null for every
 * existing account, which keeps wearing its palette entry.
 */
final class Version20261116200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a custom colour to mail accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD custom_color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP custom_color');
    }
}
