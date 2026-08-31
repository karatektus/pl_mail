<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember the address a removed user had, so a restore can still recognise them.
 *
 * THE BUG THIS CLOSES
 * ───────────────────
 * Two deliberate designs stopped fitting together, and neither side noticed.
 *
 * `ConfigBackupUserRestorer` states, in its class docblock and in the handbook,
 * that a soft-deleted user counts as existing — "they hold the email address
 * against a unique index, so creating over them is impossible anyway". Quietly
 * bringing a removed account back is the one restore failure worse than not
 * restoring at all: it returns a password hash, a TOTP secret, recovery codes
 * and every mailbox credential to somebody an administrator deliberately
 * removed.
 *
 * But removal frees the address on purpose — `Admin\User\DefaultController`
 * rewrites `email` to `deleted-<id>@invalid` before stamping `deleted_at`, so
 * the same person can be added back later. Which means the tombstone stopped
 * holding the address the restorer looks for, `existingId()` answered null, and
 * the plan created the removed user afresh and live, listed on the review page
 * as an ordinary creation.
 *
 * The address is destroyed by the rewrite, so nothing could match it back. That
 * is the whole reason this column exists: it is the only copy.
 *
 * WHY NOT ONE OF THE OTHER TWO FIXES
 * ──────────────────────────────────
 * Matching the tombstone pattern cannot work — `deleted-41@invalid` says who
 * the row was, not what address they held.
 *
 * Keeping the real address on the row and making the unique index partial
 * (`WHERE deleted_at IS NULL`) would satisfy both designs at once, and was the
 * better shape had this been built that way. It is not the safer change now: it
 * rewrites the meaning of an index every login path depends on, and it keeps a
 * removed person's real name and address in the table an administrator browses,
 * which the removal code gives as its own reason for clearing them.
 *
 * NULLABLE, NO BACKFILL, AND ROWS ALREADY TOMBSTONED STAY UNRECOGNISABLE
 * ─────────────────────────────────────────────────────────────────────
 * There is nothing to backfill: for every row deleted before this migration the
 * address is already gone, and inventing one would be a guess written into a
 * column whose only purpose is to be believed. Those users remain resurrectable
 * by a restore, and that is stated rather than papered over — it is a finite,
 * shrinking set, and an operator can see them in the admin list.
 *
 * No index: it is read once per user per import, alongside the address lookup
 * that is already indexed, and never filtered on. `deleted_at` and
 * `deactivated_at` beside it have none for the same reason.
 *
 * NOT UNIQUE, DELIBERATELY
 * ────────────────────────
 * Two people may hold the same address over time — added, removed, added under
 * the same address, removed again — and each removal is a true record of what
 * that row was called. A unique index here would make the second removal fail,
 * turning a correct history into an error on a button that must always work.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the address a removed user held, so a config restore does not resurrect them';
    }

    public function up(Schema $schema): void
    {
        // Quoted: `user` is reserved in Postgres.
        $this->addSql('ALTER TABLE "user" ADD email_at_deletion VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Dropping it puts back the bug rather than losing anything an operator
        // typed — the column is only ever written by the delete action.
        $this->addSql('ALTER TABLE "user" DROP email_at_deletion');
    }
}
