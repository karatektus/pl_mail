<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which dialect the model host speaks. See App\Domain\Enum\Ai\AiProvider.
 *
 * One column, and every existing row gets `ollama` — which is what every
 * existing installation is talking to, because until now there was nothing
 * else it could be. Nothing about a configured installation changes.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which dialect the model host speaks, Ollama for every existing row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ai_settings ADD provider VARCHAR(16) DEFAULT 'ollama' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP provider');
    }
}
