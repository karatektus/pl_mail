<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The fifth AI feature's two switches: contact help.
 *
 * The same two-columns-one-fact shape as Version20260902100000, for the same
 * reasons: AiPermissions ANDs them on every read, and the user column is
 * hydrated into a typed property, so both are `DEFAULT false NOT NULL` and go
 * in together. False on both sides changes nothing until an administrator
 * switches the feature on.
 *
 * Nothing for ai_call_metric: `feature` is a plain VARCHAR(32), and
 * 'contact_help' fits.
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the fifth AI feature\'s switches: ai_settings.contact_help_enabled and "user".ai_contact_help_off.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD contact_help_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD ai_contact_help_off BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP contact_help_enabled');
        $this->addSql('ALTER TABLE "user" DROP ai_contact_help_off');
    }
}
