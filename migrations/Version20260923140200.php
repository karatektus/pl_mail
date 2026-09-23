<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An index for Email/query's default order: an account's mail, newest first.
 *
 * received_at had no index at all, so every Email/query page sorted the whole
 * account. The column order and directions are spelled to match the ORDER BY
 * EmailQueryRunner emits — `received_at DESC NULLS LAST, id DESC` — because a
 * plain ascending index read backwards gives DESC NULLS FIRST, which Postgres
 * cannot use for that sort. The entity declares the same columns so the schema
 * comparator knows the index is wanted; it does not compare directions.
 */
final class Version20260923140200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index message (account_id, received_at DESC NULLS LAST, id DESC) for Email/query.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_message_account_received_at ON message (account_id, received_at DESC NULLS LAST, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_account_received_at');
    }
}
