<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a message is wrapped in, and what checking its signature found. See
 * App\Entity\Mail\Message::$crypto and App\Service\Crypto\MessageCryptoDetector.
 *
 * Null for every existing row, which is wrong only for the encrypted and
 * signed mail already synced: it keeps showing what it showed before — the
 * signature as an attachment, an encrypted message as an empty body — until
 * it is synced again. Nothing is rewritten here: telling them apart needs the
 * raw source, which a migration has no business fetching from a mail server.
 *
 * Reversible.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the crypto wrapping, signature state and signer of each message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD crypto VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD signature_state VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD signer VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP crypto');
        $this->addSql('ALTER TABLE message DROP signature_state');
        $this->addSql('ALTER TABLE message DROP signer');
    }
}
