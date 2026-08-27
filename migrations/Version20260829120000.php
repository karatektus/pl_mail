<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tell the planner what plmail_embed_distance() actually costs.
 *
 * WHAT WAS WRONG
 * ──────────────
 * `procost` is expressed in units of `cpu_operator_cost`, which is 0.0025 by
 * default. The function was created with the default 100, so the planner
 * believed one call cost 0.25 cost units — about a quarter of a sequential page
 * read, or roughly what a hundred integer comparisons cost.
 *
 * It is a plpgsql loop over 1024 dimensions. Measured at 0.132ms per call in
 * production and 0.119ms on the 20,000-row, 1024-dimension corpus this was
 * developed against. Not a quarter of a page read; five hundred of them.
 *
 * HOW 50000 WAS ARRIVED AT
 * ────────────────────────
 * Cost units have no absolute meaning, so they were calibrated against plans
 * whose real time is known. Two index-only scans over 20,000 rows on the test
 * database:
 *
 *     cost 798.30 → 1.366ms actual   (1.71 µs per cost unit)
 *     cost 1296.30 → 1.884ms actual  (1.45 µs per cost unit)
 *
 * So one cost unit is worth roughly 1.5µs here, and one `cpu_operator_cost`
 * about 4 nanoseconds. A 132µs function call is therefore about
 *
 *     132µs / 4ns ≈ 33,000 cpu_operator_costs
 *
 * against the 100 it was declared with — a factor of 330 out.
 *
 * ROUNDED UP, AND DELIBERATELY. 50000 rather than 33000 because every way this
 * number can be wrong is asymmetric. Cost units buy MORE time on slower
 * hardware and less on faster, and the failure being fixed is an
 * underestimate. The loop is proportional to the vector width, and 1024 is not
 * the widest model somebody can pick in the admin panel. And an overestimate
 * only ever makes the planner do things that are right anyway for a function
 * this expensive: evaluate it last among a row's predicates, prefer an index
 * that avoids calling it, and consider a parallel plan.
 *
 * WHY THIS IS INSURANCE RATHER THAN THE FIX
 * ─────────────────────────────────────────
 * The 12.9-second search was fixed in MessageThreadRepository::buildSearchSql()
 * by computing the distance once, inside a MATERIALIZED CTE, over the bounded
 * candidate set — not by anything the planner chooses. After that change the
 * function is only ever called on candidates, so this changes no plan anybody
 * has measured. It exists for the plans nobody can see: a `procost` off by two
 * orders of magnitude is a planner that will cheerfully put this function
 * anywhere, and the next query written against it has no reason to be as
 * carefully shaped as that one.
 *
 * ONE FUNCTION NAME, TWO POSSIBLE BODIES
 * ──────────────────────────────────────
 * Version20260826210000 creates a plpgsql loop, or — where the `vector`
 * extension is available — a one-line SQL wrapper around pgvector's `<=>`. The
 * number above describes the LOOP and would be a lie about the other one: `<=>`
 * is a SIMD dot product in C, some two orders of magnitude faster, and in
 * practice the planner never costs it at all because a single-SELECT SQL
 * function is inlined before costing (which is the whole reason it is written
 * that way — an inlined body is what makes an HNSW expression index reachable).
 *
 * So the body that is actually installed is read from the catalogue and the
 * cost is set to match it, exactly as the creating migration detects the
 * extension. The pgvector body keeps 100, which is about 0.4µs of the
 * calibration above and a fair price for a SIMD dot product on 1024 floats.
 *
 * Written as a lookup rather than a fixed statement so it cannot fail on either
 * installation, and skipped entirely if the function is not there — a migration
 * is not the place to discover that.
 */
final class Version20260829120000 extends AbstractMigration
{
    /**
     * What one call of the plpgsql loop costs, in units of `cpu_operator_cost`.
     * See the class docblock for the arithmetic.
     */
    private const int LOOP_COST = 50000;

    /** The default `procost`, which is right for the inlinable pgvector body. */
    private const int DEFAULT_COST = 100;

    public function getDescription(): string
    {
        return 'Raise plmail_embed_distance()\'s procost from 100 to 50000, so the planner prices the 1024-dimension loop at what it measures.';
    }

    public function up(Schema $schema): void
    {
        $this->setCost($this->costForInstalledBody());
    }

    /**
     * Back to the declared default for both bodies.
     *
     * Not "back to whatever it was", because there is nothing to read it from
     * by the time this runs, and 100 is what CREATE FUNCTION gives either body
     * in Version20260826210000.
     */
    public function down(Schema $schema): void
    {
        $this->setCost(null === $this->costForInstalledBody() ? null : self::DEFAULT_COST);
    }

    private function setCost(?int $cost): void
    {
        if (null === $cost) {
            $this->write('plmail_embed_distance() is not installed; leaving its cost alone.');

            return;
        }

        // Not a bound parameter: COST takes a literal, and the value is a
        // constant in this file rather than anything a caller supplies.
        $this->addSql('ALTER FUNCTION plmail_embed_distance(real[], real[]) COST ' . $cost);
    }

    /**
     * The cost the body that is actually installed deserves, or null when there
     * is no such function to alter.
     */
    private function costForInstalledBody(): ?int
    {
        // to_regprocedure() rather than a ::regprocedure cast, which RAISES on
        // a function that is not there — and answering that quietly is the
        // whole reason this lookup exists.
        $language = $this->connection->fetchOne(<<<'SQL'
            SELECT l.lanname
              FROM pg_proc p
              JOIN pg_language l ON l.oid = p.prolang
             WHERE p.oid = to_regprocedure('plmail_embed_distance(real[], real[])')
        SQL);

        if (false === $language) {
            return null;
        }

        if ('plpgsql' !== $language) {
            $this->write('plmail_embed_distance() is the pgvector body; leaving its cost at ' . self::DEFAULT_COST . '.');

            return self::DEFAULT_COST;
        }

        return self::LOOP_COST;
    }
}
