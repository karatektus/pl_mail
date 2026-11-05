<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Search reads the words of an HTML-only body (#477).
 *
 * A message sent as HTML alone has an empty body_text, and search_vector was
 * built from body_text, so its body was never indexed: on the mailbox in the
 * report, 20,148 of 20,675 messages could be found by subject and sender
 * only. message.body_html_text holds those words — see Message::$bodyHtmlText
 * for why it is a column of its own and not body_text filled in — and the
 * vector reads it in place of body_text where it is set.
 *
 * The column starts empty. Mail that arrives from now on fills it as it is
 * stored; mail already here is filled by `app:backfill html-body-text`, which
 * the migration cannot do itself: taking the markup off is ReadableBody's job,
 * in PHP, and a second copy of it in SQL would be a second definition of what
 * the words of a message are. Postgres regenerates search_vector for each row
 * the backfill writes.
 *
 * Rebuilding search_vector rewrites the table, as Version20260818120000 did:
 * on a large mailbox this migration takes a while. The two weighted arms that
 * read the body are the only change; the rest is that migration's, verbatim.
 *
 * A trigram index beside body_text's, for the substring pass that now reads
 * both columns. Built on an empty column, so it costs nothing here.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message.body_html_text and makes search_vector index it in place of an empty body_text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD body_html_text TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(body_html_text, body_text, '')), 'C') ||
                setweight(to_tsvector('simple', plmail_token_parts(
                    coalesce(subject, '') || ' ' ||
                    coalesce(from_name, '') || ' ' ||
                    coalesce(from_address, '') || ' ' ||
                    coalesce(body_html_text, body_text, '')
                )), 'D')
            ) STORED
            SQL);

        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');
        $this->addSql('CREATE INDEX idx_message_body_html_text_trgm ON message USING GIN (body_html_text gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_message_body_html_text_trgm');
        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

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
