<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How many messages the last "Apply to existing mail" matched but could not
 * file into a tab, because the receiving server did not vouch for the sender.
 * See MailRule::$runUnfiled.
 *
 * NOT NULL with a default, in one statement: PostgreSQL fills existing rows
 * with 0, which is the truth for every run made before it was counted — it
 * says nothing rather than something wrong.
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Count the messages a rule run could not file into a tab: mail_rule.run_unfiled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_rule ADD run_unfiled INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_rule DROP run_unfiled');
    }
}
