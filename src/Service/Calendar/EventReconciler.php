<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\EventSourceLinkRepository;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\Extraction\ExtractedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns what an extractor claimed into what the calendar shows.
 *
 * This is where a booking's life happens: a confirmation, then a change, then
 * a cancellation, usually across a thread and not always in order. Getting it
 * wrong shows up as three copies of one dinner, or a meeting that quietly
 * un-cancels itself because an older mail was synced last.
 *
 * Five rules, each of which exists because the obvious alternative is worse:
 *
 *   Identity is the uid, unique per calendar. For an invite that is the
 *   sender's own UID, verbatim — RFC 5546 settled this and re-deciding it
 *   makes plMail disagree with every other client about which update wins.
 *
 *   A later revision wins, by SEQUENCE, falling back to when the mail arrived.
 *   Out-of-order delivery is normal, so an older revision arriving after a
 *   newer one is filed rather than applied.
 *
 *   A superseded extraction is still recorded, with applied = false. That is
 *   what makes "why is this on my calendar?" answerable, and it is the
 *   difference between an audit trail and a guess.
 *
 *   Cancellation sets a status; it never deletes. Users want to see that the
 *   thing was called off, and deleting the row fights anyone who wants it
 *   back.
 *
 *   A user-edited event is never overwritten. A later mail may know more about
 *   the booking, but it does not know more than the person who corrected it.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class EventReconciler
{
    public function __construct(
        private CalendarEventRepository        $events,
        private EventSourceLinkRepository      $links,
        private EventSuppressionRepository     $suppressions,
        private ExtractedEventCalendarResolver $calendarResolver,
        private CalendarEventWriter            $writer,
        private EntityManagerInterface         $em,
        private LoggerInterface                $logger,
    ) {
    }

    /**
     * @param list<ExtractedEvent> $extracted
     *
     * @return list<CalendarEvent> the events this message created or changed
     */
    public function reconcile(Message $message, array $extracted): array
    {
        if ([] === $extracted) {
            return [];
        }

        $account  = $message->account;
        $user     = $account->usr;
        $calendar = $this->calendarResolver->resolve($account);

        if (false === $user instanceof User || null === $calendar) {
            return [];
        }

        $touched = [];

        foreach ($extracted as $claim) {
            // Asked before anything is created: dismissing an event has to
            // survive re-extraction, or every backfill puts back the thing the
            // user just threw away.
            if (true === $this->suppressions->isSuppressed($user, $claim->dedupKey)) {
                continue;
            }

            $existing = $this->events->findOneByUid($calendar, $claim->uid)
                ?? $this->pendingByUid($calendar, $claim->uid);

            $event = null === $existing
                ? $this->create($claim, $calendar, $user, $message)
                : $this->update($existing, $claim, $message);

            if (null !== $event) {
                $touched[] = $event;
            }
        }

        return $touched;
    }

    private function create(
        ExtractedEvent $claim,
        object         $calendar,
        User           $user,
        Message        $message,
    ): CalendarEvent {
        $event      = new CalendarEvent();
        $event->uid = $claim->uid;

        $this->apply($event, $claim, $calendar, $user);
        $this->link($event, $claim, $message, applied: true);

        return $event;
    }

    private function update(CalendarEvent $event, ExtractedEvent $claim, Message $message): ?CalendarEvent
    {
        if (true === $event->isUserEdited) {
            $this->logger->info('EventReconciler: skipping update to a user-edited event', [
                'eventId' => $event->id,
                'uid'     => $event->uid,
            ]);

            $this->link($event, $claim, $message, applied: false);

            return null;
        }

        if (false === $this->supersedes($event, $claim, $message)) {
            $this->link($event, $claim, $message, applied: false);

            return null;
        }

        $this->apply($event, $claim, $event->calendar, $event->usr);
        $this->link($event, $claim, $message, applied: true);

        return $event;
    }

    /**
     * SEQUENCE first, since that is what iCalendar revisions are for. Equal
     * sequences fall back to arrival time, which is the only ordering a
     * non-invite source gives us at all — a booking confirmation carries no
     * revision number, only a date.
     */
    private function supersedes(CalendarEvent $event, ExtractedEvent $claim, Message $message): bool
    {
        if ($claim->sequence > $event->sequence) {
            return true;
        }

        if ($claim->sequence < $event->sequence) {
            return false;
        }

        $arrived = $message->receivedAt ?? $message->sentAt;

        // An event this batch created has no id yet, so it cannot be bound as
        // a query parameter — and it has no committed links to find either.
        // Both facts say the same thing: there is nothing older to lose to.
        $latest = null === $event->id ? null : $this->links->latestAppliedAt($event);

        return null === $arrived || null === $latest || $arrived >= $latest;
    }

    private function apply(CalendarEvent $event, ExtractedEvent $claim, object $calendar, User $user): void
    {
        $this->writer->write(
            event:       $event,
            calendar:    $calendar,
            user:        $user,
            title:       $claim->title ?? 'Untitled',
            startsAt:    $claim->startsAt,
            endsAt:      $claim->endsAt,
            timeZone:    $claim->timeZone,
            isAllDay:    $claim->isAllDay,
            location:    $claim->location,
            description: $claim->jscalendar['description'] ?? null,
            status:      $claim->status,
            jscalendarOverlay: $claim->jscalendar,
        );

        $event->source     = $claim->source;
        $event->confidence = $claim->confidence;
        $event->kind       = $claim->kind;
        $event->sequence   = $claim->sequence;

        if (EventStatus::Cancelled === $claim->status) {
            // Kept, struck through, never deleted — see the class docblock.
            foreach ($event->occurrences as $occurrence) {
                $occurrence->cancelled = true;
            }
        }
    }

    /**
     * An event this unit of work has created but not yet flushed.
     *
     * findOneByUid() asks the database, which cannot see a queued INSERT — so
     * two messages in one batch carrying the same UID each found nothing, each
     * created an event, and the flush was rejected by the unique constraint on
     * (calendar_id, uid). A resend and its original land in the same batch
     * routinely: a backfill processes a whole mailbox at once, and an invite
     * is usually sent more than once.
     *
     * Read from the UnitOfWork rather than kept in a property here. The
     * scheduled set is the actual answer to "what will exist after the flush",
     * it is emptied by em->clear() between batches with nothing to remember to
     * reset, and this service stays stateless.
     */
    private function pendingByUid(object $calendar, string $uid): ?CalendarEvent
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $queued) {
            if (false === $queued instanceof CalendarEvent) {
                continue;
            }

            if ($queued->calendar === $calendar && $queued->uid === $uid) {
                return $queued;
            }
        }

        return null;
    }

    /**
     * One link per (event, message, extractor), so a message re-processed by a
     * backfill updates its own row instead of growing a second one.
     */
    private function link(CalendarEvent $event, ExtractedEvent $claim, Message $message, bool $applied): void
    {
        $link = $this->links->findOneBy([
            'event'     => $event,
            'message'   => $message,
            'extractor' => $claim->extractor,
        ]) ?? new EventSourceLink();

        $link->event       = $event;
        $link->message     = $message;
        $link->messagePart = $claim->part;
        $link->extractor   = $claim->extractor;
        $link->confidence  = $claim->confidence;
        $link->dedupKey    = $claim->dedupKey;
        $link->applied     = $applied;
        $link->payload     = $claim->sourcePayload;

        $this->em->persist($link);
    }
}
