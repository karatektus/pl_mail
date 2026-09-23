<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember messages the server confirmed are genuinely in two folders.
 *
 * The relocated-duplicates repair probed every such copy over IMAP on every
 * sync, found them all still there, and did the same again next time. Null on
 * every existing row, which is what makes each one be checked once.
 */
final class Version20260923101300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.copies_confirmed_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD copies_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP copies_confirmed_at');
    }
}
