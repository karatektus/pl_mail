<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What arrived in an account since a moment, cheaply (#783).
 *
 * A workspace tab's "new" count asks for the mail that reached the workspace's
 * accounts since it was last looked at. Arrival is when the email row was made
 * (created_at), not its Date header. Without this index that question read
 * every email of the account; with it, only the ones newer than the visit.
 */
final class Version20261126160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index on message (account_id, created_at) for the workspace new counts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_message_account_created_at ON message (account_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_account_created_at');
    }
}
