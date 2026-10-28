<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every stored flag list in the one spelling MessageFlag::canonicalList()
 * writes, so stored lists can be compared as they are (#421).
 *
 * webklex stored IMAP flags without their backslash (`Seen`, `Draft`) and kept
 * `\Recent`; plMail's own client stores `\Seen`, `\Draft`, keywords kept,
 * `\Recent` dropped, sorted. Until every row is in that form, each comparison
 * against a stored list had to canonicalise it first. After this one, none has
 * to. The rewrite is canonicalList() in SQL: a system flag in any case and with
 * or without its backslash becomes the enum's spelling, `\Recent` and empty
 * entries go, duplicates go, and the list is sorted bytewise (COLLATE "C", as
 * PHP's SORT_STRING does). Anything else is kept verbatim.
 *
 * First, IMAP drafts. A `Draft` without its backslash was not a draft to
 * Message::isDraft(), so a draft synced over IMAP kept its Date header as its
 * sentAt and the editor refused it. Once its flag reads `\Draft` that row would
 * be a draft the editor still refuses, so its sentAt is cleared — but only
 * where the server still files it in the Drafts folder, for the reason
 * Version20261015130000 gives: clearing the sentAt of mail that has gone out
 * would make it sendable a second time. Only rows still spelled `Draft` are
 * touched there, which is exactly the rows webklex wrote.
 *
 * Containment (@>) rather than jsonb's `?` operator, for the reason given in
 * Version20261015130000: Doctrine prepares the statement.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store every message flag list in IMAP spelling, and open IMAP drafts still in Drafts in the editor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE message m
               SET sent_at = NULL
              FROM mailbox mb
             WHERE mb.id = m.mailbox_id
               AND mb.special_use = '\Drafts'
               AND m.sent_at IS NOT NULL
               AND m.flags::jsonb @> '["Draft"]'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE message m
               SET flags = c.flags
              FROM (
                    SELECT id,
                           COALESCE(
                               (
                                   SELECT json_agg(f ORDER BY f COLLATE "C")
                                     FROM (
                                           SELECT DISTINCT
                                                  CASE lower(ltrim(e, '\'))
                                                      WHEN 'answered' THEN '\Answered'
                                                      WHEN 'flagged'  THEN '\Flagged'
                                                      WHEN 'deleted'  THEN '\Deleted'
                                                      WHEN 'seen'     THEN '\Seen'
                                                      WHEN 'draft'    THEN '\Draft'
                                                      ELSE e
                                                  END AS f
                                             FROM json_array_elements_text(message.flags) e
                                            WHERE ltrim(e, '\') <> ''
                                              AND lower(ltrim(e, '\')) <> 'recent'
                                          ) entries
                               ),
                               '[]'::json
                           ) AS flags
                      FROM message
                     WHERE json_typeof(message.flags) = 'array'
                   ) c
             WHERE c.id = m.id
               AND c.flags::jsonb <> m.flags::jsonb
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to put back: both spellings meant the same flags, the old one
        // is what every comparison had to work around, and the cleared sentAt
        // was a draft's Date header, which no draft has been sent at.
    }
}
