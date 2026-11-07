<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The list preview of a message, kept (Message::$preview, $previewKey).
 *
 * Both empty for every message that exists: they are filled the first time a
 * message is the newest of a listed row, so there is nothing to backfill and
 * nothing a long migration would have to rewrite. Adding nullable columns
 * with no default is a catalogue change in Postgres, instant on any size of
 * table.
 */
final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message.preview and message.preview_key, the stored list preview';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD preview TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD preview_key SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP preview');
        $this->addSql('ALTER TABLE message DROP preview_key');
    }
}
