<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a held calendar claim says why it is held. See
 * App\Entity\Calendar\EventSourceLink::$holdReason.
 *
 * One nullable column, null on every existing row: nothing recorded before
 * this was held for a reason the reader could overrule, so there is nothing to
 * backfill and no existing event changes.
 */
final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event_source_link.hold_reason for calendar changes waiting on the reader';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_source_link ADD hold_reason VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_source_link DROP hold_reason');
    }
}
