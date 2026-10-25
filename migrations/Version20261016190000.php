<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where the readable body of an encrypted message came from. See
 * App\Entity\Mail\Message::$plaintextSource and App\Service\Crypto\DecryptedMail.
 *
 * `none` for every existing row: no message has a decrypted body yet.
 *
 * Reversible. Going down forgets which bodies a browser supplied — they stay
 * in the rows until the account's switch is turned off, which then has no way
 * to find them, so turn it off first.
 */
final class Version20261016190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record where the decrypted body of an encrypted message came from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE message ADD plaintext_source VARCHAR(8) DEFAULT 'none' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP plaintext_source');
    }
}
