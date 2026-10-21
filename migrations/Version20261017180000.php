<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes: the user's Keep-style notes, served over JMAP as Note/get and Note/set.
 */
final class Version20261017180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_note for Keep-style notes with checklists and links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_note (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                items JSONB DEFAULT '[]' NOT NULL,
                color VARCHAR(16) DEFAULT NULL,
                pinned BOOLEAN DEFAULT false NOT NULL,
                archived BOOLEAN DEFAULT false NOT NULL,
                trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                links JSONB DEFAULT '[]' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_user_note_usr ON user_note (usr_id)');
        $this->addSql('ALTER TABLE user_note ADD CONSTRAINT FK_user_note_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_note');
    }
}
