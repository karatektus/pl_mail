<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a person put a conversation in a category and have it stay there.
 *
 * The inbox tabs are computed, never chosen: MessageCategorizer reads the
 * stored headers — or Gmail's own CATEGORY_* labels — and MessageThreader
 * copies the newest message's answer onto the thread on every arrival
 * ("most-recent-wins"). That rule is right for mail nobody has an opinion
 * about and wrong the moment somebody does: dragging a newsletter out of
 * Promotions and into Primary would hold until the next issue arrived,
 * whereupon the cascade would put it back, silently, with nothing on screen to
 * say why.
 *
 * So the column is not a second category. It is a FLAG saying the category on
 * this row was chosen rather than derived, and every writer of a category
 * checks it: adoptCategory() leaves the row alone, and the `category` backfill
 * — which recomputes every thread's category in one UPDATE — excludes pinned
 * rows in SQL for the same reason.
 *
 * A TIMESTAMP RATHER THAN A BOOLEAN
 * ─────────────────────────────────
 * `category_pinned_at IS NOT NULL` is the only question the code asks, so a
 * boolean would do the job. The timestamp answers the next one for free —
 * when, and therefore how long ago the person decided — which is what a
 * support question about "why is this in Primary" needs and what a boolean
 * throws away. It is the same shape as listed_at, snoozed_until and starred_at
 * on this very table: this codebase spells "has this happened" as "when did it
 * happen" everywhere else, and one boolean in the middle of them would be the
 * odd one out.
 *
 * NULLABLE, WITH NO BACKFILL
 * ──────────────────────────
 * NULL means "nobody has said", which is true of every conversation that
 * exists when this runs. There is nothing to migrate: the derived category
 * already in `category` stays exactly as it is and goes on being recomputed.
 * The column only ever starts holding something when somebody drops a row on
 * a tab.
 *
 * No index. The flag is read one row at a time on a write path, and the one
 * query that filters on it — the backfill's UPDATE — is a full sweep of an
 * account's threads either way, so an index would be paid for on every insert
 * to save nothing on the only reader.
 */
final class Version20260903160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a conversation category was chosen by hand, so the categoriser stops overwriting it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread ADD category_pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN message_thread.category_pinned_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        // Dropping this loses which categories were chosen, and the next sync
        // or backfill puts those threads back where the cascade thinks they
        // belong. That is the honest reversal — the derived category is still
        // in `category` and was never overwritten by the pin.
        $this->addSql('ALTER TABLE message_thread DROP category_pinned_at');
    }
}
