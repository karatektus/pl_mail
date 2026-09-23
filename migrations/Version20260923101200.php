<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A retry counter for the one message in a folder that will not import.
 *
 * A message that failed to build used to be skipped past for good: the
 * high-water mark moved over it and it was never fetched again. It is now held
 * inside the next sync's range, and these two columns cap how often, so a
 * message that always fails cannot stop the folder importing newer mail.
 */
final class Version20260923101200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox.failed_uid and mailbox.failed_uid_attempts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD failed_uid INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD failed_uid_attempts INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP failed_uid');
        $this->addSql('ALTER TABLE mailbox DROP failed_uid_attempts');
    }
}
