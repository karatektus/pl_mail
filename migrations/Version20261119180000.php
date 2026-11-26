<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message_part.content_type, filename and content_id become TEXT (#674).
 *
 * All three are the sender's, and nothing on the way in bounds them. A
 * filename longer than 255 characters — here an RFC 2231 continuation that
 * Gmail handed over as the whole unjoined header — was refused on INSERT, and
 * with it the other 49 messages of its batch, every hour, so the import sat
 * one batch short of done for good.
 *
 * None of the three is indexed, so there is no btree limit to plan for (#453
 * had to). In Postgres varchar → TEXT is a catalogue change, not a rewrite.
 *
 * The way down cuts what does not fit rather than failing on it: a part's name
 * shortened is a lesser loss than a migration that cannot be undone.
 */
final class Version20261119180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make message_part content_type, filename and content_id TEXT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_part ALTER content_type TYPE TEXT');
        $this->addSql('ALTER TABLE message_part ALTER filename TYPE TEXT');
        $this->addSql('ALTER TABLE message_part ALTER content_id TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_part ALTER content_type TYPE VARCHAR(255) USING LEFT(content_type, 255)');
        $this->addSql('ALTER TABLE message_part ALTER filename TYPE VARCHAR(255) USING LEFT(filename, 255)');
        $this->addSql('ALTER TABLE message_part ALTER content_id TYPE VARCHAR(255) USING LEFT(content_id, 255)');
    }
}
