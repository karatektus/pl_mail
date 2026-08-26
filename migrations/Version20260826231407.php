<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a background job a heartbeat, so an abandoned one can be told from a
 * slow one.
 *
 * `background_job` carried created_at and finished_at and nothing in between,
 * which meant a worker killed mid-run — a container restarted during a deploy,
 * an OOM kill, a fatal that never reached finish() — left a row in `running`
 * that was byte for byte identical to a job still working. The indicator query
 * had no time bound on its active arm, so three "Marking as read" jobs sat in
 * one user's topbar at 1400/1770, 600/1180 and 100/1275 for weeks, with nothing
 * on the row that could ever have cleared them.
 *
 * THE BACKFILL IS THE POINT OF THIS MIGRATION, not the column.
 *
 * Added nullable, backfilled, then made NOT NULL — the sequence CODESTYLE
 * requires, because a NOT NULL column with no default cannot be added to a
 * table that already has rows, and this one always does on precisely the
 * installs that have the bug.
 *
 * The backfilled value is `created_at`, and the choice is load-bearing in both
 * directions:
 *
 *   - Leaving it NULL, or stamping NOW(), makes every pre-existing row look
 *     freshly alive. The three stranded jobs would survive the deploy that was
 *     meant to be rid of them and stay on screen for ever, which is the entire
 *     reported symptom.
 *   - Stamping a fixed instant in the past — or, equivalently, teaching the
 *     query to read NULL as "long dead" — condemns every row, including a bulk
 *     action a user started two minutes before the deploy and is watching right
 *     now. It would be reported as failed while its worker was still marking
 *     mail read, and the user would be told nothing happened when it had.
 *
 * created_at gets both right, because it is the one thing already known about
 * the row that correlates with whether anybody is still waiting on it. A job
 * stranded weeks ago is instantly older than the fifteen-minute staleness
 * window and is swept on the next run of app:jobs:reap; a job started moments
 * ago is inside that window and is left alone until its own next chunk stamps
 * the column properly. The worst case is a genuinely long-running job in flight
 * across the deploy: it reads as stale until its next chunk lands, which is
 * seconds to minutes away, and that chunk puts it back on screen.
 *
 * No default is left behind on the column. Every row is written through
 * App\Entity\Job\BackgroundJob, whose constructor stamps it from the same
 * instant as created_at; a lingering DEFAULT NOW() would exist only to rescue a
 * writer that must not exist.
 */
final class Version20260826231407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add background_job.last_progress_at and backfill it from created_at so stranded jobs read as stale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_job ADD last_progress_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // The load-bearing half — see the docblock. Anything that existed before
        // this column did last showed a sign of life, at the very latest, when
        // it was created.
        $this->addSql('UPDATE background_job SET last_progress_at = created_at WHERE last_progress_at IS NULL');

        $this->addSql('ALTER TABLE background_job ALTER COLUMN last_progress_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_job DROP last_progress_at');
    }
}
