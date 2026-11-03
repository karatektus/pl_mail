<?php

declare(strict_types=1);

namespace App\Service\Imap;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which folders already have a sync waiting in the queue.
 *
 * ── What happened (#418) ────────────────────────────────────────────────────
 * A Gmail account whose syncs kept running into the five-minute deadline
 * (ImapDeadline) left 589 envelopes on `ingest`, the oldest eight hours old,
 * and a QQ account's sync sat unclaimed behind them all night — although run
 * on its own it finished in two and a half minutes. Nothing was slow about QQ.
 * The queue was simply growing faster than the one ingest worker could empty
 * it, and a queue that grows without end starves whatever is behind it, in
 * any order.
 *
 * It grew because ImapIdleListener spaces syncs out by when they were SENT
 * (SYNC_MIN_GAP, five seconds) and never asks whether the last one has run.
 * A folder that changes while its sync takes five minutes to fail asks again
 * and again, and every one of those is another five minutes. The handler's
 * own guard did not catch it either: it refuses a sync when the folder was
 * synced moments ago, and a sync that fails never says it was.
 *
 * ── What this does ──────────────────────────────────────────────────────────
 * A folder with a sync waiting gets no second one. The listener asks here
 * before it dispatches; the handler says the sync has been taken as it
 * starts, so a change that lands while it runs queues exactly one more. That
 * bounds what one folder can have on the queue at one, however slow its
 * server is.
 *
 * Nothing is lost by refusing: the waiting sync has not started, so it reads
 * whatever the refused one would have read. The sweep a notification asks for
 * is written onto the mailbox before this is asked (markSweepDue), so it
 * rides along with the waiting sync too.
 *
 * ── Why it expires ──────────────────────────────────────────────────────────
 * An envelope can leave the queue without its handler ever starting — a
 * failure transport, a purge, a worker killed holding it. A marker that
 * outlived it would stop that folder's IDLE syncs for good, and only the
 * account's poll would still bring mail in. Fifteen minutes: past that, a
 * second sync for a folder costs one envelope a quarter of an hour, which is
 * not what filled the queue.
 *
 * In the database, for the reason the other shared pools in cache.yaml are:
 * the IDLE listener and the worker are separate containers.
 */
final readonly class MailboxSyncQueue
{
    public const int LIFETIME_SECONDS = 900;

    public function __construct(
        #[Autowire(service: 'app.mailbox_sync_queue')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * Mark a sync for this folder as queued, unless one already is.
     *
     * @return bool true when the caller should dispatch it
     */
    public function claim(int $mailboxId): bool
    {
        $item = $this->pool->getItem(self::key($mailboxId));

        if (true === $item->isHit()) {
            return false;
        }

        $item->set(time());
        $item->expiresAfter(self::LIFETIME_SECONDS);

        $this->pool->save($item);

        return true;
    }

    /** The queued sync has been picked up; the next change may queue another. */
    public function taken(int $mailboxId): void
    {
        $this->pool->deleteItem(self::key($mailboxId));
    }

    private static function key(int $mailboxId): string
    {
        return sprintf('mailbox_sync.%d', $mailboxId);
    }
}
