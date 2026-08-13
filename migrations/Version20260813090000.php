<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backoff state for a calendar whose sync keeps failing.
 *
 * The sweep asks findDueForSync() for calendars whose last_synced_at is old,
 * and a calendar that CANNOT sync never updates last_synced_at — so it comes
 * back due on every sweep, permanently. On the install this was written for,
 * one expired Google sign-in kept three calendars in that loop for two days and
 * produced 2 193 identical error lines and 503 dead-lettered jobs. These two
 * columns are what lets the query skip a calendar that is already known to be
 * failing until its next scheduled attempt.
 *
 * Both are added with defaults that mean "healthy, try immediately", so every
 * existing row — including the broken ones this was written for — starts from a
 * clean slate and gets exactly one attempt and one log line before any backoff
 * applies. Starting them backed-off would have been the tidier-looking choice
 * and the wrong one: it would suppress the first occurrence on rows where the
 * first occurrence has never actually been shown to anybody.
 *
 * sync_failure_count is NOT NULL with a default because it is a counter and a
 * null would have to be read as zero at every call site. sync_backoff_until is
 * nullable because null is a real state with its own meaning — no window is in
 * force — and is what findDueForSync() tests for explicitly.
 */
final class Version20260813090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds calendar.sync_failure_count and calendar.sync_backoff_until so a permanently failing sync backs off instead of retrying every sweep forever';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD sync_failure_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE calendar ADD sync_backoff_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar DROP sync_failure_count');
        $this->addSql('ALTER TABLE calendar DROP sync_backoff_until');
    }
}
