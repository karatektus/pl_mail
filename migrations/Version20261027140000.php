<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Filters file regardless of the sender check, and a conversation's tab
 * follows its newest RECEIVED message (#405).
 *
 * message_thread.category_decided_at: when the received message that placed
 * the conversation happened. See MessageThread::$categoryDecidedAt.
 *
 * Every conversation nobody pinned is then resolved again by the new rule, in
 * one statement — the same one MessageThreadRepository::recomputeCategoriesFor*
 * run, over every account. Without it the column would be null everywhere, and
 * every conversation your reply had taken out of a filter's tab would stay out
 * until its next message. It does not refile anything: a message no filter
 * filed (because its sender was not verified) is filed by running the filter's
 * "Apply to existing mail" again.
 *
 * mail_rule.run_unfiled goes: it counted the messages a run could not file for
 * want of a verified sender, and nothing is refused for that any more.
 */
final class Version20261027140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A conversation follows its newest received message: message_thread.category_decided_at; drop mail_rule.run_unfiled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread ADD category_decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN message_thread.category_decided_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE mail_rule DROP run_unfiled');

        $this->addSql(<<<'SQL'
            UPDATE message_thread t
            SET category = sub.category,
                user_category_id = sub.rule_category_id,
                category_decided_at = sub.decided_at
            FROM (
                SELECT DISTINCT ON (m.thread_id) m.thread_id, m.category, m.rule_category_id,
                       CASE WHEN own.filed THEN NULL ELSE COALESCE(m.received_at, m.sent_at, m.created_at) END AS decided_at
                FROM message m
                CROSS JOIN LATERAL (
                    SELECT EXISTS (SELECT 1 FROM message_label ml JOIN label l ON l.id = ml.label_id
                                   WHERE ml.message_id = m.id AND l.role IN ('sent', 'drafts')) AS filed
                ) own
                WHERE m.category IS NOT NULL
                  AND m.thread_id IS NOT NULL
                  AND (m.category_held_at IS NULL OR m.category_released_at IS NOT NULL)
                ORDER BY m.thread_id, own.filed ASC, COALESCE(m.received_at, m.sent_at, m.created_at) DESC
            ) sub
            WHERE t.id = sub.thread_id
              AND t.category_pinned_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_rule ADD run_unfiled INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE message_thread DROP category_decided_at');
    }
}
