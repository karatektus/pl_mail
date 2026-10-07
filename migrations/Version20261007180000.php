<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where the time went when the assistant was asked about a message: how long
 * it queued, how long the call took, and how much of that was the model being
 * loaded. See Message::$aiQueueMs.
 *
 * Three nullable columns and nothing else. Adding a nullable column with no
 * default rewrites no rows, so this is instant on a mailbox of any size, and
 * there is no index: the only reader starts from `category_held_at`, which has
 * one.
 *
 * Not backfilled. Mail asked about before this has no record of how long it
 * took, and Admin → Performance shows a dash for it.
 */
final class Version20261007180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record how long classifying each message took: message.ai_queue_ms, ai_call_ms, ai_load_ms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD ai_queue_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD ai_call_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD ai_load_ms INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP ai_queue_ms');
        $this->addSql('ALTER TABLE message DROP ai_call_ms');
        $this->addSql('ALTER TABLE message DROP ai_load_ms');
    }
}
