<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contacts' public keys, phase 4 of issue #28. See App\Entity\Crypto\ContactKey.
 *
 * One new table and nothing changed in an old one: every existing person
 * starts with no contacts' keys, and plMail collects them from mail that
 * arrives from now on, from WKD when asked, or from an import. Mail that
 * arrived before is not searched for keys.
 *
 * The partial unique index is the rule that matters most here — at most one
 * key in use per address, whatever two workers collecting keys at once may
 * try. Rows hang off the user with ON DELETE CASCADE, like the keyring's.
 *
 * Reversible. Going down drops every collected key and every pin; nothing
 * else depends on them, and they come back the slow way, as mail arrives.
 */
final class Version20261014190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact_key for the public keys of the people a person writes with';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE contact_key (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                public_key TEXT NOT NULL,
                origin VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                pinned BOOLEAN DEFAULT false NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                encrypt VARCHAR(8) DEFAULT 'auto' NOT NULL,
                sign VARCHAR(8) DEFAULT 'auto' NOT NULL,
                scheme VARCHAR(16) DEFAULT 'pgp-mime' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_key_address_fingerprint ON contact_key (usr_id, email, fingerprint)');
        $this->addSql("CREATE UNIQUE INDEX uniq_contact_key_current ON contact_key (usr_id, email) WHERE status = 'current'");
        $this->addSql('ALTER TABLE contact_key ADD CONSTRAINT FK_contact_key_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contact_key');
    }
}
