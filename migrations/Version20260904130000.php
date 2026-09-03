<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The two settings that decide whether search by meaning works at all.
 *
 * WHY EXISTING INSTALLATIONS KEEP 0.55 AND DO NOT GET THE NEW DEFAULT
 * ───────────────────────────────────────────────────────────────────
 * The column default is 0.42, which is what qwen3-embedding:0.6b was measured
 * to want with its instruction. Backfilling every existing row to 0.42 would
 * apply that number to whatever model is actually configured — and cosine
 * similarity is not comparable between models, so on nomic-embed-text or
 * all-minilm it is a number from the wrong scale. On an installation running
 * all-minilm (measured best at 0.20) it would be roughly twice as tight as it
 * should be and the meaning pass would quietly stop finding anything.
 *
 * So the existing row keeps the threshold it has been running on. 0.55 is not
 * good — it is above every measured value in EmbeddingPreset — but it is the
 * behaviour the operator currently has, and a migration is the wrong place to
 * change what somebody's search returns. The admin panel now shows the number,
 * says what the configured model was measured to want, and fills it in on one
 * click. That is a decision somebody makes with the page in front of them.
 *
 * A FRESH INSTALLATION gets 0.42 and no model, which is coherent: it will be
 * configured from the panel, where choosing the default preset sets both halves
 * together.
 *
 * The instruction column is left NULL everywhere for the same reason. NULL
 * means "send the query as it is", which is exactly what every installation has
 * been doing, so nothing changes behaviour until somebody chooses.
 */
final class Version20260904130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-model query instruction and similarity threshold for search by meaning.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD search_query_instruction TEXT DEFAULT NULL');

        // NOT NULL with a default, so the column is never a second way of
        // saying "unset" — the threshold always has a value and the code never
        // has to decide what a missing one means. The three-step add/backfill/
        // set-not-null is because DEFAULT alone does not touch existing rows in
        // the way this needs: the existing row must keep 0.55, not take 0.42.
        $this->addSql('ALTER TABLE ai_settings ADD semantic_min_similarity DOUBLE PRECISION DEFAULT 0.42 NOT NULL');
        $this->addSql('UPDATE ai_settings SET semantic_min_similarity = 0.55');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP search_query_instruction');
        $this->addSql('ALTER TABLE ai_settings DROP semantic_min_similarity');
    }
}
