<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message.rule_builtin_category: the built-in tab a filter's "File in
 * category" action filed a message under (Message::$ruleBuiltinCategory), and
 * message.rule_builtin_rule_id, the filter that did (Message::$ruleBuiltinRule).
 *
 * Nullable columns with no default, so adding them rewrites nothing on an
 * existing install: every message starts unfiled, which is what it was. The
 * index is partial: only filed mail is ever looked up by its filter.
 */
final class Version20261126180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.rule_builtin_category, the built-in tab a filter filed a message under';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD rule_builtin_category VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD rule_builtin_rule_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FC4918EEE FOREIGN KEY (rule_builtin_rule_id) REFERENCES mail_rule (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_message_rule_builtin_rule ON message (rule_builtin_rule_id) WHERE rule_builtin_rule_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307FC4918EEE');
        $this->addSql('DROP INDEX idx_message_rule_builtin_rule');
        $this->addSql('ALTER TABLE message DROP rule_builtin_rule_id');
        $this->addSql('ALTER TABLE message DROP rule_builtin_category');
    }
}
