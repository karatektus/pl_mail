<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sending becomes a switch of its own beside receiving (#628).
 *
 * Filled from what each account could actually do under the one mail switch:
 * on wherever mail was on, except a password account with no SMTP host that
 * does not speak JMAP. That account was offered in the From list and every
 * send from it failed in the DSN factory; it starts out as what it always
 * was in practice, an account that receives and does not send. A JMAP account
 * keeps sending without an SMTP host, because its server takes submissions.
 *
 * Added with a default of true so the ALTER fills existing rows in one step,
 * then corrected — the column is NOT NULL from the start.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give an account a send switch of its own beside receiving';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD send_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE account
               SET send_enabled = mail_enabled
                   AND (auth_type <> 'password' OR incoming_protocol = 'jmap' OR COALESCE(TRIM(smtp_host), '') <> '')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP send_enabled');
    }
}
