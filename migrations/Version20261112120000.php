<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An account's one on/off switch becomes three — mail, calendar, contacts —
 * each switched on its own (#575).
 *
 * `is_active` is RENAMED to `mail_enabled` rather than dropped and replaced,
 * because mail is all it ever governed: every reader of it was a mail path,
 * and the calendar engine never consulted it. A switched-off account keeps
 * exactly the meaning it had for mail.
 *
 * The two new columns are filled from what each service actually did under
 * the old switch, so nothing starts or stops syncing on upgrade:
 *
 *   - contacts_enabled copies is_active. AddressBook::isActive() read the
 *     account's switch, so a paused account's address books were paused too.
 *   - calendar_enabled is true everywhere. Calendars synced whatever the switch
 *     said, and an account that was "off" still had its calendars updating.
 *
 * Added with a default of true so the ALTER fills existing rows in one step,
 * then corrected for contacts — the column is NOT NULL from the start.
 */
final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split an account\'s on/off switch into mail, calendar and contacts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account RENAME COLUMN is_active TO mail_enabled');
        $this->addSql('ALTER TABLE account ALTER mail_enabled SET DEFAULT true');
        $this->addSql('ALTER TABLE account ADD calendar_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE account ADD contacts_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE account SET contacts_enabled = mail_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP calendar_enabled');
        $this->addSql('ALTER TABLE account DROP contacts_enabled');
        $this->addSql('ALTER TABLE account ALTER mail_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE account RENAME COLUMN mail_enabled TO is_active');
    }
}
