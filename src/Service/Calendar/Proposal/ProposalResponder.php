<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Domain\Enum\Calendar\EventSource;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventProposal;
use App\Entity\Calendar\EventSuppression;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\ExtractedEventCalendarResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Yes and no, and what each of them costs.
 *
 * **Yes** writes a CalendarEvent through CalendarEventWriter and deletes the
 * proposal. Through the writer, not by assembling an event here: that class is
 * the one place the canonical JSCalendar object and the columns projected from
 * it are kept in step, and it is what materialises the occurrences every view
 * reads. An event built beside it looks right in the editor and is invisible in
 * the calendar, which is the specific bug the writer exists to make impossible.
 *
 * The event it writes carries no extraction kind and full confidence, and that
 * is a decision rather than an oversight. A person read the sentence, looked at
 * the date and said yes; what is on the calendar afterwards is theirs. Marking
 * it extracted would enrol it in machinery that has nothing to say about it:
 * the reconciler's supersede-and-overwrite rules, and a "found in your email"
 * note beside an event the user typed the assent to.
 *
 * Its source is AcceptedProposal, though, and not Manual — which is what this
 * used to write. Manual was a small lie: the day, the hour and the title were
 * this application's reading of somebody else's sentence, and agreeing with a
 * guess is not the same act as making one. Nothing downstream treats the two
 * differently today; the difference is recorded because it is unrecoverable
 * afterwards, which is the same reason EventSource::Llm exists unused.
 *
 * What does survive is the sentence, as the event's description. The proposal
 * row goes, so without it "why is this in my calendar?" would have no answer at
 * all a week later.
 *
 * **No** writes an EventSuppression keyed on the proposal's dedup key hash and
 * deletes the proposal. The row is the whole point: detection is re-runnable by
 * design — `app:backfill proposals` walks stored mail whenever the parser
 * improves — so a plain delete lasts until the next run and the user watches
 * the thing they refused come back. This is the same table and the same
 * mechanism EventDismisser uses for extracted events, deliberately, so there is
 * one answer to "the user said this is not an event" rather than two.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class ProposalResponder
{
    public function __construct(
        private ExtractedEventCalendarResolver $calendars,
        private CalendarEventWriter            $writer,
        private EventSuppressionRepository     $suppressions,
        private EntityManagerInterface         $em,
    ) {
    }

    /**
     * @return CalendarEvent|null null when the user has no calendar this could
     *                            land on, which the caller reports rather than
     *                            swallowing
     */
    public function accept(EventProposal $proposal): ?CalendarEvent
    {
        $calendar = $this->calendars->resolve($proposal->message->account);

        if (null === $calendar) {
            return null;
        }

        $event = $this->writer->write(
            event:       new CalendarEvent(),
            calendar:    $calendar,
            user:        $proposal->usr,
            title:       '' !== $proposal->title ? $proposal->title : 'Untitled',
            startsAt:    $proposal->startsAt,
            endsAt:      $proposal->endsAt,
            timeZone:    $proposal->timeZone,
            isAllDay:    $proposal->isAllDay,
            location:    null,
            description: $proposal->sourceSentence,
        );

        // Stated here rather than by the writer: write() is about the canonical
        // JSCalendar object and the columns projected from it, and it is also
        // how the sync engine applies what it read from a remote — so who is
        // responsible for an event is the caller's to say, exactly as it is for
        // EventReconciler.
        $event->source = EventSource::AcceptedProposal;

        // After write(), which is what assigns the calendar the mark is decided
        // against, and a no-op on a calendar that mirrors nothing — the same
        // order and the same reasoning as the event editor.
        $this->writer->markLocallyCreated($event);

        $this->em->remove($proposal);

        return $event;
    }

    /**
     * @return bool whether this refusal was newly recorded; false means the
     *              same claim had already been refused
     */
    public function dismiss(EventProposal $proposal): bool
    {
        $this->em->remove($proposal);

        // The hash rather than the key, because the row already carries the
        // hash and EventSuppression stores nothing else — see the entity.
        if (null !== $this->suppressions->findOneBy([
            'usr'          => $proposal->usr,
            'dedupKeyHash' => $proposal->dedupKeyHash,
        ])) {
            return false;
        }

        $suppression               = new EventSuppression();
        $suppression->usr          = $proposal->usr;
        $suppression->dedupKeyHash = $proposal->dedupKeyHash;

        $this->em->persist($suppression);

        return true;
    }
}
