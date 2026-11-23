<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Translating a message (#713): the feature's switches, its prompt, the reader's
 * language, and where translations are kept.
 *
 * The two switches have the shape of Version20261022120000's, for its reasons:
 * both `DEFAULT false NOT NULL`, and false on both sides changes nothing until
 * an administrator switches the feature on. The prompt column is NULL like the
 * other seven: null is "the shipped text". The reader's language is NULL too:
 * null is "the interface language".
 *
 * message_translation is raw SQL like thread_summary and message_embedding, and
 * hidden from the ORM's schema diff the same way (doctrine.yaml schema_filter).
 * One row per message and language: a reader who changes their language keeps
 * the first translation for when they change back. source_hash, model and
 * prompt_hash decide whether a stored row still answers, as for the summary;
 * VARCHAR and not CHAR for the blank padding Version20260902100100 explains.
 * ON DELETE CASCADE: a translation has no meaning without its message, and
 * mail is deleted in bulk.
 *
 * Nothing for ai_call_metric: `feature` is a plain VARCHAR(32), and
 * 'message_translation' fits.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Translating a message: switches, prompt, the reader\'s language and the message_translation table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD translation_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE ai_settings ADD prompt_translation TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD ai_translation_off BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD ai_translate_into VARCHAR(16) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE message_translation (
                message_id INT NOT NULL,
                target_language VARCHAR(16) NOT NULL,
                translation TEXT NOT NULL,
                source_hash VARCHAR(64) NOT NULL,
                model VARCHAR(128) NOT NULL,
                prompt_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(message_id, target_language)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE message_translation
                ADD CONSTRAINT fk_message_translation_message
                FOREIGN KEY (message_id) REFERENCES message (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_translation');
        $this->addSql('ALTER TABLE "user" DROP ai_translate_into');
        $this->addSql('ALTER TABLE "user" DROP ai_translation_off');
        $this->addSql('ALTER TABLE ai_settings DROP prompt_translation');
        $this->addSql('ALTER TABLE ai_settings DROP translation_enabled');
    }
}
