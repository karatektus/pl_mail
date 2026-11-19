<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recompute the bin flag once per DELETE on message, not once per deleted row
 * (#672).
 *
 * Version20261022160000 made all three message triggers per row, for a reason
 * that holds for two of them: an UPDATE that does not move a message should
 * cost nothing, and a statement trigger with a transition table would copy
 * every updated row for every flag change. A DELETE has no such case. Every
 * row it removes is one the trigger has to look at anyway, and per row that
 * meant one call to plmail_refresh_thread_binned() per message — the same
 * thread recomputed once for each of its messages.
 *
 * Measured removing an account of 400 messages in 134 threads: the per-row
 * trigger took 285 ms of a 348 ms DELETE; once per statement, 9 ms of 155. On a
 * Gmail mailbox of tens of thousands of messages that difference is what took
 * removing an account past PHP's thirty seconds. Removal is a background job
 * now, but emptying the bin, purging an account's mail and every other bulk
 * delete of messages go through this trigger too.
 *
 * Same answer as before: the threads the deleted messages belonged to are
 * recomputed from the tables as they stand after the statement, which is what
 * the per-row version arrived at by the time its last row fired.
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recompute the bin flag once per message DELETE instead of once per deleted message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS message_binned_delete ON message');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_message_binned_deleted() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM plmail_refresh_thread_binned(ARRAY(
                    SELECT DISTINCT c.thread_id
                      FROM gone c
                     WHERE c.thread_id IS NOT NULL
                ));
                RETURN NULL;
            END
            $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_binned_delete AFTER DELETE ON message
            REFERENCING OLD TABLE AS gone
            FOR EACH STATEMENT EXECUTE FUNCTION plmail_message_binned_deleted()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS message_binned_delete ON message');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_message_binned_deleted()');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_binned_delete AFTER DELETE ON message
            FOR EACH ROW WHEN (OLD.thread_id IS NOT NULL)
            EXECUTE FUNCTION plmail_message_binned()
        SQL);
    }
}
