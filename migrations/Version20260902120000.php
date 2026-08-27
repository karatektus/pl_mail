<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The system prompts become editable, and the summary cache stops depending on
 * somebody remembering to bump a number.
 *
 * TWO CHANGES IN ONE MIGRATION, BECAUSE THEY ARE ONE CHANGE
 * ─────────────────────────────────────────────────────────
 * The seven columns on `ai_settings` are what makes a prompt editable. The
 * column swap on `thread_summary` is what stops that from silently corrupting
 * a cache. Shipping the first without the second is strictly worse than
 * shipping neither — see below — so they are not separable and a deployment
 * cannot end up half-way between them.
 *
 * WHY `prompt_version` HAD TO GO
 * ──────────────────────────────
 * Version20260902100100 gave `thread_summary` an INT `prompt_version` and
 * ThreadSummariser held the matching constant, whose docblock read "bumped
 * whenever SYSTEM_PROMPT below changes". That is a sound design while the only
 * way to change the prompt is a commit somebody reviews: a human edits the text
 * and the same human edits the integer two lines above it.
 *
 * An administrator editing that prompt in Admin → AI bumps nothing. Every
 * summary already on file would keep matching — same model, same transcript,
 * same version — so every one of them would go on being displayed as current,
 * written by instructions the settings page says are no longer in use, on a
 * feature whose entire risk is being confidently wrong about mail the reader is
 * not going to check. A cache that depends on a person remembering is not
 * invalidated; it is a cache that is wrong later.
 *
 * So the key became a fact about the request instead of a number somebody
 * maintains: `prompt_hash` is the SHA-256 of the exact system message sent to
 * the model — the prompt in force, shipped or overridden, with the language
 * rule already appended. That is precisely the argument the same table's
 * `source_hash` already makes about the transcript, and it buys the same thing:
 * editing a prompt, clearing one back to the shipped wording, or taking an
 * upgrade that improves the shipped wording each produces a different string on
 * the wire, therefore a different hash, therefore rows that stop matching, with
 * nothing anywhere having to notice. Two mechanisms for one fact is what
 * Version20260828000000 refuses; this leaves one.
 *
 * NOTHING IS DELETED, WHICH IS THE TABLE'S OWN POSTURE
 * ────────────────────────────────────────────────────
 * The new column is added with a DEFAULT of '' so the existing rows can take a
 * NOT NULL, and the default is then dropped so nothing new can be written
 * without a real digest. An empty string is not a possible SHA-256 hex digest —
 * those are always 64 characters — so every row written before this migration
 * matches nothing and stops being SHOWN, which is exactly what bumping the
 * integer would have done. ThreadSummaryStore says the rest: "there is at most
 * one row per thread ever and an unusable one is replaced by the upsert the
 * moment anybody re-summarises. No pruner, no console command." A DELETE would
 * have been the same outcome with a lock on the table.
 *
 * VARCHAR(64), for the reason Version20260902100100 spells out about
 * `source_hash` and which applies identically here: PostgreSQL blank-pads
 * char(n) and hands the padding back, and a padded digest compares unequal to
 * the one that was stored — which does not error, it renders as a summary that
 * is permanently out of date on every open.
 *
 * WHY THE OVERRIDES ARE SEVEN NULLABLE COLUMNS
 * ────────────────────────────────────────────
 * NULL means "use the text this release ships", and the shipped text is never
 * copied in — not on first save, not on install. A row holding a copy of the
 * default would pin the installation to the wording of whichever release it was
 * saved under, so every later improvement to a prompt would reach new
 * installations and silently skip every existing one. See App\Entity\Embeddable
 * \AiPrompts, which is where that argument lives with the clamp.
 *
 * TEXT rather than VARCHAR(n): the cap is a budget about the model's context
 * window rather than a fact about storage, it is enforced by the property hook
 * and published to the page as `maxlength` from one constant, and a length in
 * the schema as well would be a second copy of a number that is expected to
 * move. `ai_settings` has one row.
 */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin-editable system prompts, and a summary cache keyed by the prompt actually sent rather than a hand-bumped version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE ai_settings
                ADD prompt_reply TEXT DEFAULT NULL,
                ADD prompt_shorten TEXT DEFAULT NULL,
                ADD prompt_formal TEXT DEFAULT NULL,
                ADD prompt_proofread TEXT DEFAULT NULL,
                ADD prompt_summary TEXT DEFAULT NULL,
                ADD prompt_categorise TEXT DEFAULT NULL,
                ADD prompt_language TEXT DEFAULT NULL
        SQL);

        // The index goes first: it names the column being dropped, and
        // PostgreSQL would take it with the column and leave the recreate below
        // looking like it had always been there.
        $this->addSql('DROP INDEX idx_thread_summary_model');

        $this->addSql('ALTER TABLE thread_summary DROP prompt_version');

        // DEFAULT '' so the rows already there can satisfy NOT NULL, then the
        // default dropped so nothing new is ever written without a digest. See
        // the class docblock: '' matches no fingerprint, so those rows become
        // invisible rather than wrong.
        $this->addSql("ALTER TABLE thread_summary ADD prompt_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE thread_summary ALTER COLUMN prompt_hash DROP DEFAULT');

        $this->addSql('CREATE INDEX idx_thread_summary_model ON thread_summary (model, prompt_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_thread_summary_model');

        // Back to 1, which is the only value the constant ever held. Going down
        // means going back to a build that filters on it, and every row here was
        // written by whatever prompt was in force at the time — so 1 makes them
        // visible again to a build that cannot tell the difference, which is the
        // same amount of truth the integer ever carried.
        $this->addSql('ALTER TABLE thread_summary DROP prompt_hash');
        $this->addSql('ALTER TABLE thread_summary ADD prompt_version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE thread_summary ALTER COLUMN prompt_version DROP DEFAULT');

        $this->addSql('CREATE INDEX idx_thread_summary_model ON thread_summary (model, prompt_version)');

        $this->addSql(<<<'SQL'
            ALTER TABLE ai_settings
                DROP prompt_reply,
                DROP prompt_shorten,
                DROP prompt_formal,
                DROP prompt_proofread,
                DROP prompt_summary,
                DROP prompt_categorise,
                DROP prompt_language
        SQL);
    }
}
