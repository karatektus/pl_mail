<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailThrottledException;
use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Whether one OAuth account has new mail worth a sync, in any folder, found by asking
 * the provider rather than waiting to be told.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Gmail push needs Google to reach plMail through Pub/Sub, and Outlook push
 * needs Microsoft to reach a public HTTPS address. An install that cannot be
 * reached from the internet has neither, and an account without push is synced
 * on the quarter-hourly poll: mail that is on the phone at once appears here up
 * to fifteen minutes later. This asks every InboxProbeInterval::seconds() instead, one cheap
 * question per account, and the real sync is queued only when the answer is yes.
 *
 * THE ONE WAY THIS GOES WRONG, AND WHAT STOPS IT
 * ──────────────────────────────────────────────
 * Gmail's answer stays "yes" until the sync it triggers has moved the cursor,
 * and that sync waits on a queue with one consumer, which may be minutes deep.
 * A probe that simply reported the answer would queue another full sync on the
 * next tick, and the next: ImapIdleCommand::SYNC_MIN_GAP is the record of what
 * that looked like the last time. So each account remembers WHAT it last
 * announced, not when, and announces a thing once. Mail that arrives meanwhile
 * is a different thing and is announced in its own right, which keeps the number
 * of syncs bounded by the number of arrivals rather than by the number of ticks.
 *
 * That only holds if the thing is STABLE: a signal that can change without
 * anything arriving is announced as often as it flickers. Outlook's is the
 * newest message's timestamp for exactly that reason — see
 * GraphApiClient::newestReceivedAt().
 *
 * WHAT IT COVERS: mail arriving in any folder — the inbox, spam, and wherever
 * a filter or rule files it. It used to be the inbox alone, because that is all
 * push announces; but "my phone has it and plMail doesn't" is as true of spam,
 * and the wait for the quarter-hourly poll was the same fifteen minutes.
 * Gmail's own drafts and sent copies are not arrivals — see
 * GmailApiClient::probeArrivals(). Gmail push still announces the inbox alone
 * (the watch is registered on that label, GmailWatchService::LABEL), so for a
 * Gmail account that has push as well, only this probe sees the rest.
 *
 * WHAT IT DOES NOT COVER, on purpose: every non-arrival change (read state,
 * labels, deletions made elsewhere). They are still picked up by the
 * quarter-hourly poll, which this leaves exactly as it was. The signal memory
 * is in the cache, so losing it is one redundant sync, never a missed one.
 */
final readonly class InboxProbe
{
    /**
     * How long an account is left alone after any failure that said nothing
     * about when to come back. Short enough that a blip costs minutes of
     * latency; long enough that a provider outage, a revoked grant or a bad
     * response is five requests an hour instead of sixty.
     */
    private const int FAILURE_PAUSE_SECONDS = 300;

    /** How long a remembered arrival is kept. Losing it costs one redundant sync. */
    private const int MEMORY_SECONDS = 14 * 86400;

    public function __construct(
        private GmailApiClient         $gmail,
        private GraphApiClient         $graph,
        private CacheItemPoolInterface $cache,
        private ClockInterface         $clock,
        private LoggerInterface        $logger,
    ) {}

    /**
     * Whether the caller should queue a sync for this account now.
     *
     * Never throws: a probe that failed is "no news", with the account left
     * alone for a while, because the quarter-hourly poll is already the
     * backstop and an exception here would only take down the accounts after
     * this one in the same run.
     */
    public function shouldSync(Account $account): bool
    {
        $id = (int) $account->id;

        if ($this->pausedUntil($id) > $this->clock->now()->getTimestamp()) {
            return false;
        }

        try {
            $signal = $this->signalOf($account);
        } catch (GmailThrottledException | GraphThrottledException $throttled) {
            // What the provider asked for, not a guess: its own Retry-After, or
            // the exception's fallback when it sent none.
            $this->pause($id, intdiv($throttled->getRetryDelay(), 1000));

            return false;
        } catch (\Throwable $failure) {
            $this->logger->warning('InboxProbe: could not look at an account; leaving it to the scheduled sync for a while', [
                'accountId' => $id,
                'error'     => $failure->getMessage(),
            ]);

            $this->pause($id, self::FAILURE_PAUSE_SECONDS);

            return false;
        }

        if (null === $signal) {
            return false;
        }

        $previous = $this->remembered($id);

        if ($previous === $signal) {
            return false;
        }

        $this->remember($id, $signal);

        // A first look at a Microsoft account has nothing to be compared with,
        // so a message being there is not news — only a later change is. A
        // Gmail signal is different in kind: it exists only when the history
        // window holds an arrival, so it is evidence even the first time.
        return null !== $previous || true === $account->isGmail();
    }

    /**
     * A token for what the provider reports as the newest arrival, or
     * null when there is nothing to report or nothing to ask about.
     */
    private function signalOf(Account $account): ?string
    {
        if (true === $account->isGmail()) {
            $cursor = $account->gmailHistoryId;

            // No cursor: the first sync has not finished, and starting it is
            // the scheduled sync's job, not a probe's.
            if (null === $cursor) {
                return null;
            }

            try {
                return $this->gmail->probeArrivals($account, $cursor);
            } catch (GmailThrottledException $throttled) {
                throw $throttled;
            } catch (GmailApiException $failure) {
                // The cursor is older than the history Gmail keeps. Recovering
                // from that is the real sync's job (GmailApiSyncer::
                // syncIncremental re-lists the mailbox); the probe's is to
                // start it, once. Named by the cursor so it is announced once
                // per dead cursor and not once per tick until the sync has run.
                if (true === in_array($failure->getStatus(), [404, 410], true)) {
                    return 'expired:' . $cursor;
                }

                throw $failure;
            }
        }

        if (true === $account->isMicrosoft()) {
            // Never synced: no baseline to compare with, and the initial import
            // is the scheduled sync's.
            if (null === $account->lastSyncedAt) {
                return null;
            }

            // The timestamp and not an id: see newestReceivedAt() on why
            // an id can flip between two messages received in the same second.
            return $this->graph->newestReceivedAt($account);
        }

        return null;
    }

    private function pausedUntil(int $accountId): int
    {
        $item = $this->cache->getItem($this->key('pause', $accountId));

        return true === $item->isHit() ? (int) $item->get() : 0;
    }

    private function pause(int $accountId, int $seconds): void
    {
        $item = $this->cache->getItem($this->key('pause', $accountId));

        $item->set($this->clock->now()->getTimestamp() + max(1, $seconds));
        $item->expiresAfter(max(1, $seconds));

        $this->cache->save($item);
    }

    private function remembered(int $accountId): ?string
    {
        $item = $this->cache->getItem($this->key('seen', $accountId));

        return true === $item->isHit() ? (string) $item->get() : null;
    }

    private function remember(int $accountId, string $signal): void
    {
        $item = $this->cache->getItem($this->key('seen', $accountId));

        $item->set($signal);
        $item->expiresAfter(self::MEMORY_SECONDS);

        $this->cache->save($item);
    }

    private function key(string $what, int $accountId): string
    {
        return 'inbox_probe.' . $what . '.' . $accountId;
    }
}
