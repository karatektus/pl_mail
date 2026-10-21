<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes: reminders — when to remind, and when the reminder was sent.
 */
final class Version20261019030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reminder_at and reminder_sent_at to user_note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_note ADD reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_note ADD reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_note_reminder ON user_note (reminder_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_note_reminder');
        $this->addSql('ALTER TABLE user_note DROP reminder_at');
        $this->addSql('ALTER TABLE user_note DROP reminder_sent_at');
    }
}
