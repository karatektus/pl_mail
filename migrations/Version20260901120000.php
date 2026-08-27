<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pick up pgvector on an installation that did not have it when
 * Version20260826210000 ran.
 *
 * WHY A SECOND MIGRATION AND NOT AN EDIT TO THAT ONE
 * ──────────────────────────────────────────────────
 * Version20260826210000 chooses the body of plmail_embed_distance() from
 * pg_available_extensions AT THE MOMENT IT RUNS, and on every installation that
 * exists today it ran against `postgres:18-alpine`, where the answer was no. It
 * is in the ledger; it will never run again. Editing it would change nothing
 * anywhere and would quietly stop matching what is actually installed.
 *
 * So this asks the same question again, at the one other moment it can have a
 * different answer: the boot after an operator switched the database service to
 * the image built by docker/postgres/Dockerfile. It is the same detection, the
 * same two bodies and the same function name — see that migration's docblock
 * for why the choice lives in SQL rather than in PHP.
 *
 * On an installation still running the stock image this is a no-op. That is the
 * point: pgvector is not required, must never become required, and the plpgsql
 * loop stays the shipped path.
 *
 * WHAT THE SWAP IS ACTUALLY WORTH, MEASURED
 * ─────────────────────────────────────────
 * Measured here on 74,000 rows of 1024-dimension unit vectors, PostgreSQL 18.4
 * on musl, single-threaded, warm cache:
 *
 *   20,000 rows   plpgsql loop  2.14 s      pgvector `<=>`  1.23 s
 *   74,000 rows   plpgsql loop  7.70 s      pgvector `<=>`  4.51 s
 *
 * That is 1.75×, and docs/internals/ai-assist.md used to claim ~24×. The
 * difference is not the arithmetic — pgvector's dot product is SIMD and takes
 * microseconds — it is that `embedding` is `real[]`, so EVERY row pays a
 * detoast of a 4 KB array plus a fresh `real[]` → `vector` conversion before
 * the SIMD ever starts. The conversion, not the distance, is the cost. That is
 * corrected in the handbook rather than left as a number nobody re-measured.
 *
 * 1.75× is still worth having: the search evaluates this over
 * SEMANTIC_CANDIDATES = 2,000 rows, so the semantic arm drops from about 0.63 s
 * to about 0.36 s with no change to any query.
 *
 * WHY THIS MIGRATION CREATES NO INDEX, WHICH IS THE HARD PART
 * ──────────────────────────────────────────────────────────
 * A distance function only ever scans. The reason to want pgvector is HNSW, and
 * HNSW cannot be reached from here. Three things block it, all verified against
 * pgvector 0.8.6 on PostgreSQL 18.4 rather than reasoned about:
 *
 * 1. AN HNSW INDEX NEEDS A WIDTH, AND THE EXPRESSION ABOVE HAS NONE.
 *      CREATE INDEX … USING hnsw ((embedding::vector) vector_cosine_ops)
 *      ERROR:  column does not have dimensions
 *    Only `(embedding::vector(1024))` builds. Postgres treats that as a
 *    different expression from `(embedding::vector)` — different typmod
 *    argument to array_to_vector() — so the index built on one is invisible to
 *    a query written with the other. Confirmed both ways with EXPLAIN: the
 *    typmod-bearing query takes `Index Scan using …`, the inlined function's
 *    expression takes a Seq Scan and a Sort, with no error and no notice. That
 *    is the trap Version20260826210000's own comment warns about, and it is
 *    what the function walks into.
 *
 *    Spelling the width into the function body instead is not a fix. The width
 *    is whatever the configured model returns — count($unit) in EmbeddingStore,
 *    not a setting anything can read here — and a fixed `::vector(1024)` raises
 *    `expected 1024 dimensions, not 768` for every call the moment somebody
 *    changes the model in the admin panel. A dropdown would 500 /mail/search
 *    for everybody, which is exactly the failure that migration's
 *    dimension-scoping exists to prevent.
 *
 * 2. HNSW ANSWERS `ORDER BY … LIMIT k`, AND THE SEARCH ASKS SOMETHING ELSE.
 *    The semantic arm is a threshold — `plmail_embed_distance(…) <= 0.45` over
 *    the most recent SEMANTIC_CANDIDATES messages. No approximate-nearest-
 *    neighbour index can serve a range predicate; neither HNSW nor IVFFlat has
 *    an operator for it. Reaching the index means the query becoming a top-k
 *    ordering, which is a change to MessageThreadRepository::buildSearchSql().
 *
 * 3. IT WOULD COST MORE THAN THIS BOOT CAN SPEND. Measured at 74,000 × 1024
 *    under the settings this stack actually ships (maintenance_work_mem left at
 *    PostgreSQL's 64 MB default):
 *
 *      CREATE INDEX … USING hnsw   7 min 40 s      578 MB
 *      NOTICE: hnsw graph no longer fits into maintenance_work_mem
 *              after 13928 tuples
 *
 *    (1 min 54 s at maintenance_work_mem = 2 GB, same 578 MB — the index is
 *    larger than the 397 MB table it indexes, because HNSW keeps a full copy of
 *    every vector plus its graph.)
 *
 *    Migrations here run from the entrypoint of all six services at once,
 *    serialised behind one advisory lock — see MigrateCommand. A build like
 *    that is not a slow migration, it is a stack that does not come back for
 *    eight minutes, on an upgrade whose documentation says "Starting the new
 *    image IS running it". And a plain CREATE INDEX holds a lock that blocks
 *    the backfill worker and mail ingest for the whole of it.
 *
 * So the honest state is: the extension is available and the function uses it,
 * and the index is a separate, deliberate operator step that is only worth
 * taking once the search query can reach it. The exact index, its cost and the
 * two changes that make it live are written down in
 * docs/internals/ai-assist.md § "What it would take to reach an index", so that
 * the next person to touch the search SQL finds a measured plan rather than an
 * assumption.
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use pgvector for plmail_embed_distance() where the extension is now available.';
    }

    public function up(Schema $schema): void
    {
        if (false === $this->hasVector()) {
            // The shipped path, and the common one. Said out loud because
            // "nothing happened" and "something went wrong" look identical in
            // migration output otherwise, and somebody who just switched the
            // database image needs to know which of the two they got.
            //
            // The second sentence is not a pointer at documentation, it is the
            // way out of a trap. This decision is taken ONCE, when the ledger
            // row is written; an operator who upgrades first and switches the
            // database image afterwards has already spent it, and no later
            // `migrate` will look again — it is at the latest version and says
            // so. Reversing this one migration and migrating again re-asks the
            // question, and reversing it is safe precisely because down() only
            // reinstalls the loop that is already installed.
            $this->write(
                'pgvector is not available; plmail_embed_distance() keeps the plpgsql loop. '
                . 'If you enable it later, this migration will not notice on its own — run '
                . '`doctrine:migrations:execute --down DoctrineMigrations\\Version20260901120000` '
                . 'and migrate again. See docs/install/upgrading.md § "Turning on pgvector".',
            );

            return;
        }

        $this->write('pgvector is available; plmail_embed_distance() will use it.');

        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // Byte for byte the body Version20260826210000 installs on a database
        // that already had the extension, so the two paths converge on one
        // definition rather than on two that drift.
        //
        // LANGUAGE sql and a single SELECT, so Postgres inlines it — verified
        // with EXPLAIN VERBOSE, which shows the call replaced by
        // `(e.embedding)::vector <=> (…)::vector` in the plan. No typmod on
        // either cast, deliberately: see point 1 in the docblock. The cost of
        // that is the index; the cost of the alternative is a broken search the
        // day the model changes.
        //
        // COST 100 IS SPELLED OUT, and it is not decoration. Version20260829120000
        // raised this function's procost to 50000 because the plpgsql loop is
        // 0.132 ms a call and the planner believed it was 0.25 cost units. That
        // number is a lie about THIS body — an inlined SIMD dot product is not
        // five hundred page reads — and on an installation that gains pgvector
        // later, that migration is already in the ledger and will never run
        // again to correct it. CREATE OR REPLACE happens to reset procost to
        // the default, which is 100 and is the right answer, but "happens to"
        // is not something the next person should have to rediscover from the
        // PostgreSQL manual. Saying it makes the two migrations agree in
        // writing rather than by accident.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_embed_distance(a real[], b real[])
            RETURNS double precision
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT COST 100 AS $$
                SELECT (a::vector) <=> (b::vector)
            $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        if (false === $this->hasVector()) {
            return;
        }

        // Back to the loop, not `DROP FUNCTION`. The function has to keep
        // existing under both bodies or every search 500s between this
        // migration reversing and Version20260826210000 reversing after it.
        //
        // The extension itself is left installed. Reversing this migration is
        // "stop using pgvector", not "uninstall the operator's extension" —
        // they may have put it there for something else, and DROP EXTENSION is
        // not a decision a rollback gets to make on their behalf.
        //
        // COST 50000, and this one MUST be spelled out or reversing this
        // migration reintroduces the bug Version20260829120000 exists to fix.
        // That migration priced the loop at 50000 — 0.132 ms a call, against
        // the 100 CREATE FUNCTION gives by default — and it is already applied,
        // so it will not run again to re-price a body this reinstalls. Without
        // this, `down()` would hand the planner a 1024-iteration plpgsql loop
        // labelled a quarter of a page read.
        //
        // The literal is duplicated from that migration on purpose: a migration
        // is a record of what was done, not a live reference to a constant that
        // may move. If it ever does move, both files are found by grepping for
        // plmail_embed_distance.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION plmail_embed_distance(a real[], b real[])
            RETURNS double precision
            LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE STRICT COST 50000 AS $$
            DECLARE
                total double precision := 0;
                width int := least(array_length(a, 1), array_length(b, 1));
                i int;
            BEGIN
                IF width IS NULL THEN
                    RETURN 2;
                END IF;

                FOR i IN 1..width LOOP
                    total := total + (a[i]::double precision * b[i]::double precision);
                END LOOP;

                RETURN 1 - total;
            END;
            $$
        SQL);
    }

    /**
     * Whether this database COULD have the extension.
     *
     * pg_available_extensions rather than pg_extension, matching
     * Version20260826210000: the question is whether the files are on disk in
     * the image, which is what an operator changes by swapping the database
     * image. Whether it is installed into this particular database is
     * CREATE EXTENSION IF NOT EXISTS's problem, one line further down.
     */
    private function hasVector(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM pg_available_extensions WHERE name = 'vector'",
        );
    }
}
