<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Encrypted additional OpenAI-compatible connection headers'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD openai_headers TEXT DEFAULT NULL, ADD embedding_headers TEXT DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP openai_headers, DROP embedding_headers');
    }
}
