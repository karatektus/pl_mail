<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An index on the Message-IDs a message points at, for threading the other
 * way round (#172).
 *
 * Threading used to look in one direction only: a reply finds the message it
 * answers. When the answer was synced first — the replies sit in INBOX, the
 * message they answer in Sent Items, and the folders are synced one after the
 * other — nothing ever looked back, and one conversation stayed split over as
 * many threads as the order of arrival made. MessageThreader now also asks,
 * for every message it threads, which messages already name it in
 * In-Reply-To or References. That is a lookup by an element of two json
 * arrays, on every synced message, and without an index it is a scan of the
 * account's whole mailbox per message: quadratic over an import.
 *
 * An EXPRESSION index rather than a column, so nothing has to be backfilled
 * or kept in step: the two arrays are concatenated as jsonb, and
 * jsonb_path_ops serves `@>` — containment — which is the one operator the
 * lookup uses. `?` would read more naturally and is the operator DBAL takes
 * for a positional placeholder. COALESCE because either column may be NULL,
 * and `NULL || x` is NULL, which would leave a reply that has References but
 * no In-Reply-To out of the index altogether.
 *
 * The expression has to be the one MessageRepository::REFERENCED_IDS spells,
 * character for character in meaning: the planner only uses an expression
 * index for a query that states the same expression.
 *
 * Not in the entity mapping, like the trigram indexes of
 * Version20260811161000: Doctrine's attributes cannot describe an index on an
 * expression.
 *
 * CONCURRENTLY is not used, for that migration's reason: it cannot run inside
 * the transaction migrations execute in. Building it takes a write lock on
 * `message` for as long as the build lasts.
 */
final class Version20261016130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the Message-IDs each message references, so a late parent can find its replies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_message_referenced_ids ON message USING GIN (
                (COALESCE(in_reply_to::jsonb, '[]'::jsonb) || COALESCE(thread_references::jsonb, '[]'::jsonb))
                jsonb_path_ops
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_message_referenced_ids');
    }
}
