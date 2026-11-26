<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * mailbox.bulk_vanish_hash, bulk_vanish_since and bulk_vanish_sweeps: a mass
 * disappearance from the bin or spam that the sweep refused, while it waits to
 * be confirmed across sweeps (#756, Mailbox::$bulkVanishHash).
 *
 * Nullable, and a zero count, so adding them rewrites nothing: every folder
 * starts with nothing waiting, which is what it had.
 */
final class Version20261126200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox.bulk_vanish_*, a refused purge of the bin or spam waiting to be confirmed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD bulk_vanish_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD bulk_vanish_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD bulk_vanish_sweeps INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP bulk_vanish_sweeps');
        $this->addSql('ALTER TABLE mailbox DROP bulk_vanish_since');
        $this->addSql('ALTER TABLE mailbox DROP bulk_vanish_hash');
    }
}
