<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drafts sealed in the browser, and the message the browser built to be sent.
 * See App\Entity\Mail\Message::$sealedDraft, ::$outgoingSeal and
 * App\Entity\Mail\MessagePart::$sealed.
 *
 * Every existing row is a draft the server can read and mail the server
 * builds, which is what the defaults say.
 *
 * Reversible. Going down forgets which drafts were sealed: their bodies stay
 * empty, and the sealed copies stay on disk under the storage directory's
 * `sealed/`, unread.
 */
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record sealed drafts, sealed attachments and the message built in the browser';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD sealed_draft BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE message ADD outgoing_seal JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE message_part ADD sealed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP sealed_draft');
        $this->addSql('ALTER TABLE message DROP outgoing_seal');
        $this->addSql('ALTER TABLE message_part DROP sealed');
    }
}
