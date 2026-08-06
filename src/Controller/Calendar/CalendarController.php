<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Domain\DTO\Calendar\EventCopy;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Domain\Enum\Calendar\CalendarView;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\CalendarRangeReader;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\EventClusterer;
use App\Service\Calendar\EventCopyResolver;
use App\Service\Calendar\EventDismisser;
use App\Service\Calendar\EventInstanceEditor;
use App\Service\Calendar\EventMover;
use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The calendar, in its two shapes.
 *
 * On a wide screen it is a pane docked beside the mail panes, resizable against
 * them; below md it is a page that replaces the mail view entirely. Both render
 * the same partials — they differ in their chrome and in which view they open
 * on, because a 380px pane has no business drawing a month grid.
 *
 * A view is a route segment rather than client state. That keeps every view
 * linkable, keeps the range a single indexed query, and means switching views
 * is a Turbo Frame navigation instead of a re-layout.
 *
 * The event editor renders into the app's body-level modal frame, never into
 * the pane. The pane and .main-pane both carry backdrop-filter, which makes
 * them containing blocks for position:fixed — a modal opened inside one is
 * clipped to it. The compose window learned this the hard way.
 *
 * An editor opened from a chip is opened on ONE occurrence, and says so: the
 * chip puts that occurrence's recurrence id in the URL, the form posts it back,
 * and `scope` decides whether a save or a delete means the instance or the
 * series. Anything that cannot resolve an instance — a one-off event, a stale
 * form, a hand-edited parameter — means the series, which is what this
 * controller did before the choice existed.
 *
 * It is also opened on ONE EVENT and may act on several — including on rows
 * that do not exist yet. The editor's calendar control is a checkbox per
 * calendar the user owns, ticked where the meeting already is: a meeting that
 * reached plMail twice, extracted from its invitation onto the account's
 * calendar and mirrored from the provider onto a connected one, opens with two
 * ticks, and ticking a third calendar puts a copy there under the same UID. A
 * save or a delete is then N ordinary writes through CalendarEventWriter — each
 * marked for push by the same means a lone event is, because there is no second
 * push path and inventing one is how the two would drift. The loops below are
 * delegation: what "the same meeting" means lives in EventClusterer, which
 * calendars it could be on lives in EventCopyResolver, and what a write means
 * lives in the writer.
 */
#[Route('/calendar', name: 'app_calendar_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalendarController extends AbstractController
{
    /**
     * The `scope` value that means "this one occurrence" rather than the series.
     *
     * Named because save and delete both read it, and spelled in exactly one
     * other place — the radio in calendar/_event_modal.html.twig. Anything else
     * arriving in that field, including nothing at all, means the series.
     */
    private const string SCOPE_INSTANCE = 'instance';

    public function __construct(
        private readonly CalendarRepository     $calendars,
        private readonly CalendarRangeReader    $rangeReader,
        private readonly CalendarEventWriter    $writer,
        private readonly CalendarProvisioner    $provisioner,
        private readonly CalendarTimeResolver   $time,
        private readonly RecurrenceRuleConverter $recurrence,
        private readonly EventDismisser         $dismisser,
        private readonly EventInstanceEditor    $instances,
        private readonly EventMover             $mover,
        private readonly EventClusterer         $clusterer,
        private readonly EventCopyResolver      $copies,
        private readonly AlertReader            $alerts,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The full page: mobile, and anyone who navigates to /calendar directly. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('calendar/index.html.twig', $this->viewData($request, CalendarView::Week));
    }

    /**
     * The docked pane's body, loaded lazily so a mailbox render pays nothing
     * for a pane the user has closed.
     */
    #[Route('/pane', name: 'pane', methods: ['GET'])]
    public function pane(Request $request): Response
    {
        return $this->render(
            'calendar/_pane_frame.html.twig',
            $this->viewData($request, CalendarView::Agenda, isPane: true),
        );
    }

    /** One view at one date. The grid frame re-renders into itself. */
    #[Route('/{view}/{date}', name: 'view', requirements: ['view' => 'day|week|month|agenda'], methods: ['GET'])]
    public function view(Request $request, string $view, ?string $date = null): Response
    {
        $isPane = $request->query->getBoolean('pane');

        $data = $this->viewData(
            $request,
            CalendarView::from($view),
            null === $date ? null : $this->time->parseDate($date, $this->time->zoneFor($this->currentUser())),
            $isPane,
        );

        return $this->render(
            true === $isPane
                ? 'calendar/_pane_frame.html.twig'
                : 'calendar/index.html.twig',
            $data,
        );
    }

    /** The editor, empty or filled, rendered into the body-level modal frame. */
    #[Route('/event/new', name: 'event_new', methods: ['GET'])]
    public function eventNew(Request $request): Response
    {
        $user = $this->currentUser();
        $zone = $this->time->zoneFor($user);

        $startsAt = $this->time->parseDateTime($request->query->getString('start'), $zone)
            ?? new DateTimeImmutable('today 09:00', $zone);

        $this->ensureCalendars($user);

        return $this->render('calendar/_event_modal.html.twig', [
            'event'        => null,
            // Nothing exists to be a copy of yet, so nothing is ticked except
            // where a new event would land — and ticking a second calendar
            // creates it on both at once, under one UID.
            'copies'       => $this->copies->optionsFor(null, $user),
            'title'        => '',
            'startsAt'     => $startsAt,
            'endsAt'       => $startsAt->modify('+1 hour'),
            'timeZone'     => $zone->getName(),
            // The clock the two datetime fields are printed on, stated rather
            // than left to Twig's default — see _event_modal. Here it is the
            // same zone the save will read them back in, which is the point.
            'displayZone'  => $zone->getName(),
            'recurrenceId' => '',
            'returnTo'     => $this->returnTo($request),
        ]);
    }

    /**
     * The editor for one event, opened on one of its occurrences when the chip
     * that opened it named one.
     *
     * The times shown are the INSTANCE's, not the series' — a chip on the 5th
     * that opens an editor reading the 3rd is an editor that moves the wrong
     * one the moment it is saved, and an instance already moved once has to
     * re-open where it went rather than where the rule put it.
     *
     * The whole cluster is resolved from this event's UID, so the editor can
     * tick the calendars the meeting is already on. Any member's values would
     * do for the fields — a cluster of several exists only while its members
     * agree about all of them — so the opened event's are shown, which is also
     * what makes a merged chip open the same editor a lone one does.
     */
    #[Route('/event/{id}/edit', name: 'event_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function eventEdit(Request $request, CalendarEvent $event): Response
    {
        $this->assertOwned($event);

        $zone     = $this->time->eventZone($event, $this->currentUser());
        $instance = $this->instances->instance($event, $request->query->getString('instance'));

        $startsAt = null === $instance ? $event->startsAt : $instance->startsAt;
        $endsAt   = null === $instance ? $event->endsAt : $instance->endsAt;

        return $this->render('calendar/_event_modal.html.twig', [
            'event'        => $event,
            'copies'       => $this->copies->optionsFor($event, $this->currentUser()),
            'title'        => null === $instance ? (string) $event->title : $this->instances->titleOf($event, $instance),
            'startsAt'     => $startsAt->setTimezone($zone),
            'endsAt'       => $endsAt->setTimezone($zone),
            'timeZone'     => $event->timeZone ?? $this->time->zoneFor($this->currentUser())->getName(),
            // The clock the fields are printed on, and it has to be the one
            // they were converted to just above — Twig's `|date` re-converts to
            // whatever TwigTimezoneSubscriber last set, which is the *user's*
            // zone, so leaving it to the default silently printed an event
            // pinned to another zone on the reader's clock while the hidden
            // timeZone field still said the event's, and the save then read the
            // digits back in the zone they were not written in.
            //
            // `false` for an all-day event, which is what tells Twig to leave
            // the value in the zone it arrives in. eventZone() answers UTC for
            // one because it is FLOATING — a wall date at midnight, no zone —
            // and converting it does not translate it, it moves it. That is
            // where "all day · 02:00 – 02:00" came from for a Berlin user.
            'displayZone'  => true === $event->isAllDay ? false : $zone->getName(),
            'recurrenceId' => $this->instances->identify($instance),
            'returnTo'     => $this->returnTo($request),
        ]);
    }

    #[Route('/event/save', name: 'event_save', methods: ['POST'])]
    public function eventSave(Request $request): Response
    {
        $this->assertCsrf($request, 'calendar_event');

        $user    = $this->currentUser();
        $eventId = $request->request->getInt('eventId');
        $event   = 0 === $eventId ? null : $this->ownedEvent($eventId);

        $zoneName = $request->request->getString('timeZone') ?: $this->time->zoneFor($user)->getName();
        $isAllDay = $request->request->getBoolean('isAllDay');

        // An all-day event is FLOATING: the same wall-clock day everywhere, no
        // zone at all. So its digits are read in UTC rather than in the posted
        // zone, which is the same rule RecurrenceMaterialiser::zoneOf() expands
        // it by and the same one CalendarTimeResolver::eventZone() prints it
        // by. Reading them in a real zone instead stored midnight-in-Berlin as
        // 22:00 the previous day, and the calendar then drew the event across
        // two days — the "2am to 2am" shape, from the other end.
        $zone = true === $isAllDay
            ? new DateTimeZone('UTC')
            : $this->time->safeZone($zoneName);

        $startsAt = $this->time->parseDateTime($request->request->getString('startsAt'), $zone);
        $endsAt   = $this->time->parseDateTime($request->request->getString('endsAt'), $zone);

        if (null === $startsAt || null === $endsAt || $endsAt < $startsAt) {
            return $this->json(['error' => 'calendar.error.invalid_times'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Snapped rather than trusted. The checkbox is beside two
        // datetime-local fields that keep whatever hours they held when it was
        // ticked, so "all day" arrives carrying 09:00–10:00 unless something
        // makes it mean what it says. Midnight to the next midnight, exclusive,
        // which is the iCalendar convention the exporter and every sync mapper
        // already write — and at least one whole day, because an all-day event
        // that ends where it starts is a zero-length row no view can draw.
        if (true === $isAllDay) {
            $startsAt = $startsAt->setTime(0, 0);
            $endsAt   = $endsAt->setTime(0, 0);

            if ($endsAt <= $startsAt) {
                $endsAt = $startsAt->modify('+1 day');
            }
        }

        $this->ensureCalendars($user);

        $targets = $this->copies->chosen(
            $this->copies->optionsFor($event, $user),
            $request->request->all('calendars'),
        );

        // Every calendar unticked is an edit with nowhere to go. Refused rather
        // than performed silently, because a save that redraws the calendar
        // unchanged reads as a save that did not work.
        if ([] === $targets) {
            return $this->json(['error' => 'calendar.error.no_copies'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $isInstanceScope = self::SCOPE_INSTANCE === $request->request->getString('scope');

        // "This event" cannot also mean "and put the series on a calendar it
        // has never been on". There is no row for one occurrence to write, so a
        // create under this scope would have to invent the series it belongs to
        // out of the INSTANCE's times — which is how a weekly meeting ends up
        // on the new calendar starting on the day the user happened to click.
        // Refused whole rather than honoured for the copies that already exist
        // and silently skipped for the rest, because a tick that did nothing is
        // the kind of nothing people only notice weeks later.
        if (true === $isInstanceScope && true === $this->createsAnything($targets)) {
            return $this->json(['error' => 'calendar.error.instance_create'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Resolved once, against the event the editor was opened on, and then
        // written to every copy — like the title and the times above, and for
        // the same reason: the form is a statement about the meeting, not about
        // one row of it. AlertReader::chosen() drops any key that was not in the
        // list it rendered, so a crafted post can untick a reminder but cannot
        // invent one.
        //
        // The cost, for a merged chip: a copy carrying an alarm that the clicked
        // copy does not — one imported from a CalDAV server at an absolute
        // instant — loses it, because the ticked list is the whole statement.
        // Resolving per copy instead would keep it and introduce the opposite
        // bug, where unticking a reminder silently leaves it on every copy the
        // user was not looking at.
        $alerts = $this->alerts->chosen(
            $event,
            array_values(array_filter(
                $request->request->all('alerts'),
                is_string(...),
            )),
            // Read as a string and cast, not getInt(): the field is empty on
            // almost every save, and getInt() throws on a value it cannot
            // convert rather than answering zero — which turned every save
            // that did not set a custom reminder into a 500. A non-numeric
            // string casts to 0, which AlertReader::customAlert() already
            // refuses along with negatives and anything past its ceiling.
            (int) $request->request->getString('alertCustomMinutes') ?: null,
            AlertAction::fromJsCalendar($request->request->getString('alertCustomAction') ?: null),
        );

        // What the meeting said before this save, taken once from the event the
        // editor was opened on. Null for a brand-new event, which has nothing
        // to have been corrected — see where this is read, below the write.
        $openedOnContent = null === $event ? null : $this->contentOf($event);

        // Where the user is put down afterwards. Read off the first copy
        // written rather than off the posted field, because "all events" from
        // an editor opened on one occurrence rebases the series — and any copy
        // gives the same answer, since a cluster of several exists only while
        // its members agree about when they are.
        $landOn = $startsAt;

        // N calendars is N ordinary writes, each marked for push by the same
        // means a lone event is. A read-only calendar never reaches this loop:
        // EventCopyResolver::chosen() drops it whatever the request claimed.
        foreach ($targets as $index => $target) {
            $copy = $target->event;

            // "This event" is not a smaller version of "all events": there is no
            // row for one occurrence to write, so it becomes a patch on the
            // series and write() must not run at all. Running it would rewrite
            // the series from the fields the editor posted — which are the
            // INSTANCE's times — and move every other occurrence to where this
            // one was going.
            //
            // Resolved against THIS copy, not against the one whose chip was
            // clicked. Each copy is its own series with its own overrides map,
            // so a per-instance edit across a cluster is the same patch filed
            // once per copy, keyed by that copy's own occurrence at the same
            // recurrence id. A copy whose rule has no instance there answers
            // null and takes the series branch below, which is the honest
            // outcome: it disagrees afterwards and is drawn as its own chip.
            $openedOn = $this->instances->instance($copy, $request->request->getString('recurrenceId'));

            $instance = true === $isInstanceScope ? $openedOn : null;

            $copyStart = $startsAt;
            $copyEnd   = $endsAt;

            // "All events", from an editor that was opened on one of them. The
            // fields carry the INSTANCE's times, so they are read as the change
            // the user made to that instance rather than as the series' new
            // times — otherwise renaming a weekly meeting from its fifth
            // occurrence moves the whole series onto that day. See
            // EventInstanceEditor::seriesTimesFor().
            if (null === $instance && null !== $openedOn) {
                [$copyStart, $copyEnd] = $this->instances->seriesTimesFor($copy, $openedOn, $startsAt, $endsAt);
            }

            if (0 === $index) {
                $landOn = $copyStart;
            }

            if (null !== $instance) {
                // A per-instance edit is always a real edit: there is no way to
                // reach this branch except by moving or renaming one occurrence.
                $this->writer->markUserEdited($copy);

                $this->instances->edit(
                    $copy,
                    $instance,
                    $request->request->getString('title') ?: 'Untitled',
                    $copyStart,
                    $copyEnd,
                );

                continue;
            }

            // Before write(), which is what persists the row: this is the
            // difference between a POST and a PUT at the provider, and after
            // the write every copy looks equally established.
            $isNew = $target->isNew();

            $this->writer->write(
                event:          $copy,
                // Each copy's OWN calendar, always — the one it is on, or the
                // one the ticked box named for a copy that did not exist a
                // moment ago. There is no posted destination to honour and no
                // field left that could carry one: honouring one would move
                // every copy of the meeting onto a single calendar, collapsing
                // rows that each hold their own remoteId, etag and sync state.
                calendar:       $target->calendar,
                user:           $user,
                title:          $request->request->getString('title') ?: 'Untitled',
                startsAt:       $copyStart,
                endsAt:         $copyEnd,
                timeZone:       $zone->getName(),
                isAllDay:       $isAllDay,
                location:       $request->request->getString('location') ?: null,
                description:    $request->request->getString('description') ?: null,
                status:         EventStatus::Confirmed,
                recurrenceRule: $this->recurrence->fromRepeatChoice($request->request->getString('repeat')),
                // Stated on every save, never omitted. Passing null here means
                // "keep whatever is stored", which is right for a caller with
                // no opinion about alerts — the sync engine — and wrong for
                // this one: unticking every box would then be indistinguishable
                // from not asking, and a reminder could be set but never
                // cleared.
                alerts:         $alerts,
            );

            // Only where the save actually changed the meeting.
            //
            // markUserEdited() used to fire for every ticked calendar,
            // unconditionally, and that is wrong for the commonest use of this
            // form: opening an extracted event and ticking a *second* calendar
            // changes nothing about the meeting. It stamped every copy
            // user-edited anyway — the flag that tells EventReconciler to leave
            // an event alone — so the next update from the organiser was filed
            // and never applied, on every copy, with nothing on screen to say
            // why. Sharing a meeting is not correcting it.
            //
            // Compared against the event the editor was OPENED on rather than
            // against each copy, so the answer is the same for all of them:
            // "did this save change the meeting?" is one question, and a copy
            // created by this very save must be judged by it too — a new copy
            // carrying a title the user just typed is as edited as an old one.
            if (null !== $openedOnContent && $openedOnContent !== $this->contentOf($copy)) {
                $this->writer->markUserEdited($copy);
            }

            // After write(), so the event carries the calendar the mark is
            // decided against. Both are no-ops on a calendar that mirrors
            // nothing, which is why there is no branch here — see
            // CalendarEventWriter.
            true === $isNew
                ? $this->writer->markLocallyCreated($copy)
                : $this->writer->markLocallyChanged($copy);
        }

        $this->em->flush();

        // After the flush, so a row created a moment ago is one the worker can
        // read — and mapped rather than passed whole, because the sync question
        // is asked of the event's calendar and a copy that was created now has
        // one exactly like every other.
        $this->dispatchSync(array_map(
            static fn (EventCopy $target): CalendarEvent => $target->event,
            $targets,
        ));

        return $this->redirectAfterWrite($request, $landOn->format('Y-m-d'));
    }

    /**
     * A block put somewhere else on the time-grid — dragged, resized by its
     * edge, or nudged there with the keyboard.
     *
     * Its own route rather than a save with most of the fields missing. A drag
     * says two things and nothing else, so a route that accepts only those two
     * cannot silently blank a description or clear an all-day flag that the
     * grid had no field for; what stays the same is carried across by EventMover
     * rather than round-tripped through a form. The editor is still the way to
     * change anything else, and is still reachable from every block.
     *
     * The this-or-all question is answered exactly as the editor answers it,
     * with the same `scope` values and through the same two services — see
     * EventMover. The client asks it in a dialog before it submits, because a
     * drag that silently rebased a whole series would be the same defect the
     * editor already guards against, arrived at by an easier route.
     *
     * Every writable copy of a merged meeting moves, which is what the editor
     * does by default: it renders one checkbox per copy and ticks all of them.
     * A drag has no checkboxes to render, so the default is what it means. The
     * read-only ones are dropped by EventClusterer::chosen(), which is the same
     * refusal a crafted save gets — a disabled checkbox is a statement to a
     * browser, never a guarantee to a server.
     */
    #[Route('/event/move', name: 'event_move', methods: ['POST'])]
    public function eventMove(Request $request): Response
    {
        $this->assertCsrf($request, 'calendar_event_move');

        $user  = $this->currentUser();
        $event = $this->ownedEvent($request->request->getInt('eventId'));

        // The grid's own zone, not the event's. The block was dropped against
        // hour rows drawn on the clock CalendarRangeReader publishes, so the
        // wall time the client posts is a time on that clock — reading it in
        // the event's zone would move a meeting by the offset between them
        // without anything looking wrong.
        $zone = $this->time->safeZone($request->request->getString('timeZone')
            ?: $this->time->zoneFor($user)->getName());

        $startsAt = $this->time->parseDateTime($request->request->getString('startsAt'), $zone);
        $endsAt   = $this->time->parseDateTime($request->request->getString('endsAt'), $zone);

        if (null === $startsAt || null === $endsAt || $endsAt < $startsAt) {
            return $this->json(['error' => 'calendar.error.invalid_times'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $copies  = $this->clusterer->copiesOf($event, $user);
        $targets = $this->clusterer->chosen($copies, $this->everyCopy($copies));

        // Only reachable when every copy is on a read-only calendar, which the
        // grid refuses to start a drag on and says so. Refused rather than
        // performed as a no-op, because a redraw that put the block back where
        // it was would read as a UI that dropped the gesture.
        if ([] === $targets) {
            return $this->json(['error' => 'calendar.error.read_only'], Response::HTTP_FORBIDDEN);
        }

        $seriesScope = self::SCOPE_INSTANCE !== $request->request->getString('scope');

        foreach ($targets as $copy) {
            // Per copy, for the reason the save resolves it per copy: each is
            // its own series with its own occurrence rows, so the same drag is
            // the same patch filed once against each, keyed by that copy's own
            // occurrence at the same recurrence id.
            $this->mover->move(
                $copy,
                $this->instances->instance($copy, $request->request->getString('recurrenceId')),
                $seriesScope,
                $startsAt,
                $endsAt,
            );
        }

        $this->em->flush();

        $this->dispatchSync($targets);

        // The day the block was dropped on, not the series' new start. The user
        // is looking at that day, and this is only the fallback anyway — the
        // grid posts a returnTo that puts them back on the exact view they
        // dragged in.
        return $this->redirectAfterWrite($request, $startsAt->format('Y-m-d'));
    }

    /**
     * Delete the series, or take one occurrence off it.
     *
     * Submitted by the editor's own form through `formaction`, which is why the
     * token it carries is `_deleteToken` rather than `_token`: one form cannot
     * hold two fields of the same name, and the delete keeps a token of its own
     * bound to this event's id rather than borrowing the editor's.
     *
     * It reads the same ticks the save reads, and means by them the only thing
     * a delete can mean: the copies on the ticked calendars go, and the rest —
     * including every read-only one — stay exactly where they are. A ticked
     * calendar the meeting is not on yet has nothing to delete and is dropped by
     * EventCopyResolver::existing(); creating a row in order to remove it would
     * be absurd, and refusing the delete because a default tick happened to name
     * an empty calendar would be worse.
     */
    #[Route('/event/{id}/delete', name: 'event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function eventDelete(Request $request, CalendarEvent $event): Response
    {
        $this->assertOwned($event);
        $this->assertCsrf($request, 'calendar_event_delete' . $event->id, '_deleteToken');

        $date    = $request->request->getString('date') ?: (new DateTimeImmutable())->format('Y-m-d');
        $targets = $this->copies->existing($this->copies->chosen(
            $this->copies->optionsFor($event, $this->currentUser()),
            $request->request->all('calendars'),
        ));

        if ([] === $targets) {
            return $this->json(['error' => 'calendar.error.no_copies'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($targets as $copy) {
            // One occurrence off a series is an override, not a delete: the
            // series and every other instance of it stay exactly as they were,
            // so nothing here removes a row or marks the event for deletion at
            // the remote. Resolved per copy for the same reason the save
            // resolves it per copy — each is its own series.
            $instance = self::SCOPE_INSTANCE === $request->request->getString('scope')
                ? $this->instances->instance($copy, $request->request->getString('recurrenceId'))
                : null;

            if (null !== $instance) {
                $this->instances->cancel($copy, $instance);

                continue;
            }

            // A synced event is not removed here. The row is the only record
            // that the remote still holds a copy, so it survives — with its
            // occurrences dropped, which is what makes the deletion look
            // immediate — until the remote has been told. See
            // CalendarEventWriter::markLocallyDeleted().
            if (true === $this->writer->markLocallyDeleted($copy)) {
                $this->em->remove($copy);
            }
        }

        $this->em->flush();

        $this->dispatchSync($targets);

        return $this->redirectAfterWrite($request, $date);
    }

    /**
     * Delete, and do not accept it again.
     *
     * Its own action rather than a flag on delete, because the two mean
     * different things to everything downstream: a delete is "not any more",
     * a dismissal is "this was never an event". Only the second one is worth
     * remembering, and only extracted events have anything to remember it by
     * — see EventDismisser.
     */
    #[Route('/event/{id}/dismiss', name: 'event_dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function eventDismiss(Request $request, CalendarEvent $event): Response
    {
        $this->assertOwned($event);
        $this->assertCsrf($request, 'calendar_event_dismiss' . $event->id);

        // The button only renders for an extracted event, so reaching this
        // with a hand-made one is a crafted request and answered like one.
        if (false === $this->dismisser->canDismiss($event)) {
            throw $this->createAccessDeniedException();
        }

        $date = $request->request->getString('date') ?: (new DateTimeImmutable())->format('Y-m-d');

        $this->dismisser->dismiss($event, $this->currentUser());
        $this->em->flush();

        return $this->redirectAfterWrite($request, $date);
    }

    /**
     * Remembers which position the calendar switch is in and how wide the
     * docked pane is.
     *
     * Its own endpoint rather than a query parameter on the grid, because the
     * drag handle writes on release and must not re-render anything: a pane
     * that reloads while you are dragging it is unusable.
     */
    #[Route('/pane-state', name: 'pane_state', methods: ['POST'])]
    public function paneState(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'calendar_pane_state');

        $user = $this->currentUser();

        if (true === $request->request->has('mode')) {
            // Anything unrecognised keeps the mode the user is already in
            // rather than resetting it: this is posted by a controller, so a
            // value that is not one of the three is a bug or a forgery, and
            // neither is a reason to shut somebody's calendar.
            $user->calendarPaneMode = CalendarPaneMode::fromSetting(
                $request->request->getString('mode'),
                $user->calendarPaneMode,
            );
        }

        if (true === $request->request->has('width')) {
            // Clamped server-side too: the client's bounds are a convenience,
            // not a guarantee, and a stored 40000 would wedge the layout with
            // no way back short of the database.
            $user->setSetting(User::SETTING_CALENDAR_PANE_WIDTH, max(
                User::CALENDAR_PANE_MIN_WIDTH,
                min(User::CALENDAR_PANE_MAX_WIDTH, $request->request->getInt('width')),
            ));
        }

        $this->em->flush();

        return $this->json([
            'mode'  => $user->calendarPaneMode->value,
            'width' => $user->calendarPaneWidth,
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Back to whatever page the editor was opened from.
     *
     * The editor is a modal over two quite different pages — the calendar page,
     * and any mail view with the calendar docked beside it — and a redirect
     * that always went to the calendar would take a user who was reading their
     * mail away from it. So the form carries where it came from, and this is
     * the fallback when it did not.
     */
    private function redirectAfterWrite(Request $request, string $date): Response
    {
        $returnTo = $this->safeReturnTo($request->request->getString('returnTo'));

        if (null !== $returnTo) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('app_calendar_view', [
            'view' => $request->request->getString('view') ?: CalendarView::Week->value,
            'date' => $date,
        ]);
    }

    /**
     * The page the editor was opened over, reduced to a path.
     *
     * Taken from the Referer, which is a full URL, so the host is checked
     * against the one serving this request before anything is kept. Only the
     * path and query survive into the form.
     */
    private function returnTo(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');

        if ('' === $referer) {
            return '';
        }

        $parts = parse_url($referer);

        if (false === is_array($parts) || false === isset($parts['path'])) {
            return '';
        }

        if (true === isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return '';
        }

        return $parts['path'] . (true === isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * A path on this host, or nothing.
     *
     * Whatever went into the form comes back editable by whoever submits it, so
     * this is an open-redirect check and not a formality. `//evil.test` is the
     * case worth naming: a browser reads it as scheme-relative to another host,
     * and it passes a naive "starts with a slash" test.
     */
    private function safeReturnTo(string $candidate): ?string
    {
        if ('' === $candidate || false === str_starts_with($candidate, '/')) {
            return null;
        }

        if (true === str_starts_with($candidate, '//') || true === str_contains($candidate, '\\')) {
            return null;
        }

        return $candidate;
    }

    /**
     * $isPane no longer decides which layout day and week draw — the pane and
     * the page render the same grid, and _grid.html.twig says why. What it
     * still decides is which links belong inside the pane's Turbo Frame: the
     * month view's "N more" carries `?pane=1` so following it re-renders the
     * pane rather than navigating the whole page away from the mail. It is
     * passed from the actions rather than guessed in CSS, because the route
     * already knows which of the two it is rendering and a media query would be
     * asking the window a question only the layout can answer.
     *
     * Deliberately not called `compact`, which _toolbar reads for something
     * else: whether the view switcher is drawn as icons or as words.
     *
     * @return array<string,mixed>
     */
    private function viewData(
        Request            $request,
        CalendarView       $default,
        ?DateTimeImmutable $date = null,
        bool               $isPane = false,
    ): array {
        $user = $this->currentUser();

        // A user who has never had a calendar gets one here rather than an
        // empty page.
        $this->ensureCalendars($user);

        $view   = CalendarView::tryFrom($request->query->getString('view')) ?? $default;
        $anchor = $date ?? new DateTimeImmutable('today', $this->time->zoneFor($user));

        return [
            'view'      => $view,
            'anchor'    => $anchor,
            'isPane'    => $isPane,
            'calendars' => $this->calendars->findForUser($user),
            'range'     => $this->rangeReader->read($user, $view, $anchor),
        ];
    }

    /**
     * What this editor can change about a meeting, as one comparable value.
     *
     * Exactly the fields the form has a control for, and deliberately NOT the
     * whole JSCalendar object: the writer rebuilds that from the columns on
     * every save, so an object that arrived from an extractor and one derived
     * from the same values differ in key order and in keys no control can
     * reach — and a strict comparison of the two would call every save an
     * edit, which is the behaviour this replaced.
     *
     * Also not included: the calendar a copy is on, its sync state, its remote
     * id. Those are what a save legitimately changes when somebody ticks
     * another box, and treating them as content is the mistake itself.
     *
     * Alerts compare by key, sorted. jsonb returns an object's keys in its own
     * order, so the stored list and a freshly written one are the same set in
     * different orders more often than not.
     *
     * @return array<string, mixed>
     */
    private function contentOf(CalendarEvent $event): array
    {
        $alerts = $event->jscalendar['alerts'] ?? [];
        $keys   = is_array($alerts) ? array_map(strval(...), array_keys($alerts)) : [];

        sort($keys);

        return [
            'title'       => (string) $event->title,
            'startsAt'    => $event->startsAt?->format(DateTimeImmutable::ATOM),
            'endsAt'      => $event->endsAt?->format(DateTimeImmutable::ATOM),
            'timeZone'    => $event->timeZone,
            'isAllDay'    => $event->isAllDay,
            'location'    => (string) $event->location,
            'status'      => $event->status->value,
            'description' => (string) ($event->jscalendar['description'] ?? ''),
            'recurrence'  => $event->jscalendar['recurrenceRules'] ?? null,
            'alerts'      => $keys,
        ];
    }

    private function ownedEvent(int $id): CalendarEvent
    {
        $event = $this->em->getRepository(CalendarEvent::class)->find($id);

        if (null === $event) {
            throw $this->createNotFoundException();
        }

        $this->assertOwned($event);

        return $event;
    }

    /**
     * Whether any ticked calendar would gain a copy rather than have one
     * updated.
     *
     * Asked before the write loop rather than inside it, because the answer
     * decides whether the whole save happens: half a save, with the existing
     * copies patched and the new calendar quietly skipped, is the outcome worth
     * more than any of the fields.
     *
     * @param list<EventCopy> $targets
     */
    private function createsAnything(array $targets): bool
    {
        foreach ($targets as $target) {
            if (true === $target->isNew()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A user with no calendar at all gets one.
     *
     * The ticks are the only thing that says where an event goes now that the
     * dropdown is gone, so an empty list would make Save answer "nothing was
     * chosen" to a user who had nothing to choose. This is what eventSave()'s
     * old fallback from a missing `calendarId` to CalendarProvisioner did, kept
     * because the case it covers is real: provisioning covers new accounts and
     * the backfill covers old ones, and neither covers a user with no account
     * at all who opens the editor before ever opening the calendar.
     */
    private function ensureCalendars(User $user): void
    {
        if (0 === count($this->calendars->findForUser($user))) {
            $this->provisioner->defaultFor($user);
            $this->em->flush();
        }
    }

    /**
     * Every copy's id, so a drag can go through the same chosen() filter a
     * ticked-everything save goes through.
     *
     * Spelled out rather than short-circuited past chosen(): the read-only rule
     * lives in that method and a second path around it is how a mirror that
     * takes no writes would eventually be written to by one entry point and not
     * the other.
     *
     * @param non-empty-list<CalendarEvent> $copies
     *
     * @return list<int>
     */
    private function everyCopy(array $copies): array
    {
        $ids = [];

        foreach ($copies as $copy) {
            $ids[] = (int) $copy->id;
        }

        return $ids;
    }

    /**
     * Asks for a sync now rather than waiting for the sweep.
     *
     * Fifteen minutes is the right cadence for noticing what somebody else
     * changed and the wrong one for seeing your own edit arrive on your phone.
     * Dispatched after the flush, so the worker reads a committed row rather
     * than racing the transaction that made it.
     *
     * Silent on a calendar that mirrors nothing, which is most of them.
     *
     * Takes the whole set written, and dispatches at most one message per
     * calendar: a write to N copies is N rows on up to N calendars, and a
     * message per row would ask the same calendar to sync twice for one edit.
     *
     * @param list<CalendarEvent> $events
     */
    private function dispatchSync(array $events): void
    {
        $dispatched = [];

        foreach ($events as $event) {
            $calendar = $event->calendar;

            if (null === $calendar || false === $calendar->isSynced()) {
                continue;
            }

            $calendarId = (int) $calendar->id;

            if (true === in_array($calendarId, $dispatched, true)) {
                continue;
            }

            $dispatched[] = $calendarId;

            $this->bus->dispatch(new SyncCalendarMessage($calendarId));
        }
    }

    private function assertOwned(CalendarEvent $event): void
    {
        if ($event->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * $field is not always `_token`, because the editor is one form that submits
     * to two routes: the save's token and the delete's cannot both be called
     * `_token` inside it. See eventDelete().
     */
    private function assertCsrf(Request $request, string $id, string $field = '_token'): void
    {
        if (false === $this->isCsrfTokenValid($id, (string) $request->request->get($field))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
