<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The evidence a report was missing.
 *
 * The first table shipped with the four verdicts — where it was, where it
 * belonged, and what each of Gmail, the rules and the model said — on the
 * reasoning that the verdicts are the decision. The first real report showed
 * they are not enough to act on: it read `filed:updates should:updates` about a
 * conversation its owner was reading in Primary, and nothing in the row could
 * explain how both of those were true.
 *
 * Two of them were, because `filed` was the message's category and the tabs
 * filter on the thread's. That is a code fix; these columns are the rest of it —
 * every input to the decision that the verdicts alone left somebody guessing at:
 *
 *   filed_message      the message's own row, when its thread disagrees
 *   pinned             somebody placed the conversation by hand
 *   override_provider  the reader had asked for Gmail to be overruled
 *   ai_asked           the model was consulted at all
 *   has_plain_text     there was anything but HTML for it to read
 *   list_id            the mailing's own name, to write a rule against
 *
 * Backfilled to the defaults an existing row would have had, which is honest
 * for the booleans and lossy for `list_id` — the headers are still on the
 * message, but a report is a snapshot and reconstructing one from the mailbox
 * it describes would be inventing evidence for a decision already made.
 */
final class Version20260904170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The evidence behind a category report, not only its verdicts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE category_report
                ADD filed_message VARCHAR(16) DEFAULT NULL,
                ADD pinned BOOLEAN DEFAULT FALSE NOT NULL,
                ADD override_provider BOOLEAN DEFAULT FALSE NOT NULL,
                ADD ai_asked BOOLEAN DEFAULT FALSE NOT NULL,
                ADD has_plain_text BOOLEAN DEFAULT FALSE NOT NULL,
                ADD list_id VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE category_report
                DROP filed_message,
                DROP pinned,
                DROP override_provider,
                DROP ai_asked,
                DROP has_plain_text,
                DROP list_id
        SQL);
    }
}
