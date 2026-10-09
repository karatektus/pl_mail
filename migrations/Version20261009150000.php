<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email templates and the folders they are sorted into. See
 * App\Entity\Template\MailTemplate and TemplateFolder.
 *
 * Two new, empty tables. The foreign keys are the part worth reading: a folder
 * goes with its account and with its parent (CASCADE), while a template only
 * loses its place (SET NULL) — removing a mail account or a folder moves the
 * templates in it to the top level and deletes none of them.
 */
final class Version20261009150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail_template and template_folder for reusable messages in the composer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE template_folder (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                account_id INT DEFAULT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_template_folder_usr ON template_folder (usr_id)');
        $this->addSql('CREATE INDEX IDX_template_folder_account ON template_folder (account_id)');
        $this->addSql('CREATE INDEX IDX_template_folder_parent ON template_folder (parent_id)');
        $this->addSql('ALTER TABLE template_folder ADD CONSTRAINT FK_template_folder_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE template_folder ADD CONSTRAINT FK_template_folder_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE template_folder ADD CONSTRAINT FK_template_folder_parent FOREIGN KEY (parent_id) REFERENCES template_folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE mail_template (
                id SERIAL NOT NULL,
                usr_id INT NOT NULL,
                account_id INT DEFAULT NULL,
                folder_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                subject VARCHAR(255) DEFAULT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_mail_template_usr ON mail_template (usr_id)');
        $this->addSql('CREATE INDEX IDX_mail_template_account ON mail_template (account_id)');
        $this->addSql('CREATE INDEX IDX_mail_template_folder ON mail_template (folder_id)');
        $this->addSql('ALTER TABLE mail_template ADD CONSTRAINT FK_mail_template_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE mail_template ADD CONSTRAINT FK_mail_template_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE mail_template ADD CONSTRAINT FK_mail_template_folder FOREIGN KEY (folder_id) REFERENCES template_folder (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mail_template');
        $this->addSql('DROP TABLE template_folder');
    }
}
