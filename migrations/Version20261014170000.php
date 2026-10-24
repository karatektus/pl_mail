<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inbox categories the reader makes themselves: the table, and the two columns
 * that file mail and conversations under one.
 *
 * See App\Entity\Mail\InboxCategory for what a category is, Message::$ruleCategory
 * for why the filter's verdict has a column of its own, and
 * MessageThread::$userCategory for how a conversation is shown in one.
 *
 * NOTHING IS BACKFILLED, and nothing should be: every existing message and
 * thread has no user category, which is true of all of them. Nobody's inbox
 * changes shape until they make a category and a filter that files into it.
 *
 * Both new columns are nullable with no default, so adding them rewrites no row
 * on the two largest tables in the schema. Their indexes are partial for the
 * reason idx_message_category_held is: the two questions asked of them — which
 * mail is in this category, and what to un-file when one is removed — both start
 * from "has one", and on an installation with no categories that is no row at
 * all. An unconditional index would be one more entry per message and per
 * thread, on every installation, for a feature most have never switched on.
 *
 * The foreign keys are ON DELETE SET NULL, which is the point: removing a
 * category un-files its mail in the database, leaving no pointer at a row that
 * is gone. The owner's foreign key is CASCADE, so a category goes with its user.
 *
 * And two settings on the user, beside the other sorting choices
 * (CategorySorting): which built-in tabs are switched off, and the order of all
 * of them. An empty list is the shipped behaviour for both — every tab on, the
 * built-ins in their usual order — so no row is rewritten for them either.
 */
final class Version20261014170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inbox_category, and message.rule_category_id / message_thread.user_category_id to file mail under one';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inbox_category (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                name VARCHAR(40) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_inbox_category_usr ON inbox_category (usr_id)');
        $this->addSql('ALTER TABLE inbox_category ADD CONSTRAINT FK_inbox_category_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE message ADD rule_category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_message_rule_category FOREIGN KEY (rule_category_id) REFERENCES inbox_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_message_rule_category ON message (rule_category_id) WHERE rule_category_id IS NOT NULL');

        $this->addSql('ALTER TABLE message_thread ADD user_category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_message_thread_user_category FOREIGN KEY (user_category_id) REFERENCES inbox_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_message_thread_user_category ON message_thread (user_category_id) WHERE user_category_id IS NOT NULL');

        $this->addSql('ALTER TABLE "user" ADD category_disabled JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD category_tab_order JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP category_tab_order');
        $this->addSql('ALTER TABLE "user" DROP category_disabled');

        $this->addSql('DROP INDEX idx_message_thread_user_category');
        $this->addSql('ALTER TABLE message_thread DROP CONSTRAINT FK_message_thread_user_category');
        $this->addSql('ALTER TABLE message_thread DROP user_category_id');

        $this->addSql('DROP INDEX idx_message_rule_category');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_message_rule_category');
        $this->addSql('ALTER TABLE message DROP rule_category_id');

        $this->addSql('DROP TABLE inbox_category');
    }
}
