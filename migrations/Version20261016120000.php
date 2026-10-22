<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * mailbox.uid_validity becomes a BIGINT (#171).
 *
 * RFC 3501 defines UIDVALIDITY as an unsigned 32-bit number, up to 4294967295,
 * and the column was a signed INT, which stops at 2147483647. Servers that
 * derive the value from a timestamp or a hash go past that routinely — Dovecot
 * does — and for such a folder the flush that records it was refused with
 * "value … is out of range for type integer", so the folder never synced.
 *
 * Widening keeps every stored value as it is. Postgres rewrites the table to do
 * it, which is harmless here: mailbox holds one row per folder, not per message.
 *
 * down() narrows it again and forgets any value an INT cannot hold rather than
 * failing on it. A null UIDVALIDITY is the state of a folder never listed, and
 * the next sweep adopts what the server says instead of treating it as a
 * rebuild (VanishedMessageReconciler::isRebuilt()) — which is the most an older
 * release could have done with such a folder anyway.
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen mailbox.uid_validity to BIGINT, so a UIDVALIDITY above 2147483647 can be stored';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ALTER COLUMN uid_validity TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mailbox ALTER COLUMN uid_validity TYPE INT
            USING CASE WHEN uid_validity > 2147483647 THEN NULL ELSE uid_validity END
            SQL);
    }
}
