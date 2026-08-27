<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The fourth AI feature's two switches: thread summaries.
 *
 * TWO STATEMENTS, ONE LEDGER ENTRY
 * ────────────────────────────────
 * They are two tables but one fact — "the fourth feature exists" — and the two
 * columns are meaningless apart: AiPermissions ANDs them on every read, so an
 * installation that had the ceiling and not the floor, or the other way round,
 * is a TypeError on a user row rather than half a feature. Splitting them would
 * make that state reachable for as long as it took the second file to run.
 *
 * BOTH ARE `DEFAULT false NOT NULL`, AND THE USER ONE HAS TO BE
 * ────────────────────────────────────────────────────────────
 * Version20260829090000 gives the reason for the embeddable's columns and it
 * has not changed: Doctrine hydrates an embeddable's fields straight into typed
 * properties, so a NULL in `ai_summary_off` is a TypeError on EVERY user read
 * rather than a missing preference. The default is also what makes this
 * additive without a backfill statement — PostgreSQL fills existing rows as
 * part of the ALTER.
 *
 * FALSE ON BOTH SIDES MEANS "BEHAVES AS IT DID YESTERDAY"
 * ───────────────────────────────────────────────────────
 * The administrator's column is false because off is the default and off is a
 * real state (see AiSettings); the user's is false because it stores OFF rather
 * than on, so false is "this person has not opted out". An upgrade therefore
 * changes nothing at all until an administrator switches the feature on.
 *
 * NO MIGRATION FOR ai_call_metric. Version20260827000000 declares `feature` as
 * VARCHAR(32) with no CHECK and no enumType, and AiCallRecorder writes the
 * enum's value with no per-case branching — so 'thread_summary' needs nothing,
 * and idx_ai_call_metric_feature_created already covers it.
 */
final class Version20260902100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the fourth AI feature\'s switches: ai_settings.summary_enabled and "user".ai_summary_off.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD summary_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD ai_summary_off BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP summary_enabled');
        $this->addSql('ALTER TABLE "user" DROP ai_summary_off');
    }
}
