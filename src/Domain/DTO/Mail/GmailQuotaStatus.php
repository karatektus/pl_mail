<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * Where a Gmail import stands against Google's quota, for the line that shows
 * the import.
 *
 * An import of a large mailbox is as fast as the quota lets it be and no
 * faster, so "why is this taking so long" and "is it stuck" are both
 * questions about the quota. Before this the line had one word for all of it
 * — "waiting" — whether the import was between two listings, held for a
 * minute by Google, or had stopped (#90).
 *
 * Every figure here is this application's own reckoning. Google reports
 * neither what is left of a quota nor, until it refuses a request, what the
 * quota is. See GmailQuotaPacer.
 */
final readonly class GmailQuotaStatus
{
    /**
     * @param int      $limit         units a minute the import is being paced to
     * @param bool     $learnt        whether Google named that limit, or it is
     *                                the one assumed until it does
     * @param int      $used          roughly what the bulk fetching spent in
     *                                the minute just gone — or, while paused,
     *                                in the minute before Google refused
     * @param int      $pausedSeconds how long Google's last refusal still
     *                                holds the account; 0 when it does not
     * @param int      $messagesPerMinute roughly how many messages were
     *                                fetched in the minute just gone; 0 while
     *                                paused, when none are
     * @param int|null $minutesLeft   how long the rest of the import takes at
     *                                the paced rate, or null when there is no
     *                                total to reckon it from
     */
    public function __construct(
        public int  $limit,
        public bool $learnt,
        public int  $used,
        public int  $pausedSeconds,
        public int  $messagesPerMinute,
        public ?int $minutesLeft,
    ) {}

    public function isPaused(): bool
    {
        return $this->pausedSeconds > 0;
    }
}
