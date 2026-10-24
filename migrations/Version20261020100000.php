<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An attached public key that is the sender's own, phase 6g of issue #28: it
 * is said in the crypto banner instead of being listed as an attachment.
 * message_part.sender_key_fingerprint names the key when the part is the
 * sender's key; key_checked says the part was looked at at all, so a part that
 * is somebody else's key is not opened again on every render. See
 * App\Service\Crypto\ContactKeyCollector::markAttachedKeys().
 *
 * Every existing row starts unchecked, which is right: no part was looked at
 * this way before. A message that arrived earlier is looked at the first time
 * it is shown (AttachedKeyExtension).
 *
 * Reversible: going down drops both, and the key is an attachment again.
 */
final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark the attached public key that is the sender\'s own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_part ADD sender_key_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE message_part ADD key_checked BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_part DROP sender_key_fingerprint');
        $this->addSql('ALTER TABLE message_part DROP key_checked');
    }
}
