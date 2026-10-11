<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether the server lets a folder be opened. See
 * App\Entity\Mail\Mailbox::$isSelectable.
 *
 * One boolean, true on every existing row, which is wrong for the handful of
 * placeholders already stored — Gmail's "[Gmail]" among them — and is left
 * wrong on purpose: only the server knows which they are, and the next folder
 * sync of each account asks it and writes the answer. Until then such a folder
 * is offered a sync switch it should not have, exactly as it was before this
 * column existed, and MailboxSyncer has kept it out of the poll since v0.3.3
 * either way.
 */
final class Version20261010200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox.is_selectable for folders the server will not open';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD is_selectable BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP is_selectable');
    }
}
