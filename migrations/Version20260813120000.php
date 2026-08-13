<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the account dot a colour of its own, and pins the primary that already
 * exists.
 *
 * Three separate things used to be read off account.sort_order: where the row
 * sits in the list, which account is primary (position 0), and which entry of
 * the eight-colour palette the account's dot is painted with. Dragging a row to
 * tidy the list therefore reassigned the address Compose sends from AND swapped
 * two accounts' colours — a mark whose only job is "this is the same account
 * you saw on that message" changing meaning under the reader.
 *
 * account.color_index is the palette slot, and it is backfilled FROM sort_order
 * so that no existing install sees its colours move on deploy: everyone keeps
 * exactly the dot they have today, it simply stops following the drag handle.
 *
 * is_primary already exists as a column and is already written on every
 * resequence, so the flag on disk is correct right now — it just was not
 * authoritative. The UPDATE below is the safety net for the case the old
 * derivation quietly covered: a user whose rows carry no primary at all (a
 * seed, an import, a restore — anything that did not go through
 * AccountCreator). Those get the lowest sort_order promoted, which is the same
 * account the derivation would have picked, so nobody's From address changes.
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds account.color_index (backfilled from sort_order) and guarantees every user has exactly one primary account, so ordering stops deciding both';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD color_index INT DEFAULT 0 NOT NULL');

        // The colour every one of these accounts is wearing today.
        $this->addSql('UPDATE account SET color_index = sort_order');

        // Exactly one primary per user. Demote any extras first — two primaries
        // is a state the old derivation could not produce but an import can,
        // and findOneBy() would then pick between them arbitrarily.
        $this->addSql(<<<'SQL'
            UPDATE account SET is_primary = false
            WHERE is_primary = true
              AND id NOT IN (
                  SELECT MIN(id) FROM account WHERE is_primary = true GROUP BY usr_id
              )
            SQL);

        // Then promote the lowest-ordered account of any user left without one.
        $this->addSql(<<<'SQL'
            UPDATE account SET is_primary = true
            WHERE id IN (
                SELECT DISTINCT ON (usr_id) id
                FROM account
                WHERE usr_id NOT IN (SELECT usr_id FROM account WHERE is_primary = true)
                ORDER BY usr_id, sort_order ASC, id ASC
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP color_index');
    }
}
