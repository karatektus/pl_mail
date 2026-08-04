<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSuppression;
use App\Entity\User\User;
use App\Repository\Calendar\EventSourceLinkRepository;
use App\Repository\Calendar\EventSuppressionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "This is not an event" — throwing an extracted event away, and remembering
 * that it was thrown away.
 *
 * Removing the row is the obvious half, and useless on its own. Extraction is
 * re-runnable by design: a backfill walks the whole mailbox again whenever a
 * mapper improves, and the same message yields the same claim — so a plain
 * delete lasts until the next run, and the user watches the thing they threw
 * away come back. EventSuppression exists for exactly this and, until now,
 * nothing wrote a row into it.
 *
 * What is remembered is the dedup key, not the event. Every claim carries one
 * before an event exists at all (EventSourceLink::$dedupKey), and
 * EventReconciler asks about it before it creates anything — so the refusal
 * also catches the *next* message about the same booking, which is the one
 * that would otherwise put back a second copy of what was just dismissed.
 *
 * Only extracted events can be dismissed, and that is a rule rather than a
 * convenience: a hand-made event has no claim behind it, nothing would ever
 * re-create it, and suppressing its uid would silently refuse a real invite
 * that later arrived carrying the same one.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class EventDismisser
{
    public function __construct(
        private EventSourceLinkRepository  $links,
        private EventSuppressionRepository $suppressions,
        private EntityManagerInterface     $em,
    ) {
    }

    /**
     * Whether this event is the kind that can be refused.
     *
     * Stays a method rather than a property on the entity: "extracted" is the
     * entity's business, but "may be suppressed" is this service's rule about
     * what suppression means, and CalendarEvent has no reason to know it.
     */
    public function canDismiss(CalendarEvent $event): bool
    {
        return $event->isExtracted();
    }

    /**
     * @return int how many distinct claims this refusal now covers
     */
    public function dismiss(CalendarEvent $event, User $user): int
    {
        // Asked of the repository rather than read off $event->sourceLinks:
        // that collection is the inverse side and is empty for an event whose
        // links were persisted in the same unit of work, which made this
        // suppress nothing while appearing to work.
        $keys = $this->links->findDedupKeysForEvent($event);

        foreach ($keys as $key) {
            if (true === $this->suppressions->isSuppressed($user, $key)) {
                continue;
            }

            $suppression               = new EventSuppression();
            $suppression->usr          = $user;
            $suppression->dedupKeyHash = EventSuppressionRepository::hash($key);

            $this->em->persist($suppression);
        }

        // After the keys are read: they are a query against this event's id,
        // and a removed entity is not one to be querying by.
        $this->em->remove($event);

        return count($keys);
    }
}
