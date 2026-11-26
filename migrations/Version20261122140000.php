<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A fifth switch on an account: Microsoft To Do (#75).
 *
 * Off for every account there already is. None of them has been asked for
 * Tasks.ReadWrite, so on would only be a switch whose first sync fails; a
 * person turns it on in Settings → Accounts, and the consent comes with that.
 */
final class Version20261122140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds account.tasks_enabled, off for existing accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD tasks_enabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP tasks_enabled');
    }
}
