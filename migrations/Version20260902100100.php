<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a thread summary is kept between one reading and the next.
 *
 * NO ENTITY, DELIBERATELY — THE message_embedding SHAPE
 * ─────────────────────────────────────────────────────
 * Nothing here ever needs an object: the writer has a thread id and a string,
 * and the reader is a primary-key lookup. An entity would mean a mapping, a
 * repository and one more Doctrine identity-map participant to keep in step for
 * a table nothing hydrates. ThreadSummaryStore reads and writes it with plain
 * DBAL, which is what EmbeddingStore says out loud and for the same reason.
 *
 * WHY THE KEY IS A CONTENT HASH AND NOT A TIMESTAMP
 * ─────────────────────────────────────────────────
 * `source_hash` is the SHA-256 of the exact transcript that was sent to the
 * model, and it is the only candidate that cannot silently miss.
 * MessageThread has no `updatedAt`, and `lastMessageAt` moves forward only —
 * MessageThreader::recordActivity() writes it and no deletion path ever moves
 * it back, so deleting the newest message in a thread leaves the key pointing
 * at a message that no longer exists. `messageCount` is recomputed on every
 * delete path, so deleting one message and receiving one leaves the number
 * identical and the conversation different. MAX(message.updated_at) cannot
 * miss but over-invalidates catastrophically: ThreadStatusUpdater writes
 * `seenAt` through the ORM, so MARKING A THREAD READ bumps every message in it
 * — and opening a thread is what marks it read, which would make every summary
 * stale on the very next open.
 *
 * The hash moves for a new message, a deleted one, a draft edited in place and
 * a row re-pointed into the thread by one of the paths that bypass
 * PostIngestPipeline — and does not move for reading, starring, snoozing or
 * labelling, none of which appear in a transcript. There is no `is_stale`
 * column and no cached copy of the count, because freshness derived at read
 * time cannot disagree with itself; see Version20260828000000, which refuses
 * two versions of one fact on the grounds that "they would disagree exactly
 * when somebody was watching".
 *
 * `model` AND `prompt_version`, BOTH READ-FILTERED
 * ────────────────────────────────────────────────
 * EmbeddingStore::alreadyStored()'s argument, transferred: an administrator who
 * swaps `chatModel` has changed what a summary IS, and a row written by the
 * previous model must stop being SHOWN rather than sit there looking current.
 * `prompt_version` is the analogue of `dimensions` — two summaries can share a
 * model name across a prompt edit and mean different things — so bumping
 * ThreadSummariser::PROMPT_VERSION makes every stored summary invisible in one
 * constant. Nothing is deleted by either: the primary key is the thread, so
 * there is at most one row per thread ever and an unusable one is replaced by
 * the upsert the moment anybody re-summarises. No pruner, no console command.
 *
 * ON DELETE CASCADE, NOT SET NULL
 * ───────────────────────────────
 * The two precedents in this schema point opposite ways and the criterion is
 * stated in both. mail_insight is SET NULL because "a deleted shipping
 * confirmation does not un-ship the parcel" — the fact outlives the mail.
 * message_embedding is CASCADE because "an embedding describes a message and
 * has no meaning without it". A summary DESCRIBES the thread; it is the second
 * case. It also has to be, because deletion of per-thread data here is done
 * purely by database cascade — there is no cleanup subscriber and no command —
 * so CASCADE is the whole of the deletion story, including MessagePurger
 * removing a thread that has been emptied.
 *
 * VARCHAR(64) AND NOT CHAR(64), WHICH IS NOT A STYLE CHOICE
 * ──────────────────────────────────────────────────────────
 * A SHA-256 hex digest is always exactly 64 characters, so CHAR(64) looks like
 * the more precise declaration. It is a trap: PostgreSQL BLANK-PADS char(n) on
 * write and hands the padding back on read, so anything that ever stored a
 * shorter value would compare unequal to what it stored — and unequal here does
 * not error, it renders as "this summary is out of date", permanently, on every
 * open. That is precisely the silent failure this whole key exists to avoid.
 * PostgreSQL stores short strings in varchar(n) and char(n) identically, so the
 * padding buys nothing in exchange.
 *
 * THE INDEX IS FOR THE ADMINISTRATOR, NOT THE READER
 * ──────────────────────────────────────────────────
 * The read path is a primary-key lookup and needs nothing. (model,
 * prompt_version) is what answers "how many of these are still usable" after a
 * model change without scanning the table.
 */
final class Version20260902100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create thread_summary: one on-demand AI summary per thread, keyed by a hash of what was summarised.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE thread_summary (
                thread_id INT NOT NULL,
                summary TEXT NOT NULL,
                source_hash VARCHAR(64) NOT NULL,
                model VARCHAR(128) NOT NULL,
                prompt_version INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(thread_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE thread_summary
                ADD CONSTRAINT fk_thread_summary_thread
                FOREIGN KEY (thread_id) REFERENCES message_thread (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql('CREATE INDEX idx_thread_summary_model ON thread_summary (model, prompt_version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE thread_summary');
    }
}
