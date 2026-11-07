<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Adjust panel's two numbers for a draft's images (#502):
 * Message::$imageEdge and $imageQuality, set only when the size is Custom.
 *
 * Empty for every row that exists. Nullable columns with no default are a
 * catalogue change in Postgres, instant on any size of table.
 */
final class Version20261107200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message.image_edge and message.image_quality, for a custom image size';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD image_edge SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD image_quality SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP image_edge');
        $this->addSql('ALTER TABLE message DROP image_quality');
    }
}
