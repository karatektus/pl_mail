<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let an administrator say how long the model host holds each model.
 *
 * Ollama keeps a model resident for a while after a request and then evicts it,
 * and how long is OLLAMA_KEEP_ALIVE — an environment variable on the model
 * host, which is a machine plMail cannot reach. The API takes the same setting
 * per request and a per-request value wins, so these two columns are the only
 * lever an operator has from inside the application. It is the lever that
 * matters: the writing model is around eighteen gigabytes, and the difference
 * between resident and not is the whole of the wait on the first "Help me
 * write" after an idle spell.
 *
 * TWO COLUMNS, NOT ONE
 * ────────────────────
 * The two models are nothing alike. The embedding model is a few hundred
 * megabytes and is asked for on the interactive path constantly — every search
 * by meaning, every message the backfill walks — so a cold load for it is the
 * worst bargain available and pinning it costs a rounding error. The writing
 * model is fifty times the size and asked for occasionally, which is the
 * opposite trade. One setting would force whichever answer is wrong for one of
 * them.
 *
 * NULLABLE, BECAUSE "SAY NOTHING" IS A REAL ANSWER
 * ────────────────────────────────────────────────
 * NULL means send no keep_alive field at all, so the host's own
 * OLLAMA_KEEP_ALIVE stands. An operator who has set that up deliberately must
 * be able to keep it, and plMail cannot read it to reproduce it — so the only
 * honest way to defer is to send nothing. It is selectable from the form by
 * emptying the box, and it is not the same state as an unconfigured install.
 *
 * BOTH DEFAULTS REACH THE EXISTING ROW, AND NEITHER CHANGES ANYTHING
 * ──────────────────────────────────────────────────────────────────
 * '5m' is Ollama's own default, so a host that was being sent no keep_alive at
 * all — which is every host, before these columns — was already doing exactly
 * this. Backfilling it is therefore a no-op in behaviour and a gain in
 * legibility: the form says what is happening instead of showing an empty box
 * the reader would have to know Ollama to interpret.
 *
 * That is only true because both defaults are the neutral one. An earlier draft
 * shipped '-1' for the search model, and '-1' is not a no-op — it means that
 * model stops being evicted and holds its memory for good, which is usually the
 * right setting and is not a thing to decide on somebody's behalf while they
 * are upgrading. The recommendation lives in the field's help text and the
 * handbook, where it costs one keystroke to accept and nothing to decline.
 *
 * varchar(16), because the longest legal value is a handful of characters and a
 * column that cannot hold an essay is one fewer thing to validate twice. The
 * grammar itself lives in App\Domain\Ai\KeepAlive, which both the form
 * constraint and the request body read, so they cannot come to disagree.
 */
final class Version20260903150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-model keep-alive settings to ai_settings';
    }

    public function up(Schema $schema): void
    {
        // ADD COLUMN with a DEFAULT writes that value into every existing row
        // as well as governing new ones, which is wanted here and is only safe
        // because '5m' is what the host was already doing. See above.
        $this->addSql('ALTER TABLE ai_settings ADD chat_keep_alive VARCHAR(16) DEFAULT \'5m\'');
        $this->addSql('ALTER TABLE ai_settings ADD embedding_keep_alive VARCHAR(16) DEFAULT \'5m\'');
    }

    public function down(Schema $schema): void
    {
        // Nothing to preserve. Dropping these puts every call back to sending
        // no keep_alive field, which is what the code did before them and what
        // the host's own default is for.
        $this->addSql('ALTER TABLE ai_settings DROP chat_keep_alive');
        $this->addSql('ALTER TABLE ai_settings DROP embedding_keep_alive');
    }
}
