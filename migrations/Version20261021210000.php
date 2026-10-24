<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contact photos move from var/contacts to var/uploads/contacts (see
 * ContactPhotoStore), and the photos stored before that are gone.
 *
 * var/contacts was in neither of the volumes the web and worker containers
 * share, so a photo a sync brought in was written inside the worker and never
 * seen by the web container, and every photo was lost when its container was
 * recreated — which the update that brings this migration does. No file is
 * left to move, so the rows that point at one are reset instead:
 *
 *   - every contact forgets its photo path;
 *   - a synced contact also forgets its photo version and etag, and its
 *     address book its sync position, so the next sync reads the whole book
 *     and fetches every photo again, into the new place.
 *
 * Plain SQL, deliberately: through the ORM, clearing a synced contact's photo
 * would be an edit, and the push would delete the photo on the server too.
 * Contacts with an edit waiting to be sent are left alone; the sync does not
 * overwrite them, and their photo comes back with the next change after.
 *
 * A photo uploaded to one of plMail's own contacts is lost and has to be
 * uploaded again. The contacts feature reached only installs following the
 * milestone image, on the day this was written.
 */
final class Version20261021210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contacts: forget photos stored where the web container could not see them, and read synced books again';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE address_book SET sync_token = NULL
             WHERE id IN (SELECT DISTINCT address_book_id FROM contacts_contact
                           WHERE address_book_id IS NOT NULL AND photo_path IS NOT NULL AND sync_state = 'clean')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE contacts_contact SET photo_path = NULL, photo_version = NULL, photo_dirty = false, etag = NULL
             WHERE address_book_id IS NOT NULL AND photo_path IS NOT NULL AND sync_state = 'clean'
            SQL);

        $this->addSql('UPDATE contacts_contact SET photo_path = NULL WHERE address_book_id IS NULL AND photo_path IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the files were already gone.
    }
}
