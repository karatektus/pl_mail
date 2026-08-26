<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a message's embedding lives, and how two of them are compared.
 *
 * A TABLE OF ITS OWN, ONE ROW PER MESSAGE
 * ───────────────────────────────────────
 * Not a column on `message`, and not one row per chunk. A 768-dimension
 * float4 array is 3,092 bytes, which is past the TOAST threshold, so every
 * embedding is stored out of line — 391 MB of TOAST for 100,000 messages,
 * measured. Putting that on `message` would drag it through every query that
 * touches the table.
 *
 * One row per MESSAGE rather than per chunk is the harder-won half. A to-many
 * embedding table would multiply every (thread, matching message) row in the
 * search before the GROUP BY collapsed it again — exactly the row multiplication
 * the label joins were removed for in v0.1.40, whose defining property is that
 * the answers stay correct and only the clock shows it.
 *
 * NORMALISED AT WRITE TIME
 * ────────────────────────
 * The writer scales every vector to unit length, so cosine similarity collapses
 * to a plain dot product and the distance function below needs no square roots
 * per row. That is not a micro-optimisation: the arithmetic dominates I/O about
 * six to one here, so it is most of the cost.
 *
 * ONE FUNCTION NAME, TWO POSSIBLE BODIES
 * ──────────────────────────────────────
 * The application only ever writes `plmail_embed_distance(a, b)`. Without
 * pgvector that is a plpgsql loop; with it, a one-line SQL cast to `vector`
 * which Postgres INLINES, so an HNSW expression index over the same `real[]`
 * column is reachable. Detection happens here, once, rather than in PHP — there
 * is no branch in the application and no second SQL builder to rot.
 *
 * pgvector is NOT required and must never become required. It is absent from
 * the shipped `postgres:18-alpine` image, and swapping to a Debian-based one on
 * an existing data volume is a silent-data-corruption event: musl records no
 * collation version, so Postgres's mismatch check never fires and 44 indexes
 * are quietly walked with the wrong comparator. This installation ships the
 * loop, and picks up the accelerator only if somebody deliberately arranged for
 * the extension to exist.
 */
final class Version20260826210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message_embedding and the plmail_embed_distance() comparison function.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE message_embedding (
                message_id INT NOT NULL,
                embedding REAL[] NOT NULL,
                dimensions INT NOT NULL,
                model VARCHAR(128) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(message_id)
            )
        SQL);

        // ON DELETE CASCADE: an embedding describes a message and has no
        // meaning without it, and mail is deleted in bulk here.
        $this->addSql(<<<'SQL'
            ALTER TABLE message_embedding
                ADD CONSTRAINT fk_message_embedding_message
                FOREIGN KEY (message_id) REFERENCES message (id)
                ON DELETE CASCADE
        SQL);

        // Changing the embedding model invalidates every stored vector — a
        // mailbox embedded at one width and searched at another returns
        // nonsense rather than an error. This is what makes the stale ones
        // findable without a scan of the array column itself.
        $this->addSql('CREATE INDEX idx_message_embedding_model ON message_embedding (model, dimensions)');

        $this->addSql($this->distanceFunction());
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS plmail_embed_distance(real[], real[])');
        $this->addSql('DROP TABLE message_embedding');
    }

    /**
     * The body the database can actually support.
     *
     * IMMUTABLE PARALLEL SAFE STRICT, spelled the same way plmail_token_parts()
     * is: IMMUTABLE is what lets it be used in an expression index and it
     * genuinely holds, PARALLEL SAFE is what lets a scan use more than one
     * worker, and STRICT means a NULL on either side answers NULL rather than
     * walking an array that is not there.
     *
     * Cosine DISTANCE, not similarity — 0 is identical and 2 is opposite —
     * because that is what pgvector's `<=>` returns and the two bodies must be
     * interchangeable to the caller. On unit-length input it is 1 minus the dot
     * product.
     */
    private function distanceFunction(): string
    {
        $hasVector = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM pg_available_extensions WHERE name = 'vector'",
        );

        if (true === $hasVector) {
            $this->write('pgvector is available; plmail_embed_distance() will use it.');

            $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

            // LANGUAGE sql and a single SELECT, so Postgres inlines it and an
            // expression index over the cast is reachable. A typmod mismatch
            // here silently costs that index — the planner falls back to a
            // sequential scan with no error and no notice — so the width is
            // spelled in both places or in neither.
            return <<<'SQL'
                CREATE OR REPLACE FUNCTION plmail_embed_distance(a real[], b real[])
                RETURNS double precision
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
                    SELECT (a::vector) <=> (b::vector)
                $$
            SQL;
        }

        // The shipped path. Measured at ~0.11ms per 768-dimension row, which is
        // 56ms over 500 candidates — cheaper than the full-text pass that
        // produced them. It is only ever allowed to run over a bounded set;
        // over 100,000 rows the same loop takes 11 seconds, which is worse than
        // the pathology the search UNION exists to avoid.
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_embed_distance(a real[], b real[])
            RETURNS double precision
            LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE STRICT AS $$
            DECLARE
                total double precision := 0;
                width int := least(array_length(a, 1), array_length(b, 1));
                i int;
            BEGIN
                IF width IS NULL THEN
                    -- Nothing comparable. Answered as "as far apart as possible"
                    -- rather than NULL, so a caller's ORDER BY does not have to
                    -- know about it.
                    RETURN 2;
                END IF;

                FOR i IN 1..width LOOP
                    total := total + (a[i]::double precision * b[i]::double precision);
                END LOOP;

                RETURN 1 - total;
            END;
            $$
        SQL;
    }
}
