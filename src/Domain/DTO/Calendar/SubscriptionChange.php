<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * What one pass over the subscribe screen's tick boxes actually did.
 *
 * Three counts rather than a boolean, because the interesting one is the third:
 * unsubscribing deletes a calendar, and the events on it that the remote never
 * gave us are moved to the user's default calendar rather than going with it.
 * That is a consequence a person has to be told about in the same breath as the
 * action — "Stopped mirroring 1 calendar" alone would leave them looking for
 * the dinner reservation that used to be on it.
 *
 * $kept is deliberately a count of events and not of calendars, unlike the
 * other two. It is the only number here a user cannot see for themselves by
 * looking at the list afterwards.
 */
final readonly class SubscriptionChange
{
    public function __construct(
        public int $subscribed = 0,
        public int $unsubscribed = 0,
        public int $kept = 0,
    ) {
    }

    /**
     * Whether anything happened at all. A submit that ticked nothing new and
     * unticked nothing is a legitimate outcome — the user opened the list,
     * looked, and pressed Save — and it must not be reported as a change.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->subscribed && 0 === $this->unsubscribed;
    }
}
