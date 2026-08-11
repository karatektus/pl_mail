<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The column that stops inbound flag sync from undoing the user.
 *
 * Reading the server's flags back into plMail introduces a race the outbound
 * direction never had. The user marks a message read; the local row changes
 * first, because the database is the source of truth; an ApplyImapFlagsMessage
 * is queued to tell the server. Between those two moments the server still
 * says unread — and an inbound pass that believes it would revert the local
 * change, which would queue a *second* outbound job, which the next pass would
 * revert again. A flap, driven by nothing but the two directions disagreeing
 * about who spoke last.
 *
 * `flags_touched_at` is the answer: the instant a local flag change was made
 * that the provider has not yet confirmed. It is written where the outbound job
 * is queued and cleared where that job reports success, so a non-null value
 * means exactly "there is a flag change in flight". The inbound pass declines
 * to touch those rows, and only those.
 *
 * Existing rows get NULL, which is the correct answer for every one of them:
 * nothing is in flight for a message nobody has touched since this deployed.
 */
final class Version20260811150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the instant of an unconfirmed local flag change, so inbound flag sync cannot revert it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD flags_touched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // The inbound pass asks "is anything in flight for this row" once per
        // message it is about to change, and the answer is null for nearly
        // every row nearly always. A partial index is the whole table's worth
        // of that question in the few pages where the answer is interesting.
        $this->addSql('CREATE INDEX idx_message_flags_touched_at ON message (flags_touched_at) WHERE flags_touched_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_flags_touched_at');
        $this->addSql('ALTER TABLE message DROP flags_touched_at');
    }
}
