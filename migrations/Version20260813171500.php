<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give "undo send" something it cannot lose a race to.
 *
 * `cancelled` was a flag one side wrote and the other side read, with the send
 * in between, and that is not a decision — it is two events with no defined
 * order and no way for either party to discover what the order had been. The
 * failure was reproducible: cancel a ten-second hold at 9.9s and the composer
 * answered HTTP 200 with "send cancelled", while the message went out over
 * SMTP. The row was left saying both things at once — `cancelled = true` beside
 * a populated `sent_at`.
 *
 * send_claimed_at turns that into one statement per side. The handler claims
 * `WHERE send_claimed_at IS NULL AND cancelled = false AND sent_at IS NULL`;
 * the cancel claims `WHERE send_claimed_at IS NULL AND sent_at IS NULL`;
 * Postgres decides which UPDATE matched a row, and both sides learn whether
 * they won. That last part is the user-visible half — it is what lets the
 * composer say "too late, it has gone" instead of confirming a cancellation
 * that never happened.
 *
 * Nullable, additive, no backfill and none needed: a claim means "a handler is
 * working on this message right now", so the correct value for every row that
 * exists at migration time is exactly the NULL they get. Messages already sent
 * are described by sent_at as they always were.
 */
final class Version20260813171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.send_claimed_at so a send and its cancel cannot both win';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD send_claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP send_claimed_at');
    }
}
