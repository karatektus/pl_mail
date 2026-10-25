<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S/MIME, phase 6c of issue #28: a person's own certificates with their
 * private keys wrapped in the browser (smime_certificate), and the
 * certificates of the people they write with (contact_certificate). See
 * App\Entity\Crypto\SmimeCertificate and ContactCertificate.
 *
 * Two new tables and nothing else; no existing row changes.
 *
 * Reversible. Going down drops both tables, and with them every imported
 * certificate and its wrapped key — export them first (Settings → Encryption)
 * if they are not kept anywhere else.
 */
final class Version20261017090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add smime_certificate and contact_certificate for S/MIME';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE smime_certificate (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                user_key_id INT NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                certificate TEXT NOT NULL,
                chain TEXT NOT NULL,
                private_key TEXT NOT NULL,
                addresses JSON NOT NULL,
                subject VARCHAR(255) NOT NULL,
                issuer VARCHAR(255) NOT NULL,
                not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                not_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_smime_certificate_fingerprint ON smime_certificate (usr_id, fingerprint)');
        $this->addSql('CREATE INDEX idx_smime_certificate_user_key ON smime_certificate (user_key_id)');
        $this->addSql('ALTER TABLE smime_certificate ADD CONSTRAINT FK_smime_certificate_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE smime_certificate ADD CONSTRAINT FK_smime_certificate_user_key FOREIGN KEY (user_key_id) REFERENCES user_key (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE contact_certificate (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                certificate TEXT NOT NULL,
                verified BOOLEAN DEFAULT false NOT NULL,
                status VARCHAR(16) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                issuer VARCHAR(255) NOT NULL,
                not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                not_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_certificate_address_fingerprint ON contact_certificate (usr_id, email, fingerprint)');
        $this->addSql("CREATE UNIQUE INDEX uniq_contact_certificate_current ON contact_certificate (usr_id, email) WHERE status = 'current'");
        $this->addSql('ALTER TABLE contact_certificate ADD CONSTRAINT FK_contact_certificate_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contact_certificate');
        $this->addSql('DROP TABLE smime_certificate');
    }
}
