<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Teach the search index that a hostname is made of words.
 *
 * Postgres's text-search parser classifies `help.wirhub.de` as ONE token of
 * type `host`, and `alice.smith@acme-corp.co.uk` as one `email`. That is
 * correct and it is useless: searching "wirhub" then matches nothing, because
 * "wirhub" is neither the token nor a prefix of it — it sits in the middle.
 *
 * plMail's answer to that has been a substring pass: `body_text ILIKE
 * '%wirhub%'`, OR-ed into the search alongside the tsvector matches.
 *
 * That pass is the reason search is slow, though not for the reason it first
 * appears. Its cost scales with how many candidates the trigram index has to
 * hand back for rechecking, not with the kind of term: measured on 300,000
 * messages, a rare needle costs 0.5ms and a common one costs 3.1 SECONDS
 * (32,440 candidates, 131MB of body text to verify). `%needle%` is lossy on
 * trigrams, so every candidate has to be fetched and matched for real, and
 * neither STORAGE MAIN nor STORAGE EXTERNAL moved that (2.9s, 3.1s) — it is
 * not TOAST lookups and not decompression, it is the verification itself. A
 * parallel sequential scan of every body takes 2.26s, which is to say the
 * index path is SLOWER than reading everything.
 *
 * So there is no better index to reach for; `%needle%` has no better structure
 * available. What there is, is a way to stop DEPENDING on that pass — which is
 * what this migration is. Split the compound tokens at INDEX time and the
 * ordinary inverted index answers the question the substring pass was added
 * for. Gmail does exactly this, and note that Gmail offers no substring search
 * at all, precisely because it cannot be made fast.
 *
 * This does not on its own make search faster: a hostname component was
 * already sub-millisecond through the trigram index. What it makes possible is
 * demoting the substring pass to a last resort without losing the case it was
 * written for — see the search query itself — and it puts host and address
 * fragments in the fast path, where they can rank (weight D, under every real
 * word) and where a live as-you-type search can reach them.
 *
 * `plmail_token_parts()` pulls out only the tokens that actually have internal
 * structure — `word.word`, `word@word`, `word-word` — and returns their pieces.
 * Ordinary prose contributes nothing, which is what keeps the index from
 * doubling: a sentence with no hostname in it adds no lexemes at all. Its
 * output is added at weight D, below every real match, so a hit on a fragment
 * of a hostname ranks under a hit on a word somebody actually wrote.
 *
 * IMMUTABLE because a generated column requires it, and it genuinely is: the
 * same input gives the same output for ever, with no reference to the database,
 * the locale or the clock.
 *
 * **This rewrites the message table.** The generated column has to be dropped
 * and re-added — Postgres has no ALTER for a generation expression — and the
 * GIN index rebuilt with it. On a large mailbox that is minutes of exclusive
 * lock, and it is the one expensive step in this release.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the parts of hostnames and addresses, so search need not scan bodies for substrings';
    }

    public function up(Schema $schema): void
    {
        // STRICT: a null body returns null rather than running the regex.
        // PARALLEL SAFE so a reindex and a seq scan can both use workers.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_token_parts(input text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
                SELECT coalesce(
                    (
                        SELECT string_agg(translate(m[1], './@-_:+', '       '), ' ')
                        FROM regexp_matches(input, '[A-Za-z0-9]+(?:[._@:+-][A-Za-z0-9]+)+', 'g') AS m
                    ),
                    ''
                )
            $$
            SQL);

        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        // The four weighted passes are unchanged; D is the new one. `simple`
        // rather than `english` for it, deliberately: these are fragments of
        // hostnames and addresses, not English, and stemming "corp" or
        // dropping "co" as a stop word would lose exactly the pieces somebody
        // is searching for.
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP COLUMN search_vector');

        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD search_vector tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
                setweight(to_tsvector('english', coalesce(body_text, '')), 'C')
            ) STORED
            SQL);

        $this->addSql('CREATE INDEX idx_message_search_vector ON message USING GIN (search_vector)');

        // After the column, or the drop fails on a dependency.
        $this->addSql('DROP FUNCTION IF EXISTS plmail_token_parts(text)');
    }
}
