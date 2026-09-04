<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let each person choose what sorts their mail, and whether it outranks Gmail.
 *
 * WHAT CHANGES FOR AN EXISTING MAILBOX, SAID PLAINLY
 * ──────────────────────────────────────────────────
 * Both columns default to the behaviour that has always shipped — headers
 * decide, and the provider's own categories win where it has any — with one
 * exception that is worth naming rather than discovering.
 *
 * The model's verdict used to be consulted as a TIE-BREAK: read only where the
 * header cascade found nothing and fell through to Primary. That was invisible
 * and unchoosable — no setting turned it on, none turned it off, and nothing on
 * screen said it had happened. It is now what CategorySource::Assistant means,
 * and `rules` means what it says.
 *
 * So an installation with AI categorisation switched on loses that silent
 * tie-break until somebody chooses the assistant in their own settings. That is
 * a real change and the reason it is the right one: the alternative is a model
 * quietly sorting mail on behalf of people who never asked it to, which is
 * exactly the arrangement this setting exists to end.
 *
 * Nothing is re-sorted by this migration. The category is recomputed from
 * stored data, so an existing mailbox keeps the categories it has until
 * `app:backfill category` is run — which is the operator's call, not a
 * migration's.
 */
final class Version20260904150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-user choice of what sorts mail into tabs, and whether it overrules the provider.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD category_source VARCHAR(16) DEFAULT \'rules\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD category_override_provider BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP category_source');
        $this->addSql('ALTER TABLE "user" DROP category_override_provider');
    }
}
