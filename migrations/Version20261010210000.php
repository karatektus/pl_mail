<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A separate password for sending, for the accounts whose server wants one.
 * See App\Entity\Mail\Account::$smtpPassword.
 *
 * Nullable and left null on every existing row, which is the meaning it
 * already has: one password, used for both. Nothing is backfilled because
 * nothing existing has two.
 *
 * TEXT like `password` beside it — the value is encrypted before it is
 * stored, and the ciphertext is longer than what was typed.
 */
final class Version20261010210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.smtp_password for servers that want a different password to send';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD smtp_password TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP smtp_password');
    }
}
