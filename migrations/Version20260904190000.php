<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere for the browser to say something broke.
 *
 * A row is a DISTINCT FAULT rather than an occurrence, which is why
 * `fingerprint` is unique: a broken line in a controller fires on every page
 * load for every user, and a table that kept them all would hold four hundred
 * identical rows by teatime. The same fault increments `occurrences` and moves
 * `last_seen_at` instead.
 *
 * That uniqueness is also the concurrency story. Two tabs reporting the same
 * new fault at the same instant both try to insert; the constraint decides
 * which one wins and the loser becomes an increment, so the count is right
 * either way without a lock.
 *
 * Separate from `log_entry` deliberately, and not only because the shapes
 * differ. A server error is one event in one request; this is a population with
 * a count and a lifetime, and mixing them puts a list nobody can read past in
 * front of the log somebody came to read.
 */
final class Version20260904190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Browser errors and CSP violations, grouped by fault rather than logged per occurrence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE client_error (
                id SERIAL PRIMARY KEY,
                fingerprint VARCHAR(40) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                message TEXT NOT NULL,
                source VARCHAR(500) DEFAULT NULL,
                line INT DEFAULT NULL,
                column_number INT DEFAULT NULL,
                stack TEXT DEFAULT NULL,
                url VARCHAR(500) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                occurrences INT DEFAULT 1 NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_client_error_fingerprint ON client_error (fingerprint)');
        $this->addSql('CREATE INDEX idx_client_error_last_seen ON client_error (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE client_error');
    }
}
