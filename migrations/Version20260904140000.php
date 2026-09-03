<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember whether a stored summary was written from the whole conversation.
 *
 * WHY THIS CANNOT BE DERIVED LIKE THE REST OF THE CARD
 * ───────────────────────────────────────────────────
 * "Is this conversation too long to send in full" is a question about the
 * thread, and MailController answers it for free from the transcript the
 * freshness check already builds. "Was this particular summary written from all
 * of it" is a question about the ROW, and the thread cannot answer it: a thread
 * that is still too long is still too long after somebody presses "summarise
 * the whole conversation" and gets a summary that did see all of it.
 *
 * Without this column that press would appear to do nothing. The summary would
 * change, the notice saying the model had not seen everything would stay, and
 * the reader would have spent a much longer wait for a card that still says the
 * work was not done.
 *
 * DEFAULT FALSE, which is true of every row already stored: nothing before this
 * release could send a full conversation, because nothing before it sent
 * num_ctx at all.
 */
final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record whether a stored thread summary was written from the whole conversation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE thread_summary ADD full_context BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE thread_summary DROP full_context');
    }
}
