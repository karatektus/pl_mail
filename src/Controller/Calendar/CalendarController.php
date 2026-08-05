<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarView;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\CalendarRangeReader;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\EventClusterer;
use App\Service\Calendar\EventDismisser;
use App\Service\Calendar\EventInstanceEditor;
use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
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
 * It is also opened on ONE EVENT and may act on several. A meeting that reached
 * plMail twice — extracted from its invitation onto the account's calendar, and
 * mirrored from the provider onto a connected one — is two rows sharing the
 * organiser's UID, drawn as one chip. The editor re-derives the whole set from
 * that UID (EventClusterer::copiesOf) rather than trusting a cluster identity
 * threaded through a URL, offers a checkbox per copy, and a save or a delete is
 * then N ordinary writes through CalendarEventWriter — each marked for push by
 * the same means a lone event is, because there is no second push path and
 * inventing one is how the two would drift. The loops below are delegation:
 * what "the same meeting" means lives in EventClusterer, and what a write means
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
        private readonly EventClusterer         $clusterer,
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
        return $this->render('calendar/_pane_frame.html.twig', $this->viewData($request, CalendarView::Agenda));
    }

    /** One view at one date. The grid frame re-renders into itself. */
    #[Route('/{view}/{date}', name: 'view', requirements: ['view' => 'day|week|month|agenda'], methods: ['GET'])]
    public function view(Request $request, string $view, ?string $date = null): Response
    {
        $data = $this->viewData(
            $request,
            CalendarView::from($view),
            null === $date ? null : $this->time->parseDate($date, $this->time->zoneFor($this->currentUser())),
        );

        return $this->render(
            true === $request->query->getBoolean('pane')
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

        return $this->render('calendar/_event_modal.html.twig', [
            'event'        => null,
            'calendars'    => $this->calendars->findForUser($user),
            // Nothing exists to have copies of yet, so the editor renders its
            // calendar dropdown rather than a list of one checkbox.
            'members'      => [],
            'title'        => '',
            'startsAt'     => $startsAt,
            'endsAt'       => $startsAt->modify('+1 hour'),
            'timeZone'     => $zone->getName(),
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
     * offer a checkbox per copy. Any member's values would do for the fields —
     * a cluster of several exists only while its members agree about all of
     * them — so the opened event's are shown, which is also what makes a
     * merged chip open the same editor a lone one does.
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
            'calendars'    => $this->calendars->findForUser($this->currentUser()),
            'members'      => $this->clusterer->copiesOf($event, $this->currentUser()),
            'title'        => null === $instance ? (string) $event->title : $this->instances->titleOf($event, $instance),
            'startsAt'     => $startsAt->setTimezone($zone),
            'endsAt'       => $endsAt->setTimezone($zone),
            'timeZone'     => $event->timeZone ?? $this->time->zoneFor($this->currentUser())->getName(),
            'recurrenceId' => $this->instances->identify($instance),
            'returnTo'     => $this->returnTo($request),
        ]);
    }

    #[Route('/event/save', name: 'event_save', methods: ['POST'])]
    public function eventSave(Request $request): Response
    {
        $this->assertCsrf($request, 'calendar_event');

        $user     = $this->currentUser();
        $eventId  = $request->request->getInt('eventId');
        $event    = 0 === $eventId ? new CalendarEvent() : $this->ownedEvent($eventId);
        $calendar = $this->calendars->findOneForUser($user, $request->request->getInt('calendarId'))
            ?? $this->provisioner->defaultFor($user);

        $zoneName = $request->request->getString('timeZone') ?: $this->time->zoneFor($user)->getName();
        $zone     = $this->time->safeZone($zoneName);
        $isAllDay = $request->request->getBoolean('isAllDay');

        $startsAt = $this->time->parseDateTime($request->request->getString('startsAt'), $zone);
        $endsAt   = $this->time->parseDateTime($request->request->getString('endsAt'), $zone);

        if (null === $startsAt || null === $endsAt || $endsAt < $startsAt) {
            return $this->json(['error' => 'calendar.error.invalid_times'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $copies  = $this->clusterer->copiesOf($event, $user);
        $targets = $this->targets($request, $event, $copies);

        // Every copy unticked is an edit with nowhere to go. Refused rather
        // than performed silently, because a save that redraws the calendar
        // unchanged reads as a save that did not work.
        if ([] === $targets) {
            return $this->json(['error' => 'calendar.error.no_copies'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Where the user is put down afterwards. Read off the first copy
        // written rather than off the posted field, because "all events" from
        // an editor opened on one occurrence rebases the series — and any copy
        // gives the same answer, since a cluster of several exists only while
        // its members agree about when they are.
        $landOn = $startsAt;

        // N calendars is N ordinary writes, each marked for push by the same
        // means a lone event is. A read-only copy never reaches this loop:
        // targets() drops it whatever the request claimed.
        foreach ($targets as $index => $copy) {
            $this->writer->markUserEdited($copy);

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

            $instance = self::SCOPE_INSTANCE === $request->request->getString('scope')
                ? $openedOn
                : null;

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
                $this->instances->edit(
                    $copy,
                    $instance,
                    $request->request->getString('title') ?: 'Untitled',
                    $copyStart,
                    $copyEnd,
                );

                continue;
            }

            // Before write(), which is what assigns the calendar: on a new event
            // there is nothing to read the "is this synced?" question off yet,
            // and on an existing one the answer must be taken from where the
            // event is now rather than from where it is being moved to.
            $isNew = null === $copy->id;

            $this->writer->write(
                event:          $copy,
                // The copy's OWN calendar once there is more than one of them.
                // The editor replaces its calendar dropdown with the checkbox
                // list for a merged chip, so there is no destination to honour
                // — and honouring one would move every copy of the meeting onto
                // a single calendar, collapsing rows that each hold their own
                // remoteId, etag and sync state.
                calendar:       1 < count($copies) ? ($copy->calendar ?? $calendar) : $calendar,
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
            );

            // After write(), so the event carries the calendar the mark is
            // decided against. Both are no-ops on a calendar that mirrors
            // nothing, which is why there is no branch here — see
            // CalendarEventWriter.
            true === $isNew
                ? $this->writer->markLocallyCreated($copy)
                : $this->writer->markLocallyChanged($copy);
        }

        $this->em->flush();

        $this->dispatchSync($targets);

        return $this->redirectAfterWrite($request, $landOn->format('Y-m-d'));
    }

    /**
     * Delete the series, or take one occurrence off it.
     *
     * Submitted by the editor's own form through `formaction`, which is why the
     * token it carries is `_deleteToken` rather than `_token`: one form cannot
     * hold two fields of the same name, and the delete keeps a token of its own
     * bound to this event's id rather than borrowing the editor's.
     *
     * It follows the same rule as the save: it removes the copies that are
     * ticked, and leaves the rest — including every read-only one — exactly
     * where they are.
     */
    #[Route('/event/{id}/delete', name: 'event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function eventDelete(Request $request, CalendarEvent $event): Response
    {
        $this->assertOwned($event);
        $this->assertCsrf($request, 'calendar_event_delete' . $event->id, '_deleteToken');

        $date    = $request->request->getString('date') ?: (new DateTimeImmutable())->format('Y-m-d');
        $copies  = $this->clusterer->copiesOf($event, $this->currentUser());
        $targets = $this->targets($request, $event, $copies);

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
     * Remembers the docked pane's width and whether it is open.
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

        if (true === $request->request->has('open')) {
            $user->setSetting(User::SETTING_CALENDAR_PANE_OPEN, $request->request->getBoolean('open'));
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
            'open'  => $user->isCalendarPaneOpen(),
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
     * @return array<string,mixed>
     */
    private function viewData(Request $request, CalendarView $default, ?DateTimeImmutable $date = null): array
    {
        $user = $this->currentUser();

        // A user who has never had a calendar gets one here rather than an
        // empty page: provisioning covers new accounts and the backfill covers
        // old ones, but neither covers a user who has no account at all.
        if (0 === count($this->calendars->findForUser($user))) {
            $this->provisioner->defaultFor($user);
            $this->em->flush();
        }

        $view   = CalendarView::tryFrom($request->query->getString('view')) ?? $default;
        $anchor = $date ?? new DateTimeImmutable('today', $this->time->zoneFor($user));

        return [
            'view'      => $view,
            'anchor'    => $anchor,
            'calendars' => $this->calendars->findForUser($user),
            'range'     => $this->rangeReader->read($user, $view, $anchor),
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
     * Which copies of this meeting a save or a delete is for.
     *
     * An ordinary event is a cluster of one and posts no checkboxes at all, so
     * it answers itself — the editor showed a calendar dropdown and the old
     * behaviour is what it means. Only a merged chip renders `copies[]`, and
     * only then does the answer come from what the user ticked.
     *
     * Deliberately not "everything when nothing was ticked". That fallback
     * reads as helpful and is the one that writes every copy of a meeting after
     * a user has explicitly cleared the list.
     *
     * @param non-empty-list<CalendarEvent> $copies
     *
     * @return list<CalendarEvent>
     */
    private function targets(Request $request, CalendarEvent $event, array $copies): array
    {
        if (1 === count($copies)) {
            return [$event];
        }

        return $this->clusterer->chosen($copies, $request->request->all('copies'));
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
