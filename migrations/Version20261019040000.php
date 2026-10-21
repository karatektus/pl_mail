<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes: share links (/n/<token>), optionally password-protected, expiring,
 * or open to editing by whoever holds them.
 */
final class Version20261019040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create note_share';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE note_share (
                id SERIAL NOT NULL,
                note_id INT NOT NULL,
                token VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                can_edit BOOLEAN DEFAULT false NOT NULL,
                view_count INT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_note_share_token ON note_share (token)');
        $this->addSql('CREATE INDEX idx_note_share_note ON note_share (note_id)');
        $this->addSql('ALTER TABLE note_share ADD CONSTRAINT FK_note_share_note FOREIGN KEY (note_id) REFERENCES user_note (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE note_share');
    }
}
