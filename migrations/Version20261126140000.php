<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An account in several account groups (#783).
 *
 * The unified inboxes of #265 became account groups: a group is still an inbox
 * row in the sidebar, and a workspace now shows one or more groups. An account
 * may be in any number of them, so "one row per account" becomes "one row per
 * account and group" — the change #265's migration said this would be.
 *
 * No data moves. Every existing row is one account in one group, which is
 * still true; an account with no row is still in the first group.
 */
final class Version20261126140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lets an account be in several unified inboxes (account groups)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_unified_inbox_account_account');
        $this->addSql('CREATE UNIQUE INDEX uniq_unified_inbox_account_pair ON unified_inbox_account (inbox_id, account_id)');
        $this->addSql('CREATE INDEX idx_unified_inbox_account_account ON unified_inbox_account (account_id)');
    }

    public function down(Schema $schema): void
    {
        // Back to one group per account: the first row of each account stays.
        $this->addSql('DELETE FROM unified_inbox_account a USING unified_inbox_account b WHERE a.account_id = b.account_id AND a.id > b.id');
        $this->addSql('DROP INDEX idx_unified_inbox_account_account');
        $this->addSql('DROP INDEX uniq_unified_inbox_account_pair');
        $this->addSql('CREATE UNIQUE INDEX uniq_unified_inbox_account_account ON unified_inbox_account (account_id)');
    }
}
