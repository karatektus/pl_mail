<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Gmail Pub/Sub verification token, alongside the mail OAuth credentials.
 *
 * The topic name lives in the settings jsonb, but the token is a bearer
 * credential in all but name — Google echoes it back on every push and the
 * webhook trusts the notification because of it — so it gets the same
 * encrypted_string treatment as a client secret, in its own TEXT column.
 *
 * GmailPushSettings reads a stored value first and falls back to
 * GMAIL_PUBSUB_TOPIC / GMAIL_PUBSUB_VERIFICATION_TOKEN, so an installation
 * configured through .env keeps pushing without change.
 */
final class Version20260730020832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add push_verification_token to mail_provider_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_provider_config ADD push_verification_token TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_provider_config DROP push_verification_token');
    }
}
