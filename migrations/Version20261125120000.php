<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message.rule_builtin_category: the built-in tab a filter's "File in
 * category" action filed a message under (Message::$ruleBuiltinCategory).
 *
 * A nullable column with no default, so adding it rewrites nothing on an
 * existing install: every message starts unfiled, which is what it was.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.rule_builtin_category, the built-in tab a filter filed a message under';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD rule_builtin_category VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP rule_builtin_category');
    }
}
