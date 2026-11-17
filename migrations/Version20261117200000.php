<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A separate username for sending (#660), beside the separate password from
 * #42. See App\Entity\Mail\Account::$smtpUsername.
 *
 * Nullable and null on every existing row: that is the meaning it already
 * has, one username for both directions. Plain VARCHAR like `username` — a
 * username is not encrypted.
 */
final class Version20261117200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.smtp_username for relays that sign in as someone else';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD smtp_username VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP smtp_username');
    }
}
