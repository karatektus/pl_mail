<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use App\Domain\Ai\EmbeddingSpace;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Separate embedding connections and isolate vector spaces without reindexing'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ai_settings ADD embedding_revision VARCHAR(64) DEFAULT NULL, ADD embedding_provider VARCHAR(32) DEFAULT 'ollama' NOT NULL, ADD embedding_shared_connection BOOLEAN DEFAULT FALSE NOT NULL, ADD embedding_base_url VARCHAR(255) DEFAULT NULL, ADD embedding_api_token TEXT DEFAULT NULL, ADD embedding_approved_space VARCHAR(80) DEFAULT NULL, ADD embedding_reindex_required BOOLEAN DEFAULT FALSE NOT NULL");
        // Preserve the existing connection and encrypted credential without decrypting it.
        $this->addSql('UPDATE ai_settings SET embedding_base_url = base_url, embedding_api_token = api_token');
        $settings = $this->connection->fetchAssociative('SELECT base_url, embedding_model FROM ai_settings ORDER BY id LIMIT 1');
        if (false !== $settings) {
            $identity = EmbeddingSpace::identity('ollama', $settings['base_url'], $settings['embedding_model']);
            $this->addSql('UPDATE message_embedding SET model = :space WHERE model = :model', ['space' => $identity, 'model' => $settings['embedding_model']]);
            $this->addSql('UPDATE ai_settings SET embedding_approved_space = :space', ['space' => $identity]);
        }
        // Previous queue deliveries have no generation token and must not restart an old run.
        $this->addSql('ALTER TABLE ai_backfill_state ADD run_id VARCHAR(32) DEFAULT NULL');
        $this->addSql("UPDATE ai_backfill_state SET status = 'paused', pause_reason = 'operator', run_id = 'migration' WHERE status IN ('running', 'paused')");
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Vector identities cannot safely be converted to model names. Restore a database backup to roll back.');
    }
}
