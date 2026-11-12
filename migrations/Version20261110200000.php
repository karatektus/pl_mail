<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The search vector reads at most the first 200,000 characters of a body (#546).
 *
 * Postgres refuses a tsvector over 1 MB, and the trigger fed it the whole
 * body. A message with megabytes of HTML (7.3 MB in the report, 2.8 million
 * characters once the markup was off) could then not be indexed — the
 * background rebuild stopped on it at 500 of 9,444 — and, since the trigger
 * also runs on INSERT, a new one could not be stored at all: the sync retried
 * it and then skipped it for good.
 *
 * WHY 200,000. The reporter measured about 0.69 bytes of vector per character
 * of real mail and proposed 400,000. Real prose repeats its words; a body of
 * hashes, tracking ids or base64 does not, and that is the worst case: one
 * vector entry per word. Measured on Postgres 18 with such text, the two arms
 * together come to 0.25 MB at 100,000 characters, 0.50 MB at 200,000 and
 * 1.00 MB at 400,000 — the limit itself, with the subject and sender still to
 * add. 200,000 leaves half the limit for the worst body there is, and is
 * still some 30,000 words: more than any email anybody reads. The words after
 * it are not searchable; the message is stored and found by the rest.
 *
 * Both arms read the same capped text: they are concatenated into one value,
 * and the limit is on the sum.
 *
 * CREATE OR REPLACE, so an install that patched the function by hand, as the
 * reporter did, ends up with this one.
 */
final class Version20261110200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cap the body the search vector reads at 200,000 characters, under Postgres\'s 1 MB tsvector limit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(self::function("left(coalesce(NEW.body_html_text, NEW.body_text, ''), 200000)"));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(self::function("coalesce(NEW.body_html_text, NEW.body_text, '')"));
    }

    /** Version20261105120000's function, with the body expression given. */
    private static function function(string $body): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION plmail_message_search_vector() RETURNS trigger
                LANGUAGE plpgsql
            AS \$\$
            DECLARE
                body text;
            BEGIN
                -- Not indexed yet: the rebuild's job, not this update's.
                IF TG_OP = 'UPDATE' AND NEW.search_vector IS NULL THEN
                    RETURN NEW;
                END IF;

                body := {$body};

                NEW.search_vector :=
                    setweight(to_tsvector('english', coalesce(NEW.subject, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(NEW.from_name, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(NEW.from_address, '')), 'B') ||
                    setweight(to_tsvector('english', body), 'C') ||
                    setweight(to_tsvector('simple', plmail_token_parts(
                        coalesce(NEW.subject, '') || ' ' ||
                        coalesce(NEW.from_name, '') || ' ' ||
                        coalesce(NEW.from_address, '') || ' ' ||
                        body
                    )), 'D');

                RETURN NEW;
            END
            \$\$
            SQL;
    }
}
