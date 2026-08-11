<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trigram indexes for the substring pass in search.
 *
 * Why search needs a substring pass at all is in FreeTextCompiler: the
 * tokenizer emits "help.wirhub.de" as one `host` lexeme, so "wirhub" is not a
 * prefix of anything in the tsvector and full-text search cannot reach it by
 * any query. ILIKE '%wirhub%' can, and without an index that is a sequential
 * scan over every body in the account.
 *
 * pg_trgm ships with the postgres image (available 1.6, not previously
 * installed). GIN over gin_trgm_ops lets the planner serve `%needle%` from the
 * index for needles of three characters or more, which is exactly the floor
 * FreeTextCompiler enforces before it emits a needle at all.
 *
 * The cost, stated plainly because it is not free:
 *
 *   Write amplification. Every INSERT of a message now maintains four more GIN
 *   indexes, body_text's being much the largest — a trigram index over prose is
 *   roughly the size of the prose. Sync is a bulk insert path, so this is the
 *   number that matters; GIN's pending-list (fastupdate, on by default) absorbs
 *   it in batches rather than per row.
 *
 *   Disk. Expect the trigram index on body_text to approach the size of the
 *   body_text column itself. This is the real price of the feature.
 *
 * The alternative — leaving the substring pass unindexed — was rejected because
 * a search box that is correct and slow gets used and then reported as slow,
 * whereas one that is fast and wrong gets reported as broken, which is what
 * happened.
 *
 * CONCURRENTLY is deliberately NOT used: it cannot run inside the transaction
 * migrations execute in. On an install large enough for that to matter, the
 * index build takes a write lock on `message` for its duration.
 */
final class Version20260811161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Install pg_trgm and add GIN trigram indexes for substring search on message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_subject_trgm ON message USING GIN (subject gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_body_text_trgm ON message USING GIN (body_text gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_from_name_trgm ON message USING GIN (from_name gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_from_address_trgm ON message USING GIN (from_address gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_message_from_address_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_message_from_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_message_body_text_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_message_subject_trgm');

        // The extension stays. btree_gist is installed by the calendar
        // migration and nothing here can know whether something else has since
        // come to depend on pg_trgm; dropping a shared extension on the way
        // down is how an unrelated index disappears.
    }
}
