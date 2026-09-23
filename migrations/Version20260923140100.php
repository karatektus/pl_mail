<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * JMAP state tokens in commit order, per account.
 *
 * The change log's identity column is the state token, and an identity value
 * is handed out at INSERT, not at COMMIT. Two flushes writing change rows for
 * one account could therefore commit out of order: A takes 10, B takes 11 and
 * commits, a client reads state 11 — and when A commits, row 10 sits below a
 * token the client already holds and /changes never reports it. The change is
 * lost to that client for good, which is exactly what state tokens exist to
 * prevent.
 *
 * The fix is to serialise the writers of one account from the moment they
 * take a number until they commit: a transaction-scoped advisory lock keyed by
 * the account, taken BEFORE the number. That has to be a trigger rather than a
 * repository call from PHP. Doctrine opens the flush transaction itself and
 * fires no event inside it before the inserts; onFlush runs before BEGIN, so a
 * lock taken there would be released by autocommit on the spot. And the
 * column default is evaluated before a BEFORE trigger sees the row, so the
 * trigger cannot merely lock — it locks and then draws the number again.
 * Doctrine reads the id back with LASTVAL(), which is the trigger's nextval,
 * so the entity carries the number that was stored. The default's own draw is
 * a gap, and gaps in this sequence are already normal (it is shared by every
 * account and object type).
 *
 * Cost: one uncontended lock per row, re-entrant within a transaction. Writers
 * of different accounts never wait for each other. A flush touching several
 * accounts (Gmailify attribution) takes several locks in row order; two such
 * flushes in opposite order can deadlock, which Postgres detects and resolves
 * by failing one, and the transport retries it.
 *
 * Key: the two-int form, (JMAP, account_id), so it cannot collide with the
 * single-bigint keys used by MigrateCommand and UserRepository. 1246576976 is
 * the bytes of "JMAP" as an int4.
 */
final class Version20260923140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serialise jmap_change_log writers per account so state tokens follow commit order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_jmap_change_log_sequence() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(1246576976, NEW.account_id);
                NEW.sequence := nextval(pg_get_serial_sequence('jmap_change_log', 'sequence'));
                RETURN NEW;
            END
            $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE TRIGGER jmap_change_log_sequence
            BEFORE INSERT ON jmap_change_log
            FOR EACH ROW EXECUTE FUNCTION plmail_jmap_change_log_sequence()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS jmap_change_log_sequence ON jmap_change_log');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_jmap_change_log_sequence()');
    }
}
