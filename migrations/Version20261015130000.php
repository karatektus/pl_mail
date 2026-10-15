<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drafts synced from Outlook or Gmail before the builders stopped giving them a
 * sentAt: give them back the null sentAt an unsent draft has, so the editor
 * opens them (see Message::$sentAt and ComposeController::assertEditableDraft()).
 *
 * NARROWER THAN "EVERY ROW FLAGGED \Draft", on purpose. Before this change a
 * draft sent from another client stayed `\Draft` here, because sync never
 * rebuilds a stored row and nothing settled it. Clearing such a row's sentAt
 * would make mail that has already gone out editable and sendable a second time,
 * which is the danger Message::settleAsSent() exists to prevent. So only a row
 * the provider still files as a draft, as of its last sync, is changed:
 *
 *   - Gmail: its label ids hold DRAFT and not SENT, the test
 *     SyncGmailMessageBatchHandler applies;
 *   - Graph: it wears the Drafts label and not the Sent one, Graph's folders as
 *     GraphApiSyncer::attachFolderLabel() files them.
 *
 * A stale row of the other kind keeps its sentAt and stays refused by the
 * editor, exactly as before, until the provider reports it and the sync settles
 * it. IMAP rows are left alone: their stored flag is `Draft` without the
 * backslash, which isDraft() does not read as a draft, so the builder change
 * does not reach them either.
 *
 * Containment (@>) rather than jsonb's `?` operator: Doctrine prepares the
 * statement, and a `?` is a parameter placeholder to it, so `?` would reach
 * Postgres as `$1` and stop every upgrade at this migration.
 *
 * The thread's lastMessageAt is not touched. It was raised by the draft's date
 * when the draft was stored; lowering it now would move conversations down the
 * list on upgrade, for no one's benefit.
 */
final class Version20261015130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear sent_at on Gmail and Outlook drafts still filed as drafts, so the editor opens them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE message m
               SET sent_at = NULL
             WHERE m.sent_at IS NOT NULL
               AND m.flags::jsonb @> '["\\Draft"]'
               AND (
                    (
                        m.gmail_id IS NOT NULL
                        AND m.gmail_label_ids::jsonb @> '["DRAFT"]'
                        AND NOT m.gmail_label_ids::jsonb @> '["SENT"]'
                    )
                 OR (
                        m.graph_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1 FROM message_label ml JOIN label l ON l.id = ml.label_id
                             WHERE ml.message_id = m.id AND l.role = 'drafts'
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM message_label ml JOIN label l ON l.id = ml.label_id
                             WHERE ml.message_id = m.id AND l.role = 'sent'
                        )
                    )
               )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to put back: the old sentAt was the draft's receivedAt, which
        // is still on the row, and putting it back would only make the drafts
        // unopenable again.
    }
}
