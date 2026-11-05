<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every account a custom label is on gets a binding (#221).
 *
 * LabelBinding is meant to be one row per account a label has been used on,
 * but putting a plMail tag on IMAP or POP3 mail wrote none, so "a tag on this
 * account" and "never seen here" could not be told apart. LabelKinds now reads
 * the bindings to decide whether a label that is a folder on one account is
 * also a tag on another, and new tags get their row from
 * LabelResolver::usedAsTagOn(). This writes the missing rows for mail that was
 * tagged before.
 *
 * Empty rows: no mailbox, no provider id, which is exactly "a tag here". One
 * per label and account; ON CONFLICT keeps every row that is already there,
 * folders included. System labels are left out, as they are in usedAsTagOn().
 *
 * A binding is also a JMAP Mailbox of plMail's own server, so each new row is
 * written to jmap_change_log as a created Mailbox, which is what
 * LabelResolver::binding() records for a row it makes. The apps then fetch the
 * new mailboxes on their next Mailbox/changes, and the tags these rows stand
 * for show up in Email mailboxIds, where EmailMapper left them out before.
 *
 * down() takes nothing away: the new rows cannot be told from the empty rows
 * that were there before, and an empty row is harmless.
 */
final class Version20261105170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a label_binding row for every account whose mail carries a custom label it has none for';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            WITH added AS (
                INSERT INTO label_binding (label_id, account_id, created_at, updated_at)
                SELECT DISTINCT ml.label_id, m.account_id, NOW(), NOW()
                FROM message_label ml
                JOIN message m ON m.id = ml.message_id
                JOIN label l ON l.id = ml.label_id
                WHERE l.role IS NULL
                ON CONFLICT (label_id, account_id) DO NOTHING
                RETURNING id, account_id
            )
            INSERT INTO jmap_change_log (account_id, object_type, entity_id, change_type, created_at, updated_at)
            SELECT account_id, 'Mailbox', id::text, 'created', NOW(), NOW()
            FROM added
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
