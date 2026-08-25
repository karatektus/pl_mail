<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to put what the IMAP server told the user.
 *
 * RFC 3501 says the text of an `[ALERT]` response MUST be shown to the user.
 * plMail read those lines off the socket and dropped them, so a mailbox over
 * quota — the single most common one, and common in particular on the German
 * consumer ISPs plMail's preset list is full of — simply stopped receiving mail
 * with nothing anywhere saying why.
 */
final class Version20260826000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.imap_server_alert, the last [ALERT] the server sent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD imap_server_alert TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP imap_server_alert');
    }
}
