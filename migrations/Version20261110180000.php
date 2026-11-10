<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets the next folder sync recognise the switched-off mirror folders again,
 * so it hides their labels and shows plMail's own All Mail (#548).
 *
 * Hiding the label happens when a folder is first recognised as a mirror, and
 * on existing installs that moment has passed. Which kind of mirror a folder is
 * (All Mail or Starred) is in the server's LIST attributes and not stored, so
 * this cannot do the work itself; it puts the folders back to "not yet
 * recognised" and the sync does it, the way Version20261027210000 left the
 * first recognition to the sync.
 *
 * Only folders whose sync is off. One somebody switched back on has been
 * recognised and decided about, and recognising it again would switch it off.
 */
final class Version20261110180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Have the next folder sync recognise switched-off mirror folders again, to hide their labels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE mailbox SET mirrors_other_folders = false WHERE mirrors_other_folders = true AND is_sync_enabled = false');
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: the next folder sync marks the same folders again.
    }
}
