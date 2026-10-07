<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * What PostIngestPipeline processed, for the caller's own tail.
 *
 * The three sync paths do not finish the same way — IMAP publishes its Mercure
 * update and dispatches contact harvesting one level up, in
 * SyncImapMailboxMessageHandler, while Gmail and Graph do both inline — so the
 * pipeline stops at the last flush and hands back what each caller needs to
 * finish its own way.
 */
final readonly class PostIngestResult
{
    /**
     * @param list<Message>          $messages         in the order they were ingested
     * @param array<int, Account>    $accounts         owning accounts, keyed by id
     * @param array<int, list<int>>  $threadIdsByAccount thread ids touched, keyed by owning account id
     */
    public function __construct(
        public array $messages,
        public array $accounts,
        public array $threadIdsByAccount,
        /**
         * Whether this is mail trickling in rather than a mailbox being
         * imported: every owning account has finished its first import.
         *
         * Decided once by the pipeline and carried here, because every step
         * that queues work needs the same answer to choose a queue and each
         * asking for itself would be a query per step per batch. Defaulted to
         * false, which is the safe reading — bulk work is never put in front
         * of live mail by an omission. See EnrichmentRouter.
         */
        public bool $live = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === count($this->messages);
    }
}
