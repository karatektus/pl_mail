<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Count the times an account has had to list its whole mailbox again.
 *
 * Both providers hand out a sync cursor that expires, and the answer to either
 * is a full re-enumeration. Once in a while that is housekeeping. Repeatedly it
 * means the account is not being synced inside the window the provider keeps,
 * or a worker is dying mid-batch — and the symptom is a mailbox that is
 * intermittently behind, which looks like nothing at all from outside.
 *
 * Nothing was recorded, so nobody could tell the two apart.
 */
final class Version20260826020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.full_resync_count and account.last_full_resync_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD full_resync_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE account ADD last_full_resync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP full_resync_count');
        $this->addSql('ALTER TABLE account DROP last_full_resync_at');
    }
}
