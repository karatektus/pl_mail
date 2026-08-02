<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A user can say which clock they read.
 *
 * Nullable with no default and nothing backfilled, because null is a real
 * answer here and not a missing one: "never chose" resolves to the install's
 * configured default, so changing that default later still reaches everyone who
 * has not expressed a preference. Stamping every existing row with a zone would
 * take that away for the sake of a column that is never null.
 *
 * Purely a display preference. Every timestamp stays stored in UTC — this
 * decides nothing about what is written, only about what is read back.
 */
final class Version20260802080014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-user display timezone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD timezone VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP timezone');
    }
}
