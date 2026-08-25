<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record that a sent message came back undelivered.
 *
 * plMail could already tell you a message had been read. It could not tell you
 * the opposite — a bounce arrived in the Inbox as an SMTP transcript titled
 * "Undelivered Mail Returned to Sender", and the message it was about went on
 * saying "Sent". The one delivery outcome a person has to act on was the one
 * outcome not attached to anything.
 *
 * Status is kept as the reporting MTA wrote it rather than as a boolean, so
 * that 4.x.x and 5.x.x stay distinguishable and plMail is never the thing
 * deciding a failure was final.
 */
final class Version20260826030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.bounced_at, bounce_status, bounce_recipient and bounce_diagnostic.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD bounced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD bounce_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD bounce_recipient VARCHAR(320) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD bounce_diagnostic TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP bounced_at');
        $this->addSql('ALTER TABLE message DROP bounce_status');
        $this->addSql('ALTER TABLE message DROP bounce_recipient');
        $this->addSql('ALTER TABLE message DROP bounce_diagnostic');
    }
}
