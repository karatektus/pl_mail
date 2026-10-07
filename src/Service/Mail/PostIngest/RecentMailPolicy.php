<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Entity\Mail\Message;
use DateTimeImmutable;

/**
 * How old a message may be and still be worth doing things to on arrival.
 *
 * WHY ARRIVING AND BEING NEW STOPPED BEING THE SAME THING
 * ──────────────────────────────────────────────────────
 * Every post-ingest step used to treat "this message has just been ingested"
 * as "this message has just arrived", and on an ordinary day the two are the
 * same. On the day an account is added they are not: a mailbox of fifty
 * thousand messages is ingested in an afternoon, and each one was queued for a
 * model call, an event extraction and a receipt scan as though somebody were
 * waiting to read it. A newsletter from 2019 does not need a second opinion on
 * its tab, an invitation to a meeting held four years ago is not an event, and
 * nobody is owed a read receipt for mail that predates the installation.
 *
 * So the question a step asks is no longer "is this in my batch" but "is this
 * recent", and this is the one place that says what recent means.
 *
 * BY THE MAIL'S OWN DATE, NOT BY WHEN IT WAS IMPORTED. The alternative — a
 * flag on the account saying "the first import is still running" — answers a
 * different question and answers it worse: it would skip last week's mail
 * during an import and process a ten-year-old folder somebody subscribed to
 * afterwards. Age is the property that actually decides whether the work is
 * worth doing, and it is already on the row.
 *
 * WHAT OLD MAIL STILL GETS: a row, a thread, the rule cascade's category, the
 * user's own rules and its contacts harvested — everything PostIngestPipeline
 * does itself. What it does not get is anything a step queues. The backfill
 * tasks (`app:backfill insights`, `event-extraction`, …) are how history is
 * read when somebody wants it, and old mail is classified by the assistant
 * later and in the background — see App\Service\Ai\ClassificationCatchUp.
 *
 * Read through `%env(default:…)%` in config/services.yaml, like BackfillPolicy
 * and for its reason: the answer for almost everybody is "leave it alone".
 */
final readonly class RecentMailPolicy
{
    /** Days back from now that still count as recent. */
    public int $recentDays;

    public function __construct(int $recentDays)
    {
        // Clamped rather than validated: it comes from the environment, and a
        // typo must not be able to switch every step off (zero) or quietly
        // restore the behaviour this exists to remove (a million).
        $this->recentDays = max(1, min(3_650, $recentDays));
    }

    public function cutoff(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable())->modify(sprintf('-%d days', $this->recentDays));
    }

    /**
     * receivedAt first and sentAt as the fallback, the order EventProposer
     * anchors on.
     *
     * A MESSAGE WITH NO DATE AT ALL IS RECENT. It is rare — a builder that
     * could not read either — and of the two mistakes available, doing a little
     * unnecessary work is the one nobody notices. Treating it as old would
     * silently drop a real arrival out of everything the steps do.
     */
    public function isRecent(Message $message, ?DateTimeImmutable $now = null): bool
    {
        $date = $message->receivedAt ?? $message->sentAt;

        if (null === $date) {
            return true;
        }

        return $date >= $this->cutoff($now);
    }

    /**
     * The ids in a batch that a step should queue work for.
     *
     * Here rather than in each step because five of them were about to carry
     * the same loop, and the loop is where the cutoff has to be applied the
     * same way every time.
     *
     * @return list<int>
     */
    public function recentIds(PostIngestResult $result, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $ids   = [];

        foreach ($result->messages as $message) {
            $id = $message->id;

            if (null === $id || false === $this->isRecent($message, $now)) {
                continue;
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }
}
