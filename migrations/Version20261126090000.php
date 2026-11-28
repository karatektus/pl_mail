<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * email_alias.extra_names: more names an address sends under, beside its own
 * display name (EmailAlias::$extraNames).
 *
 * A JSON list defaulting to empty, so every existing alias keeps exactly the
 * one name it had, and the counter its keys are drawn from
 * (EmailAlias::$extraNameSeq).
 */
final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_alias.extra_names, the other names an address sends under';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias ADD extra_names JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE email_alias ADD extra_name_seq INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP extra_name_seq');
        $this->addSql('ALTER TABLE email_alias DROP extra_names');
    }
}
