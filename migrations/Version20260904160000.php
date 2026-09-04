<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to keep "this message is in the wrong tab".
 *
 * Written only when somebody presses a button, so an installation nobody
 * reports anything on carries an empty table and pays nothing for it.
 *
 * No foreign key to `message`, deliberately: a report outlives the mail it is
 * about. Somebody deletes the message; the pattern it was evidence of does not
 * go away, and a cascade would quietly delete the record of a problem along
 * with the example of it. The user reference DOES cascade — a deleted account
 * takes its own reports with it, which is what deleting an account means.
 */
final class Version20260904160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reports of miscategorised mail, for deciding whether a rule or prompt should change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE category_report (
                id SERIAL PRIMARY KEY,
                usr_id INT NOT NULL,
                message_id INT NOT NULL,
                filed VARCHAR(16) NOT NULL,
                should_be VARCHAR(16) NOT NULL,
                gmail VARCHAR(16) DEFAULT NULL,
                rules VARCHAR(16) DEFAULT NULL,
                rules_signal VARCHAR(64) DEFAULT NULL,
                model VARCHAR(16) DEFAULT NULL,
                source VARCHAR(16) NOT NULL,
                bulk_headers VARCHAR(255) NOT NULL,
                from_address VARCHAR(320) NOT NULL,
                from_name VARCHAR(255) NOT NULL,
                subject TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql('CREATE INDEX idx_category_report_created ON category_report (created_at)');
        $this->addSql('CREATE INDEX idx_category_report_usr ON category_report (usr_id)');
        $this->addSql(
            'ALTER TABLE category_report ADD CONSTRAINT fk_category_report_usr '
            . 'FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE category_report');
    }
}
