<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Separate optional OpenAI-compatible generation from Ollama embeddings'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ai_settings ADD chat_provider VARCHAR(32) DEFAULT 'ollama' NOT NULL, ADD openai_base_url VARCHAR(255) DEFAULT NULL, ADD openai_api_token TEXT DEFAULT NULL, ADD openai_model VARCHAR(128) DEFAULT NULL");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP chat_provider, DROP openai_base_url, DROP openai_api_token, DROP openai_model');
    }
}
