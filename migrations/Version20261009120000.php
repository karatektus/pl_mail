<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a public demo counts the sessions it hands out, for Admin → Demo
 * visitors. See App\Entity\Demo\DemoVisit.
 *
 * A new, empty table. It is created on every install because the schema is one
 * schema, and it is written to only in demo mode, so on a normal install it
 * stays empty.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add demo_visit: one row per demo session handed out, with a keyed hash in place of an address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE demo_visit (
                id SERIAL NOT NULL,
                visitor_hash VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_demo_visit_created_at ON demo_visit (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_visit');
    }
}
