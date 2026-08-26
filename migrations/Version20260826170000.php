<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where an installation records which model host it talks to, if any.
 *
 * Every column starts off or empty, and plMail is a complete mail client in
 * that state — this table existing changes nothing until an administrator fills
 * it in. Which is the point: an existing install gets the migration on boot and
 * notices nothing at all.
 *
 * The token is encrypted at rest like every other credential here. Ollama has
 * no authentication of its own, but people put it behind a reverse proxy that
 * does, and a feature that could not be used behind one would push them towards
 * exposing it instead.
 */
final class Version20260826170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_settings: the model host, its models, and the per-feature switches.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_settings (
                id SERIAL NOT NULL,
                singleton INT DEFAULT 1 NOT NULL,
                is_enabled BOOLEAN DEFAULT FALSE NOT NULL,
                base_url VARCHAR(255) DEFAULT NULL,
                api_token TEXT DEFAULT NULL,
                chat_model VARCHAR(128) DEFAULT NULL,
                embedding_model VARCHAR(128) DEFAULT NULL,
                embedding_dimensions INT DEFAULT NULL,
                search_enabled BOOLEAN DEFAULT FALSE NOT NULL,
                categorisation_enabled BOOLEAN DEFAULT FALSE NOT NULL,
                writing_help_enabled BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // One row, held where it can actually be held.
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_settings_singleton ON ai_settings (singleton)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_settings');
    }
}
