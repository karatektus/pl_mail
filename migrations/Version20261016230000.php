<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill: mirror the emails already in the Contacts address book into the
 * `contact` table the composer's recipient fields search.
 *
 * New emails are mirrored as they are saved (AddressBookMirrorListener); this
 * covers the ones imported before that existed. DISTINCT ON picks one row per
 * (user, address), because an INSERT ... ON CONFLICT cannot touch the same
 * target row twice in one statement. Safe to re-run: existing rows only get
 * their display name filled in.
 */
final class Version20261016230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mirror address-book emails into contact for composer autocomplete';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO contact (usr_id, email, display_name, frequency, first_seen_at, last_seen_at, created_at, updated_at, is_correspondent)
            SELECT src.usr_id, src.email, src.display_name, 0, NOW(), NOW(), NOW(), NOW(), FALSE
            FROM (
                SELECT DISTINCT ON (c.usr_id, LOWER(e.email))
                       c.usr_id,
                       LOWER(e.email) AS email,
                       NULLIF(BTRIM(c.display_name), '') AS display_name
                FROM contacts_contact_email e
                JOIN contacts_contact c ON c.id = e.contact_id
                WHERE c.trashed_at IS NULL
                  AND e.email LIKE '%_@_%._%'
                ORDER BY c.usr_id, LOWER(e.email), e.is_primary DESC, c.id
            ) src
            ON CONFLICT (usr_id, email) DO UPDATE
                SET display_name = COALESCE(contact.display_name, EXCLUDED.display_name)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Mirrored rows are indistinguishable from harvested ones by design; nothing to undo.
    }
}
