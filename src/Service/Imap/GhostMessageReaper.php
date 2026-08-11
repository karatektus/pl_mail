<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MessageEraser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes rows that a failed fetch left behind pretending to be mail.
 *
 * The bug that made them is fixed at the source — MessageSyncer::isUsableFetch()
 * refuses a fetch that carried nothing — but that only stops new ones. An
 * install that ran the old code still holds the corpses: blank rows with a "?"
 * avatar, dated 1970-01-01, counted as unread because nothing ever set seenAt,
 * which is how seven of them could be the entire Spam badge.
 *
 * Self-repair rather than a one-shot migration, on the same reasoning as
 * SentCopyReconciler::repair(): a migration only helps installs that upgrade
 * through this exact version, and the rows are cheap to look for. On a database
 * that has none — every database, once this has run once — it is one indexed
 * query per sync that returns nothing.
 *
 * The predicate lives in MessageRepository::findEpochGhosts(), where its
 * conservatism is argued line by line. It is deliberately unable to match a
 * subjectless message, an undated message or an empty draft.
 */
readonly class GhostMessageReaper
{
    /**
     * Capped per run for the same reason the vanished reaper is: erasing is the
     * one thing here the next poll cannot undo, so it happens a batch at a time
     * and leaves evidence in the log.
     */
    private const int REAP_BATCH = 100;

    public function __construct(
        private MessageRepository      $messages,
        private MessageEraser          $eraser,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /**
     * @return int how many rows were removed
     */
    public function reap(): int
    {
        $ghosts = $this->messages->findEpochGhosts(self::REAP_BATCH);

        if ([] === $ghosts) {
            return 0;
        }

        // Logged before the erase, and with the ids, so that if the predicate
        // ever does match something it should not have, there is a record of
        // exactly what went and the argument can be had against evidence.
        $this->logger->warning('Removing epoch-dated ghost rows left by failed IMAP fetches', [
            'count' => count($ghosts),
            'ids'   => array_map(static fn ($message): ?int => $message->id, $ghosts),
        ]);

        $erased = $this->eraser->eraseAll($ghosts);

        // Flushed here rather than left to the caller: syncMailbox recomputes
        // the mailbox's unread and total counts immediately after this runs,
        // and counting rows that are still pending deletion would reproduce the
        // very badge the reaper exists to correct.
        $this->em->flush();

        return $erased;
    }
}
