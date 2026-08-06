<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make a held EmailSubmission something the server can still describe.
 *
 * A submission id is the Email id and there is no submission table, so
 * everything `EmailSubmission/get` answers has to be reconstructible from the
 * message row. Two facts were not on it. The release time of a scheduled send
 * existed only in the create response — the DelayStamp went onto a messenger
 * envelope and nowhere else — so a client that lost the response could never
 * ask again, and every client was pushed into keeping its schedules
 * device-local. And a cancel left no durable trace: `cancelled` is a one-shot
 * flag SendMessageHandler clears when the envelope comes due, so half an hour
 * later nothing remembered that anything had been cancelled.
 *
 * submission_send_at is written for every accepted submission, which also makes
 * it the marker that one exists — that is what lets get distinguish "queued,
 * not gone yet" from "a draft nobody ever submitted", where both used to be
 * notFound. submission_cancelled_at is the durable half of the cancel.
 *
 * Both nullable, both additive, no backfill and none possible: a submission
 * that completed before this migration is described by sent_at exactly as it
 * always was, and one still held at this moment has its release time only in
 * the messenger envelope, which is not a table this migration can read. Such a
 * submission keeps answering as it did before — notFound until it sends — and
 * there is at most one hold's worth of them.
 */
final class Version20260806163218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep a submission\'s release time and its cancel on the message row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD submission_send_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD submission_cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP submission_cancelled_at');
        $this->addSql('ALTER TABLE message DROP submission_send_at');
    }
}
