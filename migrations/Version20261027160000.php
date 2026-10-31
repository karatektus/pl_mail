<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * JMAP as an incoming protocol (#27): where a remote JMAP server's ids live.
 *
 * message.jmap_id is the Email id, which survives moves the way a Gmail id
 * does, so it is guarded per account like gmail_id and graph_id are — the
 * batch handler deduplicates in PHP first, and this is what holds when two
 * batches overlap. Provider id first, for the same reason as theirs: the
 * index then also serves "which of these ids do we have" for one account.
 *
 * label_binding.jmap_mailbox_id is the Mailbox id a label is bound to on that
 * server, indexed like gmail_label_id for the lookup a sync makes when it
 * meets a mailbox id on an Email.
 *
 * Both nullable and both empty on every existing row: no account spoke JMAP
 * before this, and the protocol column needs no change — 'jmap' fits its
 * length and nothing constrains its values in SQL.
 */
final class Version20261027160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store remote JMAP ids: message.jmap_id and label_binding.jmap_mailbox_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD jmap_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_jmap_id_account ON message (jmap_id, account_id)');
        $this->addSql('ALTER TABLE label_binding ADD jmap_mailbox_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_label_binding_jmap_mailbox_id ON label_binding (jmap_mailbox_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_label_binding_jmap_mailbox_id');
        $this->addSql('ALTER TABLE label_binding DROP jmap_mailbox_id');
        $this->addSql('DROP INDEX uniq_message_jmap_id_account');
        $this->addSql('ALTER TABLE message DROP jmap_id');
    }
}
