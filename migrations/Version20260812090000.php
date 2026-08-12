<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Priority and read receipts on the message row.
 *
 * Three columns, one migration, because they arrive with the same compose
 * menu and splitting them would be two locks on the same large table for no
 * gain.
 *
 * All three are nullable with no default, which is what makes this cheap on a
 * table that holds every message the installation has ever seen: Postgres 11+
 * adds a nullable column without a default as a catalogue change, so there is
 * no rewrite and no long lock however many rows are down there. A DEFAULT
 * false on read_receipt_requested would have been tidier to read and would
 * have cost a full table rewrite to gain nothing — nothing distinguishes "no
 * receipt was asked for" from "this row predates the feature", and neither
 * sends anything.
 *
 * priority is VARCHAR rather than a Postgres enum: Doctrine maps it through
 * enumType on the PHP side, and a native enum type would need its own
 * migration every time a value is added or renamed.
 *
 * down() drops all three. That loses the receipt timestamps, which is correct
 * — they are derived facts, re-learnable only from MDNs that have already been
 * delivered, and a schema rollback that kept orphan columns around would leave
 * the next up() unable to run.
 */
final class Version20260812090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Message priority, read-receipt request flag and read-receipt timestamp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD priority VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD read_receipt_requested BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD read_receipt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP read_receipt_at');
        $this->addSql('ALTER TABLE message DROP read_receipt_requested');
        $this->addSql('ALTER TABLE message DROP priority');
    }
}
