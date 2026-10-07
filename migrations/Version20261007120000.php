<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Holding arriving mail out of the inbox tabs until the assistant has sorted
 * it: the two timestamps on the message, and the switch and the ceiling on the
 * AI settings.
 *
 * See Message::$categoryHeldAt for what the pair means and AiSettings for why
 * the switch defaults to on.
 *
 * NOTHING IS BACKFILLED, and nothing should be. Every existing message has
 * both timestamps null, which reads as "was never held" — true of all of them.
 *
 * The index is partial for the reason idx_message_ai_categorised is: the two
 * queries that read these columns — the minute sweep for mail held too long,
 * and the delay figures in Admin → AI — both start from "was held", and on an
 * installation that never sorts by assistant that is no row at all. An
 * unconditional index would be one more entry per message, on every install,
 * for a feature most of them have switched off.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hold arriving mail for the assistant: message.category_held_at/released_at, ai_settings.hold_*';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD category_held_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD category_released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_category_held ON message (category_held_at) WHERE category_held_at IS NOT NULL');

        $this->addSql('ALTER TABLE ai_settings ADD hold_until_classified BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE ai_settings ADD hold_max_seconds INT DEFAULT 60 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_category_held');
        $this->addSql('ALTER TABLE message DROP category_held_at');
        $this->addSql('ALTER TABLE message DROP category_released_at');
        $this->addSql('ALTER TABLE ai_settings DROP hold_until_classified');
        $this->addSql('ALTER TABLE ai_settings DROP hold_max_seconds');
    }
}
