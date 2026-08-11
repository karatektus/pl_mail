<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes "are there any ghost rows left" free to ask.
 *
 * GhostMessageReaper runs with the other self-repairs on every mailbox sync,
 * looking for the epoch-dated corpses a failed IMAP fetch used to leave behind
 * (see MessageSyncer::isUsableFetch). The answer is no on every install that has
 * run it once — which, after the first sync, is every install — so the cost that
 * matters is not the cleanup but the asking, repeated per mailbox per poll
 * forever.
 *
 * Without an index that is a sequential scan of the message table each time, on
 * a table that is the largest one here by a wide margin. A partial index makes
 * it an index probe of a relation that is normally empty: nothing real is dated
 * 1970, so this indexes the rows that should not exist and nothing else. On a
 * healthy database it occupies essentially no space.
 *
 * The predicate is a literal rather than a parameter so the planner can match
 * it against the reaper's own literal probe — a bound parameter cannot be
 * proven to imply a partial index's condition at plan time, which would have
 * left the index built and unused.
 */
final class Version20260811160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial index over epoch-dated message rows, so the ghost reaper costs nothing when there is nothing to reap';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE INDEX idx_message_epoch_ghost ON message (id) WHERE received_at < TIMESTAMP '1971-01-01'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_epoch_ghost');
    }
}
