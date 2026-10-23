<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store whether a conversation is in the bin, instead of working it out on
 * every list and badge (#223).
 *
 * "In the bin" is #268's rule: the thread carries Trash and none of its
 * messages is outside Trash. Every list, every badge and search leave such a
 * thread out, and each of them computed it from the messages: on a mailbox of
 * 67k threads that was about 170 ms per query, and a sidebar runs about ten.
 * Rewriting the sub-query as NOT EXISTS gave the same rows and no better time
 * where it mattered, because the cost is the rule itself, not its spelling.
 * So it is stored, as message_thread.unread_count is, and the filters read a
 * column.
 *
 * ── Kept right by the database, not by PHP ───────────────────────────────────
 * What changes the answer is scattered: a label going on or off a message or
 * a thread through the ORM; raw SQL that rebuilds threads or unlinks a label
 * from an account; ON DELETE CASCADE when a label or a message goes; a message
 * moved to another thread; a reply arriving in a conversation that was in the
 * bin. A flag set from PHP would be right on the paths someone remembered to
 * update and silently wrong on the rest, and a wrong answer here is mail that
 * vanishes from the Inbox. Triggers see every one of those writes, as the
 * JMAP change log's already does (Version20260923140100).
 *
 * Every trigger recomputes the whole answer for the threads it touched, from
 * the tables as they are, rather than adjusting it: a flush writes a message's
 * labels and the thread's in several statements, and a value derived from
 * scratch after each is right after the last whatever the order.
 *
 * ── Cost ─────────────────────────────────────────────────────────────────────
 * The label tables fire once per statement, with the rows it changed, and only
 * Trash rows lead to any work: filing, reading and labelling mail never reach
 * the recompute. A new message costs one primary-key lookup, and recomputes
 * only when it lands in a thread that is in the bin, which it takes out. A
 * message leaving a thread, by deletion or a move, recomputes that thread.
 */
final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store whether a conversation is in the bin, kept by triggers, so lists need not compute it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread ADD binned BOOLEAN DEFAULT false NOT NULL');

        // The rule, in one place. Called with the threads a write touched.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_refresh_thread_binned(thread_ids bigint[]) RETURNS void
            LANGUAGE sql AS $$
                UPDATE message_thread t
                   SET binned = s.binned
                  FROM (
                      SELECT c.id,
                             EXISTS (
                                 SELECT 1 FROM thread_label tl
                                 JOIN label l ON l.id = tl.label_id
                                 WHERE tl.message_thread_id = c.id AND l.role = 'trash'
                             )
                             AND NOT EXISTS (
                                 SELECT 1 FROM message m
                                 WHERE m.thread_id = c.id
                                   AND NOT EXISTS (
                                       SELECT 1 FROM message_label ml
                                       JOIN label l ON l.id = ml.label_id
                                       WHERE ml.message_id = m.id AND l.role = 'trash'
                                   )
                             ) AS binned
                        FROM message_thread c
                       WHERE c.id = ANY (thread_ids)
                  ) s
                 WHERE t.id = s.id
                   AND t.binned IS DISTINCT FROM s.binned
            $$
        SQL);

        // message_label: the threads of the messages whose Trash changed.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_message_label_binned() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM plmail_refresh_thread_binned(ARRAY(
                    SELECT DISTINCT m.thread_id
                      FROM changed c
                      JOIN label l ON l.id = c.label_id AND l.role = 'trash'
                      JOIN message m ON m.id = c.message_id
                     WHERE m.thread_id IS NOT NULL
                ));
                RETURN NULL;
            END
            $$
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_label_binned_insert AFTER INSERT ON message_label
            REFERENCING NEW TABLE AS changed
            FOR EACH STATEMENT EXECUTE FUNCTION plmail_message_label_binned()
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_label_binned_delete AFTER DELETE ON message_label
            REFERENCING OLD TABLE AS changed
            FOR EACH STATEMENT EXECUTE FUNCTION plmail_message_label_binned()
        SQL);

        // thread_label: the threads whose own Trash changed.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_thread_label_binned() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM plmail_refresh_thread_binned(ARRAY(
                    SELECT DISTINCT c.message_thread_id
                      FROM changed c
                      JOIN label l ON l.id = c.label_id AND l.role = 'trash'
                ));
                RETURN NULL;
            END
            $$
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER thread_label_binned_insert AFTER INSERT ON thread_label
            REFERENCING NEW TABLE AS changed
            FOR EACH STATEMENT EXECUTE FUNCTION plmail_thread_label_binned()
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER thread_label_binned_delete AFTER DELETE ON thread_label
            REFERENCING OLD TABLE AS changed
            FOR EACH STATEMENT EXECUTE FUNCTION plmail_thread_label_binned()
        SQL);

        // message: arriving, leaving, or moving between threads. Per row, so
        // that an UPDATE that does not move a message costs nothing at all —
        // a statement trigger with transition tables would copy every updated
        // row, bodies included, for every flag change.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_message_binned() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                -- A message arriving can only take a thread out of the bin, so
                -- only a thread that is in it needs looking at.
                IF TG_OP IN ('INSERT', 'UPDATE') AND NEW.thread_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM message_thread WHERE id = NEW.thread_id AND binned) THEN
                    PERFORM plmail_refresh_thread_binned(ARRAY[NEW.thread_id::bigint]);
                END IF;

                -- A message leaving can put the rest of its thread in the bin.
                IF TG_OP IN ('DELETE', 'UPDATE') AND OLD.thread_id IS NOT NULL THEN
                    PERFORM plmail_refresh_thread_binned(ARRAY[OLD.thread_id::bigint]);
                END IF;

                RETURN NULL;
            END
            $$
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_binned_insert AFTER INSERT ON message
            FOR EACH ROW WHEN (NEW.thread_id IS NOT NULL)
            EXECUTE FUNCTION plmail_message_binned()
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_binned_move AFTER UPDATE OF thread_id ON message
            FOR EACH ROW WHEN (OLD.thread_id IS DISTINCT FROM NEW.thread_id)
            EXECUTE FUNCTION plmail_message_binned()
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER message_binned_delete AFTER DELETE ON message
            FOR EACH ROW WHEN (OLD.thread_id IS NOT NULL)
            EXECUTE FUNCTION plmail_message_binned()
        SQL);

        // What is in the bin already. Only threads wearing Trash can be.
        $this->addSql(<<<'SQL'
            SELECT plmail_refresh_thread_binned(ARRAY(
                SELECT DISTINCT tl.message_thread_id
                  FROM thread_label tl
                  JOIN label l ON l.id = tl.label_id AND l.role = 'trash'
            ))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS message_binned_delete ON message');
        $this->addSql('DROP TRIGGER IF EXISTS message_binned_move ON message');
        $this->addSql('DROP TRIGGER IF EXISTS message_binned_insert ON message');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_message_binned()');
        $this->addSql('DROP TRIGGER IF EXISTS thread_label_binned_delete ON thread_label');
        $this->addSql('DROP TRIGGER IF EXISTS thread_label_binned_insert ON thread_label');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_thread_label_binned()');
        $this->addSql('DROP TRIGGER IF EXISTS message_label_binned_delete ON message_label');
        $this->addSql('DROP TRIGGER IF EXISTS message_label_binned_insert ON message_label');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_message_label_binned()');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_refresh_thread_binned(bigint[])');
        $this->addSql('ALTER TABLE message_thread DROP binned');
    }
}
