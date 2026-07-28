<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728195115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade account/mailbox/thread/message deletes in the database instead of the ORM';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox DROP CONSTRAINT fk_a69fe20b9b6b5fba');
        $this->addSql('ALTER TABLE mailbox ADD CONSTRAINT FK_A69FE20B9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT fk_b6bd307f9b6b5fba');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT fk_b6bd307f66ec35cc');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT fk_b6bd307fe2904019');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F66EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FE2904019 FOREIGN KEY (thread_id) REFERENCES message_thread (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_part DROP CONSTRAINT fk_e09aeb1f537a1329');
        $this->addSql('ALTER TABLE message_part ADD CONSTRAINT FK_E09AEB1F537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_thread DROP CONSTRAINT fk_607d18c9b6b5fba');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_607D18C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox DROP CONSTRAINT FK_A69FE20B9B6B5FBA');
        $this->addSql('ALTER TABLE mailbox ADD CONSTRAINT fk_a69fe20b9b6b5fba FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307F9B6B5FBA');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307F66EC35CC');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307FE2904019');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT fk_b6bd307f9b6b5fba FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT fk_b6bd307f66ec35cc FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT fk_b6bd307fe2904019 FOREIGN KEY (thread_id) REFERENCES message_thread (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message_part DROP CONSTRAINT FK_E09AEB1F537A1329');
        $this->addSql('ALTER TABLE message_part ADD CONSTRAINT fk_e09aeb1f537a1329 FOREIGN KEY (message_id) REFERENCES message (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message_thread DROP CONSTRAINT FK_607D18C9B6B5FBA');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT fk_607d18c9b6b5fba FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
