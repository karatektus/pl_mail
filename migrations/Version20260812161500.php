<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a thread was first shown in a list — the "new mail" marker.
 *
 * Null means new. The column is therefore nullable, and a plain ADD COLUMN
 * would leave every row in every existing mailbox null, which is to say NEW:
 * the first person to open plMail after this deploy would find their entire
 * archive wearing a badge, every category dotted, and no way to clear it but to
 * page through years of mail. That is the whole reason the backfill below
 * exists, and it is not an optimisation.
 *
 * NOW() rather than the thread's own last_message_at: the value only has to be
 * non-null to mean "not new", and a per-row timestamp derived from the mail
 * would be inventing a fact — nobody was shown that row at that moment, the
 * deploy is simply the point from which the marker starts meaning anything.
 *
 * Two statements rather than ADD COLUMN ... DEFAULT NOW(): a default would keep
 * firing forever and every newly ingested thread would be born already-seen,
 * which is the feature not working at all. So the column is added bare, the
 * existing rows are stamped once, and nothing carries a default afterwards.
 */
final class Version20260812161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds message_thread.listed_at (the new-mail marker) and backfills existing threads as already seen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread ADD listed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // The load-bearing half. Everything that existed before this feature
        // did was, by definition, already available to be looked at.
        $this->addSql('UPDATE message_thread SET listed_at = NOW() WHERE listed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread DROP listed_at');
    }
}
