<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Six columns for what one person has decided about the AI an administrator
 * switched on.
 *
 * EVERY DEFAULT IS "AS IT BEHAVES TODAY"
 * ──────────────────────────────────────
 * The three feature columns are `false` because they store OFF rather than on —
 * see App\Entity\Embeddable\AiPreferences, where that spelling is the point —
 * so false means "this person has not opted out", which is every row on every
 * existing install. `ai_reply_context` defaults to 'message', which is what the
 * composer has always given the model. An upgrade therefore behaves identically
 * until somebody opens the settings page.
 *
 * NOT NULLABLE, WITH A DEFAULT, RATHER THAN NULLABLE
 * ──────────────────────────────────────────────────
 * Doctrine hydrates an embeddable's fields straight into typed properties, and
 * a null in `ai_search_off` would be a TypeError on every user read rather than
 * a missing preference. The default is what makes this additive without a
 * backfill statement: PostgreSQL fills existing rows as part of the ALTER.
 *
 * THE TWO TEXT COLUMNS ARE `TEXT` AND NOT `VARCHAR(600)`
 * ──────────────────────────────────────────────────────
 * The cap belongs to the application and is enforced twice there — in the
 * property hook on write, and again in WritingAssistant::persona() on read,
 * because property hooks are skipped on hydration. A column length would add a
 * third copy of the number that fails as a database error rather than a clamp,
 * and PostgreSQL stores short strings in `text` and `varchar(n)` identically.
 */
final class Version20260829090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the ai_* columns on "user": per-user AI feature opt-outs, persona and reply context depth.';
    }

    public function up(Schema $schema): void
    {
        // One statement rather than six. ALTER TABLE takes an ACCESS EXCLUSIVE
        // lock on `user`, which every request reads, so the number of times
        // that lock is taken is the thing worth minimising.
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                ADD ai_search_off BOOLEAN DEFAULT false NOT NULL,
                ADD ai_categorise_off BOOLEAN DEFAULT false NOT NULL,
                ADD ai_writing_help_off BOOLEAN DEFAULT false NOT NULL,
                ADD ai_system_prompt TEXT DEFAULT '' NOT NULL,
                ADD ai_about_me TEXT DEFAULT '' NOT NULL,
                ADD ai_reply_context VARCHAR(16) DEFAULT 'message' NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                DROP ai_search_off,
                DROP ai_categorise_off,
                DROP ai_writing_help_off,
                DROP ai_system_prompt,
                DROP ai_about_me,
                DROP ai_reply_context
        SQL);
    }
}
