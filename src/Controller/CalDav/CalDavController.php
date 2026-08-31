<?php

declare(strict_types=1);

namespace App\Controller\CalDav;

use App\Entity\Calendar\Calendar;
use App\Domain\Exception\CalendarStateTokenException;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarChangeLogRepository;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Change\CalendarChangeReader;
use App\Service\Calendar\Dav\DavEtag;
use App\Service\Calendar\Dav\DavPaths;
use App\Service\Calendar\Dav\DavReportReader;
use App\Service\Calendar\Dav\DavReportRequest;
use App\Service\Calendar\Dav\MultiStatusBuilder;
use App\Domain\Exception\CalendarSyncException;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\Ics\IcsExporter;
use App\Service\Calendar\Ics\IcsImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use XMLWriter;

/**
 * The CalDAV server: plMail's calendars, to any client that speaks the protocol.
 *
 * Written rather than delegated to sabre/dav, which was tried first and
 * reverted. Its current release still requires sabre/xml ^2.0.1 while sabre/xml
 * is on 4.x, so installing it downgraded sabre/xml and sabre/uri underneath
 * sabre/vobject — the library every ICS path in this codebase is built on — and
 * closed off vobject 5.0, which needs the newer xml. The suite passed with the
 * downgrade; the cost was a dependency floor five years old under the most
 * load-bearing library here, permanently.
 *
 * Doing it by hand is less daunting than it sounds because most of it already
 * existed for the other direction. CalDavEventConverter maps an event to and
 * from a VEVENT in 981 lines, MultiStatusParser reads the document this writes,
 * and CalendarChangeReader answers the only hard question — what changed since
 * a token — because that was built first, for JMAP, and CalDAV counts in the
 * same numbers.
 *
 * ── What is deliberately not here ─────────────────────────────────────────
 *
 * No scheduling (calendar-auto-schedule) and no free/busy. plMail already does
 * iTIP its own way through ItipReplyBuilder and InviteResponder; a second
 * system that also sent invitations would double every RSVP. Not advertising
 * them is the supported way to say so, and clients degrade to sending
 * invitations themselves.
 *
 * ── Read and write are separate concerns ──────────────────────────────────
 *
 * This controller answers discovery and reads. Writes — PUT and DELETE — go
 * through the same CalendarEventWriter the web editor uses, and a read-only
 * calendar refuses them, exactly as IcsImporter does.
 */
#[Route('/caldav', name: 'app_caldav_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalDavController extends AbstractController
{
    /** What this server can do, in the header every client reads first. */
    private const string DAV_COMPLIANCE = '1, 3, calendar-access';

    public function __construct(
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventRepository $events,
        private readonly CalendarChangeLogRepository $log,
        private readonly CalendarChangeReader $changes,
        private readonly IcsExporter $exporter,
        private readonly DavPaths $paths,
        private readonly DavEtag $etags,
        private readonly DavReportReader $reports,
        private readonly IcsImporter $importer,
        private readonly CalendarEventWriter $writer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Every client starts here, and a few never send anything else if the
     * answer is wrong. Allow must list the methods this server really answers:
     * a client that sees no REPORT falls back to polling every resource.
     */
    #[Route('{path}', name: 'options', requirements: ['path' => '.*'], methods: ['OPTIONS'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT, [
            'DAV'   => self::DAV_COMPLIANCE,
            'Allow' => 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, REPORT',
        ]);
    }

    /**
     * PROPFIND, which is discovery and listing both.
     *
     * Depth decides which: 0 is "tell me about this thing", 1 is "and its
     * children". Depth infinity is refused — RFC 4918 allows a server to, and a
     * client that asks for it on a calendar home is asking for every event in
     * every collection in one document.
     */
    #[Route('/', name: 'propfind_root', methods: ['PROPFIND'])]
    public function propfindRoot(Request $request): Response
    {
        $user = $this->currentUser();

        return $this->multiStatus(
            (new MultiStatusBuilder())->response($this->paths->root(), [
                'd:resourcetype'           => static fn (XMLWriter $w): mixed => $w->writeElement('d:collection'),
                'd:current-user-principal' => fn (XMLWriter $w): mixed => $w->writeElement(
                    'd:href',
                    $this->paths->principal($user),
                ),
            ])
        );
    }

    /**
     * The principal. Its whole job is to say where the calendars are — a client
     * reads calendar-home-set here and never comes back.
     */
    #[Route('/principals/{userId}/', name: 'propfind_principal', requirements: ['userId' => '\d+'], methods: ['PROPFIND'])]
    public function propfindPrincipal(int $userId): Response
    {
        $user = $this->currentUser();

        // Another user's principal is not found rather than forbidden: telling
        // a stranger an id exists is the same leak Calendar/get avoids.
        if ($userId !== $user->id) {
            throw $this->createNotFoundException();
        }

        return $this->multiStatus(
            (new MultiStatusBuilder())->response($this->paths->principal($user), [
                'd:resourcetype'      => static fn (XMLWriter $w): mixed => $w->writeElement('d:principal'),
                'd:displayname'       => $user->nameFirst . ' ' . $user->nameLast,
                'c:calendar-home-set' => fn (XMLWriter $w): mixed => $w->writeElement(
                    'd:href',
                    $this->paths->home($user),
                ),
                'd:principal-URL'     => fn (XMLWriter $w): mixed => $w->writeElement(
                    'd:href',
                    $this->paths->principal($user),
                ),
            ])
        );
    }

    /**
     * The calendar-home-set: one response for the home itself, then one per
     * collection when Depth is 1. This is where a client learns the names and
     * colours it will draw in its sidebar.
     */
    #[Route('/calendars/{userId}/', name: 'propfind_home', requirements: ['userId' => '\d+'], methods: ['PROPFIND'])]
    public function propfindHome(Request $request, int $userId): Response
    {
        $user = $this->currentUser();

        if ($userId !== $user->id) {
            throw $this->createNotFoundException();
        }

        $builder = (new MultiStatusBuilder())->response($this->paths->home($user), [
            'd:resourcetype' => static fn (XMLWriter $w): mixed => $w->writeElement('d:collection'),
            'd:displayname'  => 'Calendars',
        ]);

        if ('0' !== $request->headers->get('Depth', '0')) {
            foreach ($this->calendars->findForUser($user) as $calendar) {
                $builder->response($this->paths->collection($calendar), $this->collectionProperties($calendar));
            }
        }

        return $this->multiStatus($builder);
    }

    /**
     * One collection, and its resources when Depth is 1.
     *
     * The listing carries an ETag per resource and nothing else by default —
     * not the calendar data. A client decides from the ETags what it actually
     * needs and asks for those with calendar-multiget, which is one request
     * instead of one per changed event.
     */
    #[Route('/calendars/{userId}/{calendarId}/', name: 'propfind_collection', requirements: ['userId' => '\d+', 'calendarId' => '\d+'], methods: ['PROPFIND'])]
    public function propfindCollection(Request $request, int $userId, int $calendarId): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->ownedCalendar($user, $userId, $calendarId);

        $builder = (new MultiStatusBuilder())
            ->response($this->paths->collection($calendar), $this->collectionProperties($calendar));

        if ('0' !== $request->headers->get('Depth', '0')) {
            $sequences = $this->log->latestSequencesForCalendar($calendarId);

            foreach ($this->events->findBy(['calendar' => $calendar]) as $event) {
                $builder->response($this->paths->resource($calendar, $event), [
                    'd:getetag'        => $this->etags->for($event, $sequences[$event->id] ?? null),
                    'd:getcontenttype' => 'text/calendar; charset=utf-8; component=vevent',
                    'd:resourcetype'   => true,
                ]);
            }
        }

        return $this->multiStatus($builder);
    }

    /** One event, as the iCalendar a client stores. */
    #[Route('/calendars/{userId}/{calendarId}/{name}', name: 'get_resource', requirements: ['userId' => '\d+', 'calendarId' => '\d+', 'name' => '.+\.ics'], methods: ['GET', 'HEAD'])]
    public function getResource(int $userId, int $calendarId, string $name): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->ownedCalendar($user, $userId, $calendarId);
        $event    = $this->resourceOf($calendar, $name);

        $sequences = $this->log->latestSequencesForCalendar($calendarId);

        return new Response($this->exporter->one($event), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'ETag'         => $this->etags->for($event, $sequences[$event->id] ?? null),
        ]);
    }

    /**
     * REPORT: three questions at one URL, told apart by the root element.
     *
     * sync-collection is the one that matters. Everything else here is a client
     * asking for resources it has already decided it wants; this is the client
     * asking what it should want, and answering it from calendar_change_log
     * means a sync costs one request and the rows that actually moved, rather
     * than a listing of every event in the collection on every poll.
     */
    #[Route('/calendars/{userId}/{calendarId}/', name: 'report', requirements: ['userId' => '\d+', 'calendarId' => '\d+'], methods: ['REPORT'])]
    public function report(Request $request, int $userId, int $calendarId): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->ownedCalendar($user, $userId, $calendarId);

        $report = $this->reports->read($request->getContent());

        if (null === $report) {
            return new Response('Unsupported report.', Response::HTTP_BAD_REQUEST);
        }

        return match ($report->type) {
            DavReportRequest::SYNC_COLLECTION => $this->syncCollection($calendar, $report),
            DavReportRequest::MULTIGET        => $this->multiget($calendar, $report),
            default                           => $this->calendarQuery($calendar, $report),
        };
    }

    /**
     * What changed since a token (RFC 6578).
     *
     * A destroyed resource comes back as an href and a bare 404 with no
     * propstat — that is how the client is told to forget something it still
     * holds, and it is the reason the change log copies the UID onto the
     * tombstone: by now there is no row left to read it from.
     *
     * An unusable token answers 403 with valid-sync-token rather than an empty
     * delta. "Nothing changed" to a client whose token we cannot place would
     * leave it stale with no way to notice; the 403 sends it back for a full
     * read, which is correct and self-healing.
     */
    private function syncCollection(Calendar $calendar, DavReportRequest $report): Response
    {
        try {
            $delta = $this->changes->sinceForCalendar(
                (int) $calendar->id,
                $report->syncToken ?? '0',
                $report->limit,
            );
        } catch (CalendarStateTokenException) {
            return new Response(
                '<?xml version="1.0" encoding="utf-8"?>' . "\n"
                . '<d:error xmlns:d="DAV:"><d:valid-sync-token/></d:error>',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'application/xml; charset=utf-8'],
            );
        }

        $builder   = new MultiStatusBuilder();
        $sequences = $this->log->latestSequencesForCalendar((int) $calendar->id);

        $live = $delta->created + $delta->updated;

        foreach ($this->events->findBy(['id' => array_keys($live)]) as $event) {
            $builder->response(
                $this->paths->resource($calendar, $event),
                $this->resourceProperties($event, $sequences[$event->id] ?? null, $report->wantsCalendarData),
            );
        }

        foreach ($delta->destroyed as $uid) {
            $builder->status(
                $this->paths->collection($calendar) . $this->paths->resourceName($uid),
                'HTTP/1.1 404 Not Found',
            );
        }

        return $this->multiStatus($builder->syncToken($delta->newState));
    }

    /** These exact resources, by href — one request instead of one per event. */
    private function multiget(Calendar $calendar, DavReportRequest $report): Response
    {
        $builder   = new MultiStatusBuilder();
        $sequences = $this->log->latestSequencesForCalendar((int) $calendar->id);

        foreach ($report->hrefs as $href) {
            $uid   = $this->paths->uidFromName(basename($href));
            $event = $this->events->findOneBy(['calendar' => $calendar, 'uid' => $uid]);

            // An href the client still holds for something deleted meanwhile is
            // a 404 in the multistatus, not an error for the whole report.
            if (null === $event) {
                $builder->status($href, 'HTTP/1.1 404 Not Found');

                continue;
            }

            $builder->response(
                $this->paths->resource($calendar, $event),
                $this->resourceProperties($event, $sequences[$event->id] ?? null, $report->wantsCalendarData),
            );
        }

        return $this->multiStatus($builder);
    }

    /**
     * The resources matching a filter, which in practice is always a time range
     * — a client showing a month asks for that month.
     *
     * Matched on the series' own bounds rather than on expanded occurrences. A
     * recurring event is included whenever its recurrence could still reach the
     * window, which over-answers for a series whose rule skips it and never
     * under-answers. Over-answering costs the client a resource it draws
     * nothing for; under-answering loses an appointment from somebody's week,
     * and the two are not the same kind of wrong.
     */
    private function calendarQuery(Calendar $calendar, DavReportRequest $report): Response
    {
        $builder   = new MultiStatusBuilder();
        $sequences = $this->log->latestSequencesForCalendar((int) $calendar->id);

        foreach ($this->events->findBy(['calendar' => $calendar]) as $event) {
            if (false === $this->withinRange($event, $report)) {
                continue;
            }

            $builder->response(
                $this->paths->resource($calendar, $event),
                $this->resourceProperties($event, $sequences[$event->id] ?? null, $report->wantsCalendarData),
            );
        }

        return $this->multiStatus($builder);
    }

    private function withinRange(CalendarEvent $event, DavReportRequest $report): bool
    {
        if (null === $report->rangeStart && null === $report->rangeEnd) {
            return true;
        }

        if (null !== $report->rangeEnd && null !== $event->startsAt && $event->startsAt >= $report->rangeEnd) {
            // A series can still reach the window from before it, unless it has
            // already stopped repeating.
            if (false === $event->isRecurring) {
                return false;
            }
        }

        if (null !== $report->rangeStart && null !== $event->endsAt && $event->endsAt <= $report->rangeStart) {
            if (false === $event->isRecurring) {
                return false;
            }

            if (null !== $event->recurrenceUntil && $event->recurrenceUntil <= $report->rangeStart) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,string|true|\Closure(XMLWriter):mixed>
     */
    private function resourceProperties(CalendarEvent $event, ?int $sequence, bool $withData): array
    {
        $properties = [
            'd:getetag'        => $this->etags->for($event, $sequence),
            'd:getcontenttype' => 'text/calendar; charset=utf-8; component=vevent',
        ];

        if (true === $withData) {
            $properties['c:calendar-data'] = $this->exporter->one($event);
        }

        return $properties;
    }

    /**
     * PUT: a client storing an event.
     *
     * Goes through IcsImporter, which is the same door the web UI's "import a
     * .ics" uses. That is deliberate rather than convenient — it already parses
     * the document, maps it with the converter the sync driver uses, and
     * refuses a read-only mirror outright. A second implementation here would
     * be a second answer to "what does this VEVENT mean", and the two would
     * disagree the first time either learned something.
     *
     * If-Match is the lost-update guard and is checked before anything is
     * written: a client that read version A and PUTs while somebody else has
     * written B must be told, not silently allowed to erase B. A request
     * without the header is allowed through, because a client that never read
     * the resource cannot be overwriting a version it did not see.
     */
    #[Route('/calendars/{userId}/{calendarId}/{name}', name: 'put_resource', requirements: ['userId' => '\d+', 'calendarId' => '\d+', 'name' => '.+\.ics'], methods: ['PUT'])]
    public function putResource(Request $request, int $userId, int $calendarId, string $name): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->ownedCalendar($user, $userId, $calendarId);
        $uid      = $this->paths->uidFromName($name);

        $existing = $this->events->findOneBy(['calendar' => $calendar, 'uid' => $uid]);

        if (null !== $existing) {
            $sequences = $this->log->latestSequencesForCalendar($calendarId);
            $current   = $this->etags->for($existing, $sequences[$existing->id] ?? null);

            if (false === $this->etags->matches($request->headers->get('If-Match'), $current)) {
                return new Response('', Response::HTTP_PRECONDITION_FAILED);
            }
        }

        try {
            $this->importer->import($calendar, $user, $request->getContent());
        } catch (CalendarSyncException $e) {
            // A read-only mirror answers 403 — the client should stop offering
            // edits — and an unreadable document 400, which is the client's bug
            // rather than a permission problem.
            $status = true === $calendar->isReadOnly
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_BAD_REQUEST;

            return new Response($e->getMessage(), $status);
        }

        $this->em->flush();

        $stored = $this->events->findOneBy(['calendar' => $calendar, 'uid' => $uid]);

        if (null === $stored) {
            // The document parsed but named a different UID than the href. The
            // resource the client asked to create does not exist, and saying so
            // is better than a 201 for something it cannot then fetch.
            return new Response('The calendar data does not carry the UID this resource is named by.', Response::HTTP_CONFLICT);
        }

        $sequences = $this->log->latestSequencesForCalendar($calendarId);

        return new Response('', null === $existing ? Response::HTTP_CREATED : Response::HTTP_NO_CONTENT, [
            'ETag' => $this->etags->for($stored, $sequences[$stored->id] ?? null),
        ]);
    }

    /**
     * DELETE, through the writer rather than straight to the EntityManager.
     *
     * markLocallyDeleted() is what decides whether the row may go now or has to
     * survive until the remote it mirrors has been told — returning false means
     * "this is the pusher's problem". Removing the entity regardless would drop
     * the only record that a deletion is still owed to a provider.
     */
    #[Route('/calendars/{userId}/{calendarId}/{name}', name: 'delete_resource', requirements: ['userId' => '\d+', 'calendarId' => '\d+', 'name' => '.+\.ics'], methods: ['DELETE'])]
    public function deleteResource(Request $request, int $userId, int $calendarId, string $name): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->ownedCalendar($user, $userId, $calendarId);

        if (true === $calendar->isReadOnly) {
            return new Response('This calendar is a read-only mirror.', Response::HTTP_FORBIDDEN);
        }

        $event     = $this->resourceOf($calendar, $name);
        $sequences = $this->log->latestSequencesForCalendar($calendarId);
        $current   = $this->etags->for($event, $sequences[$event->id] ?? null);

        if (false === $this->etags->matches($request->headers->get('If-Match'), $current)) {
            return new Response('', Response::HTTP_PRECONDITION_FAILED);
        }

        if (true === $this->writer->markLocallyDeleted($event)) {
            $this->em->remove($event);
        }

        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    // ------------------------------------------------------------- helpers --

    /**
     * The properties that describe a collection.
     *
     * calendar-color and calendar-order are Apple's, not the CalDAV standard's,
     * and are what Akonadi, Thunderbird, DAVx5 and iOS all read — the sidebar
     * this produces is the one plMail draws, colours included.
     *
     * The sync-token is the change log's sequence for this collection, which is
     * the same number CalendarEvent/changes counts in. getctag is the older
     * calendarserver property meaning "has anything here changed"; clients that
     * predate sync-collection poll it, and it costs one more line to keep them
     * working.
     *
     * @return array<string,string|true|\Closure(XMLWriter):mixed>
     */
    private function collectionProperties(Calendar $calendar): array
    {
        $token = $this->changes->stateForCalendar((int) $calendar->id);

        return [
            'd:resourcetype' => function (XMLWriter $w): void {
                $w->writeElement('d:collection');
                $w->writeElement('c:calendar');
            },
            'd:displayname'                  => $calendar->name,
            'ical:calendar-color'            => $calendar->color,
            'ical:calendar-order'            => (string) $calendar->sortOrder,
            'c:calendar-timezone-id'         => $calendar->timeZone,
            'cs:getctag'                     => $token,
            'd:sync-token'                   => $token,
            'c:supported-calendar-component-set' => function (XMLWriter $w): void {
                $w->startElement('c:comp');
                $w->writeAttribute('name', 'VEVENT');
                $w->endElement();
            },
            'd:supported-report-set' => function (XMLWriter $w): void {
                foreach (['sync-collection', 'calendar-query', 'calendar-multiget'] as $report) {
                    $w->startElement('d:supported-report');
                    $w->startElement('d:report');
                    $w->writeElement('sync-collection' === $report ? 'd:' . $report : 'c:' . $report);
                    $w->endElement();
                    $w->endElement();
                }
            },
            // Read-only mirrors must say so, or a client offers edits that
            // IcsImporter would refuse and the user never learns why.
            'd:current-user-privilege-set' => function (XMLWriter $w) use ($calendar): void {
                $privileges = true === $calendar->isReadOnly
                    ? ['d:read']
                    : ['d:read', 'd:write', 'd:write-content', 'd:bind', 'd:unbind'];

                foreach ($privileges as $privilege) {
                    $w->startElement('d:privilege');
                    $w->writeElement($privilege);
                    $w->endElement();
                }
            },
        ];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function ownedCalendar(User $user, int $userId, int $calendarId): Calendar
    {
        if ($userId !== $user->id) {
            throw $this->createNotFoundException();
        }

        $calendar = $this->calendars->findOneForUser($user, $calendarId);

        if (null === $calendar) {
            throw $this->createNotFoundException();
        }

        return $calendar;
    }

    private function resourceOf(Calendar $calendar, string $name): CalendarEvent
    {
        $event = $this->events->findOneBy([
            'calendar' => $calendar,
            'uid'      => $this->paths->uidFromName($name),
        ]);

        if (null === $event) {
            throw $this->createNotFoundException();
        }

        return $event;
    }

    private function multiStatus(MultiStatusBuilder $builder): Response
    {
        return new Response($builder->toXml(), 207, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'DAV'          => self::DAV_COMPLIANCE,
        ]);
    }
}
