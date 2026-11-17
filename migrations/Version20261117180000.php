<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pins and snoozes on one email (#615), beside the conversation's own.
 *
 * The list can show single emails instead of conversations, and a pin or a
 * snooze made there is that email's. All three columns are null for every
 * existing email: nothing was pinned or snoozed on its own before.
 *
 * Partial indexes, like the conversation's: only a handful of rows are ever
 * set, and the questions asked are "which emails are pinned" and "which are
 * due to wake".
 */
final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let one email be pinned or snoozed on its own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD pinned_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD snoozed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_pinned ON message (thread_id) WHERE (pinned_at IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_message_snoozed_until ON message (snoozed_until) WHERE (snoozed_until IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_snoozed_until');
        $this->addSql('DROP INDEX idx_message_pinned');
        $this->addSql('ALTER TABLE message DROP snoozed_until');
        $this->addSql('ALTER TABLE message DROP pinned_until');
        $this->addSql('ALTER TABLE message DROP pinned_at');
    }
}
