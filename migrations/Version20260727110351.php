<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727110351 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one message row per remote id per account (Gmail/Graph).';
    }

    public function up(Schema $schema): void
    {
        // Subsumed by uniq_message_gmail_id_account, which leads on gmail_id.
        $this->addSql('DROP INDEX idx_message_gmail_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_gmail_id_account ON message (gmail_id, account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_graph_id_account ON message (graph_id, account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_message_gmail_id_account');
        $this->addSql('DROP INDEX uniq_message_graph_id_account');
        $this->addSql('CREATE INDEX idx_message_gmail_id ON message (gmail_id)');
    }
}
