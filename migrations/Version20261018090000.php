<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encryption for accounts that sign in only through single sign-on, phase 6e
 * of issue #28: what a person's keyring hangs off (keyring_root), and the salt
 * and iteration count of the encryption passphrase that is its root on such
 * an account. See App\Domain\Enum\Crypto\KeyringRoot.
 *
 * Every existing row gets 'password', which is what every existing keyring
 * hangs off — so nothing changes for anybody until an account without a
 * password sets encryption up. The passphrase columns are null throughout.
 *
 * Reversible in the schema, not in effect: going down drops the passphrase
 * parameters, and every keyring set up with a passphrase is then one no
 * browser can derive the key for. Its recovery phrase or a known device still
 * opens it.
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record what each keyring hangs off, and the encryption passphrase parameters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD keyring_root VARCHAR(16) DEFAULT 'password' NOT NULL");
        $this->addSql('ALTER TABLE "user" ADD passphrase_salt VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD passphrase_iterations INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP keyring_root');
        $this->addSql('ALTER TABLE "user" DROP passphrase_salt');
        $this->addSql('ALTER TABLE "user" DROP passphrase_iterations');
    }
}
