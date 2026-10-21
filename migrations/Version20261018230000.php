<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes: labels, Keep's way of grouping notes ("Work", "Shopping").
 */
final class Version20261018230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add labels to user_note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_note ADD labels JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_note DROP labels');
    }
}
