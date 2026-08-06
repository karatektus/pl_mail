<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An invitation lands on the calendar when it is accepted, and not before.
 *
 * The answer itself already had somewhere to live: RFC 8984 puts it on the
 * participant, and it has been written there since invitations were first read
 * out of mail — `jscalendar.participants[…].participationStatus`. What it did
 * not have was a way to *act* on it. That key is inside a jsonb object under a
 * key named after the reader's own email address, which nothing can filter on
 * and nothing can index, so the calendar drew every invitation the moment it
 * arrived: a week of meetings somebody had not agreed to, indistinguishable from
 * the ones they had.
 *
 * So the answer is projected out, exactly like `status`, `starts_at` and every
 * other column on this table: the object stays canonical, the column is what a
 * query reads. It gates one thing — whether RecurrenceMaterialiser writes
 * occurrence rows — and every reader follows from that without a clause of its
 * own, because occurrences are what the views, the alert sweep, Happening Soon,
 * a share link, an .ics export and a JMAP client all read.
 *
 * **NULL is the important value and is not "has not answered".** It means the
 * event is not an invitation addressed to the owner at all, which is nearly
 * every row: something they typed, a booking read out of a confirmation, a
 * calendar mirrored from a provider, or a meeting they organised themselves.
 * Those are drawn unconditionally. Conflating the two would empty every calendar
 * on this install the moment the column existed, which is also why there is no
 * backfill here and why the column is nullable rather than defaulted.
 *
 * The consequence of no backfill, stated because it is a real one: an invitation
 * that arrived before this migration keeps NULL and stays on the calendar
 * whatever was answered. That is the conservative direction — nothing
 * disappears from anybody's calendar on upgrade — and re-running
 * `app:backfill events` over the mail re-reads the invitations and fills it in.
 *
 * Reversible and lossless in the direction that matters: dropping the column
 * makes every invitation visible again, which is what the previous version did.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project the owner\'s RSVP onto calendar_event, so an unanswered invitation is not drawn';
    }

    public function up(Schema $schema): void
    {
        // 16 to match `status` and `privacy` beside it; the longest value this
        // holds is "needs-action" at twelve.
        $this->addSql('ALTER TABLE calendar_event ADD my_participation VARCHAR(16) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN calendar_event.my_participation IS
                'The owner''s own RSVP, projected from jscalendar.participants. NULL means this is not an invitation addressed to them and is always drawn.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event DROP my_participation');
    }
}
