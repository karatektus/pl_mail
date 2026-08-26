<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Room for a second opinion about what a message is.
 *
 * Deliberately beside `category` rather than in it. The category is what the
 * deterministic rules produced, it is what every list query filters on, and it
 * has to stay explicable without reaching for a machine that may since have
 * been switched off. Overwriting it would make a tab's contents depend on which
 * model happened to be installed the week the mail arrived.
 *
 * Both columns are null on every existing row and stay null on every install
 * that never switches this on.
 */
final class Version20260826190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.ai_category and message.ai_categorised_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD ai_category VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD ai_categorised_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // The one query that reads these looks for "asked already?" over a
        // batch of ids, and a partial index keeps it off the 99% of rows that
        // were never asked about — which on an install with this switched off
        // is every row there will ever be.
        $this->addSql('CREATE INDEX idx_message_ai_categorised ON message (ai_categorised_at) WHERE ai_categorised_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_ai_categorised');
        $this->addSql('ALTER TABLE message DROP ai_category');
        $this->addSql('ALTER TABLE message DROP ai_categorised_at');
    }
}
