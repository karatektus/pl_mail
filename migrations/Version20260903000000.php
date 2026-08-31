<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An account can be suspended instead of removed.
 *
 * WHAT WAS MISSING
 * ────────────────
 * `user` carried exactly two states: present, and soft-deleted. Removal frees
 * the address, overwrites the display name and blanks the hash, so the one
 * question an administrator actually asks about a colleague on leave — "stop
 * this person signing in for a while" — had only an answer that cannot be
 * undone. `deactivated_at` is the state in between.
 *
 * NULLABLE, WITH NO BACKFILL AND NO DEFAULT
 * ─────────────────────────────────────────
 * Unlike the timestamp backfills elsewhere in this directory, there is nothing
 * to populate: every row that exists predates the feature and is therefore
 * active, which is exactly what NULL says. A DEFAULT would be a value for a
 * column whose whole meaning is that it usually has none.
 *
 * NO INDEX, DELIBERATELY
 * ──────────────────────
 * The column is read one row at a time — the user checker holds the row it is
 * about to admit, and the admin list already selects every undeleted user for
 * a paginator. Nothing filters on it, so an index here would be an index
 * PostgreSQL maintains on every login and never consults. `deleted_at` next to
 * it has none for the same reason.
 */
final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an administrator suspend an account instead of removing it';
    }

    public function up(Schema $schema): void
    {
        // The table name stays quoted: `user` is reserved in Postgres, which is
        // why the entity maps to #[ORM\Table(name: '`user`')] and why every
        // statement in this directory that touches it is written this way.
        $this->addSql('ALTER TABLE "user" ADD deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Going down does not reactivate anybody, because there is nobody to
        // reactivate: dropping the column removes the only record that an
        // account was ever suspended, and a build without it lets every one of
        // them sign in again. That is the honest outcome of removing the
        // feature, and it is why the changelog says so.
        $this->addSql('ALTER TABLE "user" DROP deactivated_at');
    }
}
