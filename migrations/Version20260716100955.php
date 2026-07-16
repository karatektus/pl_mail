<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716100955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message ADD search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector(\'english\', coalesce(subject, \'\')), \'A\') ||
                setweight(to_tsvector(\'english\', coalesce(from_name, \'\')), \'B\') ||
                setweight(to_tsvector(\'english\', coalesce(from_address, \'\')), \'B\') ||
                setweight(to_tsvector(\'english\', coalesce(body_text, \'\')), \'C\')
            ) STORED');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message DROP search_vector');
    }
}
