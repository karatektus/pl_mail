<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The keyring of issue #28: user keys, their recovery copies, the OpenPGP
 * address keys beneath them, and which addresses each key serves. See
 * App\Entity\Crypto\UserKey for how they fit together.
 *
 * Four new tables and nothing changed in an old one, so every existing account
 * simply has no keyring until a browser it signs in from makes one. Every row
 * hangs off the user with ON DELETE CASCADE, which is what removing a person
 * should do with key material nobody else can open anyway.
 *
 * Reversible in the schema. Going down after anybody has made a keyring drops
 * their keys, and with them every message ever encrypted to those keys — the
 * server holds no other copy.
 */
final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the end-to-end keyring: user keys, recovery copies, address keys and their bindings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_key (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                wrapped TEXT NOT NULL,
                status VARCHAR(16) NOT NULL,
                locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_user_key_user ON user_key (usr_id)');
        $this->addSql('ALTER TABLE user_key ADD CONSTRAINT FK_user_key_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_key_copy (
                id SERIAL NOT NULL,
                user_key_id INT NOT NULL,
                kind VARCHAR(16) NOT NULL,
                wrapped TEXT NOT NULL,
                label VARCHAR(100) DEFAULT NULL,
                last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_user_key_copy_key ON user_key_copy (user_key_id)');
        $this->addSql('ALTER TABLE user_key_copy ADD CONSTRAINT FK_user_key_copy_key FOREIGN KEY (user_key_id) REFERENCES user_key (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE address_key (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                user_key_id INT NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                public_key TEXT NOT NULL,
                private_key TEXT NOT NULL,
                token TEXT NOT NULL,
                origin VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revocation_certificate TEXT DEFAULT NULL,
                first_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_address_key_fingerprint ON address_key (usr_id, fingerprint)');
        $this->addSql('CREATE INDEX idx_address_key_user_key ON address_key (user_key_id)');
        $this->addSql('ALTER TABLE address_key ADD CONSTRAINT FK_address_key_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE address_key ADD CONSTRAINT FK_address_key_user_key FOREIGN KEY (user_key_id) REFERENCES user_key (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE address_key_binding (
                id SERIAL NOT NULL,
                address_key_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                is_primary BOOLEAN NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_address_key_binding ON address_key_binding (address_key_id, email)');
        $this->addSql('CREATE INDEX idx_address_key_binding_email ON address_key_binding (email)');
        $this->addSql('CREATE INDEX IDX_address_key_binding_key ON address_key_binding (address_key_id)');
        $this->addSql('ALTER TABLE address_key_binding ADD CONSTRAINT FK_address_key_binding_key FOREIGN KEY (address_key_id) REFERENCES address_key (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE address_key_binding');
        $this->addSql('DROP TABLE address_key');
        $this->addSql('DROP TABLE user_key_copy');
        $this->addSql('DROP TABLE user_key');
    }
}
