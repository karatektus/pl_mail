<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contacts' keys from public key servers, phase 6f of issue #28: the key
 * server a key came from (contact_key.origin_host), so the person can be told
 * "from the key server keys.openpgp.org". The origin itself is the new value
 * 'keyserver' in the existing VARCHAR(16) origin column, which needs no
 * change. See App\Domain\Enum\Crypto\ContactKeyOrigin.
 *
 * Null for every existing row, which is right: none of them came from a key
 * server. 253, the longest a DNS name can be.
 *
 * Reversible, at the price of those keys: going down drops the host and the
 * rows whose origin is 'keyserver', because the code before this phase has
 * no such origin and could not load them. A later lookup finds them again.
 */
final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which key server a contact key came from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_key ADD origin_host VARCHAR(253) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM contact_key WHERE origin = 'keyserver'");
        $this->addSql('ALTER TABLE contact_key DROP origin_host');
    }
}
