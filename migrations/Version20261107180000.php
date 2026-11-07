<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Smaller images in a draft (#502): the size the person chose
 * (Message::$imageSize), and where each scaled image's original is kept
 * (MessagePart::$originalStoragePath, $originalSize).
 *
 * All three empty for every row that exists: nothing was ever scaled before
 * this. Nullable columns with no default are a catalogue change in Postgres,
 * instant on any size of table.
 */
final class Version20261107180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message.image_size and message_part.original_storage_path / original_size, for images sent smaller';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD image_size VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE message_part ADD original_storage_path TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_part ADD original_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP image_size');
        $this->addSql('ALTER TABLE message_part DROP original_storage_path');
        $this->addSql('ALTER TABLE message_part DROP original_size');
    }
}
