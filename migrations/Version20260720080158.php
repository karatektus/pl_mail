<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260720080158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change from_address to TEXT and recreate generated search_vector column';
    }

    public function up(Schema $schema): void
    {
        // Drop index if it exists
        $this->addSql('DROP INDEX IF EXISTS idx_message_search_vector');

        // Drop generated column
        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        // Change type
        $this->addSql('ALTER TABLE message ALTER COLUMN from_address TYPE TEXT');

        // Recreate generated column
        $this->addSql(<<<'SQL'
ALTER TABLE message
ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(body_text, '')), 'C')
) STORED
SQL);

        // Recreate index
        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_message_search_vector');
        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        $this->addSql('ALTER TABLE message ALTER COLUMN from_address TYPE VARCHAR(320)');

        $this->addSql(<<<'SQL'
ALTER TABLE message
ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(body_text, '')), 'C')
) STORED
SQL);

        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');
    }
}
