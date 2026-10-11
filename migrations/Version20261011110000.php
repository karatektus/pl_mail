<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extra HTTP headers for the model host. See App\Domain\Ai\ConnectionHeaders.
 *
 * TEXT because the column is encrypted, like the token beside it: what is
 * stored is ciphertext, and its length is not the length of what was typed.
 * Null for every existing row, which is "send nothing extra" — what they do
 * today.
 */
final class Version20261011110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the extra request headers for the model host';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD headers TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP headers');
    }
}
