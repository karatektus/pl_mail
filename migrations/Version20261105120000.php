<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Search reads the words of an HTML-only body (#477), and the index is rebuilt
 * in the background rather than here.
 *
 * A message sent as HTML alone has an empty body_text, and search_vector was
 * built from body_text, so its body was never indexed: on the mailbox in the
 * report, 20,148 of 20,675 messages could be found by subject and sender
 * only. message.body_html_text holds those words — see Message::$bodyHtmlText
 * for why it is a column of its own and not body_text filled in — and the
 * vector reads it in place of body_text where it is set.
 *
 * NO TABLE REWRITE. Changing a generated column's expression means dropping it
 * and adding it again, and adding a STORED generated column computes it for
 * every row inside the migration: Version20260818120000 did exactly that, and
 * an update was unreachable until it finished. So search_vector stops being a
 * generated column. Dropping it only touches the catalogue; adding a nullable
 * column with no default does not touch the rows either. Every row starts out
 * NULL, which `@@` reads as "no match", and SearchIndexRebuilder fills them
 * from a scheduled job, newest first — the same pass that fills
 * body_html_text, which the migration could not do anyway: taking the markup
 * off is ReadableBody's job, in PHP.
 *
 * A TRIGGER BUILDS THE VECTOR, so SQL stays the one place a vector is made,
 * with the same four weighted arms as before. On INSERT always. On UPDATE only
 * of the columns it reads, which is cheaper than the generated column was —
 * Postgres recomputed that on every UPDATE, a flag change included — and only
 * for a row that already has a vector. A row still waiting for the rebuild
 * stays NULL whatever else changes, because a vector built by an unrelated
 * update would be built without body_html_text and would take the row off
 * the rebuild's list for good. The rebuild sets search_vector itself (to an
 * empty vector), which is what tells the trigger to build it.
 *
 * Two indexes are new besides search_vector's own, rebuilt on the empty
 * column: body_html_text's trigram index for the substring pass, on an empty
 * column too, and a partial index of the rows still waiting, which is what lets
 * the rebuild take the newest waiting batch without walking past every row it
 * has already done, and lets the scheduled job see "nothing left" in one probe.
 * That one is built from every row (all of them are waiting), which reads the
 * table once but rewrites nothing.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message.body_html_text; search_vector becomes a trigger-maintained column rebuilt in the background';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD body_html_text TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');
        $this->addSql('ALTER TABLE message ADD search_vector tsvector DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION plmail_message_search_vector() RETURNS trigger
                LANGUAGE plpgsql
            AS $$
            BEGIN
                -- Not indexed yet: the rebuild's job, not this update's.
                IF TG_OP = 'UPDATE' AND NEW.search_vector IS NULL THEN
                    RETURN NEW;
                END IF;

                NEW.search_vector :=
                    setweight(to_tsvector('english', coalesce(NEW.subject, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(NEW.from_name, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(NEW.from_address, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(NEW.body_html_text, NEW.body_text, '')), 'C') ||
                    setweight(to_tsvector('simple', plmail_token_parts(
                        coalesce(NEW.subject, '') || ' ' ||
                        coalesce(NEW.from_name, '') || ' ' ||
                        coalesce(NEW.from_address, '') || ' ' ||
                        coalesce(NEW.body_html_text, NEW.body_text, '')
                    )), 'D');

                RETURN NEW;
            END
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_message_search_vector
                BEFORE INSERT OR UPDATE OF subject, from_name, from_address, body_text, body_html_text, search_vector
                ON message
                FOR EACH ROW EXECUTE FUNCTION plmail_message_search_vector()
            SQL);

        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');
        $this->addSql('CREATE INDEX idx_message_body_html_text_trgm ON message USING GIN (body_html_text gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_message_search_vector_pending ON message (id) WHERE (search_vector IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_message_search_vector ON message');
        $this->addSql('DROP FUNCTION IF EXISTS plmail_message_search_vector()');
        $this->addSql('DROP INDEX IF EXISTS idx_message_search_vector_pending');
        $this->addSql('DROP INDEX IF EXISTS idx_message_body_html_text_trgm');
        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        // Version20260818120000's column, verbatim.
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(body_text, '')), 'C') ||
                setweight(to_tsvector('simple', plmail_token_parts(
                    coalesce(subject, '') || ' ' ||
                    coalesce(from_name, '') || ' ' ||
                    coalesce(from_address, '') || ' ' ||
                    coalesce(body_text, '')
                )), 'D')
            ) STORED
            SQL);

        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');
        $this->addSql('ALTER TABLE message DROP body_html_text');
    }
}
