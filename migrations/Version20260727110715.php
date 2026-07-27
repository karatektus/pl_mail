<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727110715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one message row per IMAP UID per mailbox.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_message_mailbox_imap_uid ON message (mailbox_id, imap_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_message_mailbox_imap_uid');
    }
}
