<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A second model host, for search. See
 * App\Entity\Ai\AiSettings::$embeddingOwnConnection.
 *
 * Five columns, and the first is the one that matters: it is false for every
 * existing row, and while it is false the other four are not read. Search goes
 * on using the one model host an installation has, exactly as before.
 *
 * No stored vector is touched. A vector belongs to the model that made it, not
 * to the address it was made at, so nothing here needs re-indexing.
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional second model host for search, unused until switched on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD embedding_own_connection BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE ai_settings ADD embedding_provider VARCHAR(16) DEFAULT 'ollama' NOT NULL");
        $this->addSql('ALTER TABLE ai_settings ADD embedding_base_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ai_settings ADD embedding_api_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE ai_settings ADD embedding_headers TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP embedding_own_connection');
        $this->addSql('ALTER TABLE ai_settings DROP embedding_provider');
        $this->addSql('ALTER TABLE ai_settings DROP embedding_base_url');
        $this->addSql('ALTER TABLE ai_settings DROP embedding_api_token');
        $this->addSql('ALTER TABLE ai_settings DROP embedding_headers');
    }
}
