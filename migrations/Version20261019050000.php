<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes: pictures, drawings and voice recordings (the list; the files live
 * beside it on disk, see NoteMediaStorage).
 */
final class Version20261019050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media to user_note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_note ADD media JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_note DROP media');
    }
}
