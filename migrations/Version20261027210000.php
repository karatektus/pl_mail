<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mailbox::$mirrorsOtherFolders: whether a folder is the server's view of mail
 * it keeps elsewhere — All Mail beside an Archive, Starred (#414).
 *
 * False everywhere to start. Nothing here can tell which folders those are; the
 * server's LIST attributes can, so the next folder sync sets it, switches those
 * folders' sync off, and starts erasing the copies they stored.
 */
final class Version20261027210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox.mirrors_other_folders for All Mail and Starred folders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD mirrors_other_folders BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP mirrors_other_folders');
    }
}
