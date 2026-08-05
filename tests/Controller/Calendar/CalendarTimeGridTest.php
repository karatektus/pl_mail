<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The time-grid is on the page and not in the pane, and a block dropped on it
 * writes through the same services a form save writes through.
 *
 * Two claims, and both of them are things a browser spec can only see the
 * surface of:
 *
 *   **Which layout a request gets is decided by the route, not by CSS.** The
 *   pane and the page ask for the same view of the same week and get different
 *   markup, because a 380px column shared with the mail cannot hold a time
 *   gutter and seven columns of positioned blocks. Decided in a media query the
 *   pane would draw a grid on a wide monitor — it is narrow there too — and
 *   both layouts would be rendered on every calendar request so that one could
 *   be hidden.
 *
 *   **A drag is a write, and everything a write owes is owed here too.** The
 *   route is new and the ways it can be wrong are the ways every write endpoint
 *   can be wrong and no test would notice: it can accept a request with no
 *   token, it can move somebody else's event, it can write a mirror that
 *   accepts no writes back, and it can change the database without queuing the
 *   push — which on a synced calendar is not a lost change but a reverted one,
 *   because the next pull writes the remote's old time back over it.
 *
 * Written as requests rather than against the controller directly, because the
 * token, the form the grid renders it into and the markup the next render
 * produces are all part of what is being claimed.
 */
final class CalendarTimeGridTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private CalendarEventRepository $events;
    private User $user;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Which layout ──────────────────────────────────────────────────────

    public function testTheWeekOnThePageIsATimeGridWithAnAllDayRowAboveIt(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/week');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-controller="calendar--time-grid"]'),
            'the page draws the grid',
        );
        self::assertCount(
            7,
            $crawler->filter('[data-calendar--time-grid-target="column"]'),
            'one column per day of the week',
        );
        self::assertStringContainsString(
            'All day',
            $crawler->filter('[data-controller="calendar--time-grid"]')->text(),
            'all-day events get a row of their own rather than a block at midnight',
        );
    }

    /**
     * The pane asks for the same week and gets the column list. Asserted on the
     * absence of the grid as well as the presence of the list: a pane that
     * rendered both and hid one would pass a check for only the second.
     */
    public function testTheSameWeekInThePaneKeepsTheColumnListInstead(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/week?pane=1');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-controller="calendar--time-grid"]'),
            'a 380px pane has no business drawing a time-grid',
        );
        self::assertCount(1, $crawler->filter('turbo-frame#calendar-pane-frame'));
    }

    /** Month has no time axis to draw, on the page or anywhere else. */
    public function testTheMonthIsNeverATimeGrid(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/month');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="calendar--time-grid"]'));
    }

    /**
     * A block carries its times on the GRID's clock, because that is the clock
     * the hour rows are labelled with and the one the move route reads the
     * answer back in. Rendered in the event's own zone instead, a drag would
     * move the event by the offset between the two and nothing would look wrong.
     */
    public function testABlockCarriesItsTimesOnTheClockTheGridIsDrawnIn(): void
    {
        $client = $this->signIn();

        $this->calendar->timeZone = 'Europe/Berlin';
        $this->em->flush();

        $event = $this->oneOff();

        $crawler = $client->request('GET', '/calendar/day/' . $this->start()->format('Y-m-d'));
        $block   = $crawler->filter(sprintf('[data-event-id="%d"]', $event->id));

        self::assertCount(1, $block, 'the event is on the grid');
        self::assertSame(
            $this->start()->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i:s'),
            $block->attr('data-starts-at'),
        );
    }

    /**
     * A block whose calendar takes no writes says so on the block itself,
     * before anyone tries to drag it — the refusal on the drop is the second
     * line, not the first.
     */
    public function testABlockOnAReadOnlyCalendarIsMarkedAsUnmovable(): void
    {
        $client = $this->signIn();

        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $event = $this->oneOff();

        $crawler = $client->request('GET', '/calendar/day/' . $this->start()->format('Y-m-d'));

        self::assertSame(
            'true',
            $crawler->filter(sprintf('[data-event-id="%d"]', $event->id))->attr('data-read-only'),
        );
    }

    // ── The move ──────────────────────────────────────────────────────────

    public function testDroppingABlockSomewhereElseMovesTheEventThere(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $moved = $this->start()->modify('+3 hours');

        $client->request('POST', '/calendar/event/move', [
            '_token'   => $this->token($client),
            'eventId'  => $event->id,
            'scope'    => 'series',
            'timeZone' => 'UTC',
            'startsAt' => $moved->format('Y-m-d\TH:i:s'),
            'endsAt'   => $moved->modify('+1 hour')->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseRedirects();
        self::assertSame($moved->format(DATE_ATOM), $this->reload($event)->startsAt->format(DATE_ATOM));
    }

    /**
     * The this-or-all question, answered "this one". The grid posts the
     * occurrence's own times, and only that occurrence may move.
     */
    public function testDroppingOneOccurrenceOfASeriesMovesOnlyThatOne(): void
    {
        $client = $this->signIn();
        $event  = $this->weeklySeries();

        $second = $this->instanceAt($event, 1);

        $client->request('POST', '/calendar/event/move', [
            '_token'       => $this->token($client),
            'eventId'      => $event->id,
            'recurrenceId' => $second->recurrenceId?->format('Y-m-d\TH:i:s\Z'),
            'scope'        => 'instance',
            'timeZone'     => 'UTC',
            'startsAt'     => $second->startsAt?->modify('+2 hours')->format('Y-m-d\TH:i:s'),
            'endsAt'       => $second->endsAt?->modify('+2 hours')->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseRedirects();

        $reloaded = $this->reload($event);

        self::assertSame('11:00', $this->localStart($reloaded, 1), 'the one that was dragged moved');
        self::assertSame('09:00', $this->localStart($reloaded, 2), 'and its siblings did not');
        self::assertSame(
            $this->start()->format(DATE_ATOM),
            $reloaded->startsAt->format(DATE_ATOM),
            'the series itself is exactly where it was',
        );
    }

    /**
     * The other answer, and the bug it guards. The grid posts the OCCURRENCE's
     * times, so a route that wrote them as the series' own would rebase the
     * whole series onto the day of whichever block was dragged — a weekly
     * meeting quietly moved a week on, with nothing having failed.
     */
    public function testDroppingASeriesFromALaterOccurrenceShiftsItRatherThanRebasingIt(): void
    {
        $client = $this->signIn();
        $event  = $this->weeklySeries();

        $fourth = $this->instanceAt($event, 3);

        $client->request('POST', '/calendar/event/move', [
            '_token'       => $this->token($client),
            'eventId'      => $event->id,
            'recurrenceId' => $fourth->recurrenceId?->format('Y-m-d\TH:i:s\Z'),
            'scope'        => 'series',
            'timeZone'     => 'UTC',
            'startsAt'     => $fourth->startsAt?->modify('+2 hours')->format('Y-m-d\TH:i:s'),
            'endsAt'       => $fourth->endsAt?->modify('+2 hours')->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseRedirects();

        self::assertSame(
            $this->start()->modify('+2 hours')->format(DATE_ATOM),
            $this->reload($event)->startsAt->format(DATE_ATOM),
            'the series keeps its day and gains the two hours the drag added',
        );
    }

    // ── What it refuses ───────────────────────────────────────────────────

    /**
     * A disabled control is a statement to a browser, never a guarantee to a
     * server. The grid will not start a drag on a read-only block; this is what
     * happens when something else does.
     */
    public function testAMoveOnAReadOnlyCalendarIsRefusedRatherThanPerformed(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();
        $token  = $this->token($client);

        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $client->request('POST', '/calendar/event/move', [
            '_token'   => $token,
            'eventId'  => $event->id,
            'scope'    => 'series',
            'timeZone' => 'UTC',
            'startsAt' => $this->start()->modify('+3 hours')->format('Y-m-d\TH:i:s'),
            'endsAt'   => $this->start()->modify('+4 hours')->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(
            $this->start()->format(DATE_ATOM),
            $this->reload($event)->startsAt->format(DATE_ATOM),
            'the event stayed exactly where it was',
        );
    }

    public function testAMoveWithNoTokenWritesNothing(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $client->request('POST', '/calendar/event/move', [
            'eventId'  => $event->id,
            'scope'    => 'series',
            'timeZone' => 'UTC',
            'startsAt' => $this->start()->modify('+3 hours')->format('Y-m-d\TH:i:s'),
            'endsAt'   => $this->start()->modify('+4 hours')->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($this->start()->format(DATE_ATOM), $this->reload($event)->startsAt->format(DATE_ATOM));
    }

    /** An end before its start is not a resize, it is a request to refuse. */
    public function testAnEndBeforeItsStartIsRefused(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $client->request('POST', '/calendar/event/move', [
            '_token'   => $this->token($client),
            'eventId'  => $event->id,
            'scope'    => 'series',
            'timeZone' => 'UTC',
            'startsAt' => $this->start()->modify('+3 hours')->format('Y-m-d\TH:i:s'),
            'endsAt'   => $this->start()->format('Y-m-d\TH:i:s'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($this->start()->format(DATE_ATOM), $this->reload($event)->startsAt->format(DATE_ATOM));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The token as the grid renders it, read out of the move form.
     *
     * Minted through the token manager instead it would be a token for a
     * session this test happens to hold rather than the one the page was
     * rendered into, which the same-origin manager rejects — correctly, and
     * confusingly.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/calendar/week');

        return (string) $crawler
            ->filter('form[action$="/calendar/event/move"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    /**
     * Relative to the run, not a literal date: RecurrenceMaterialiser only
     * writes occurrences inside a horizon around now, so a fixed year is a
     * suite that passes until that year leaves the window. Monday so a weekly
     * series is eight distinct days whichever day this runs on.
     */
    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 09:00', new DateTimeZone('UTC'));
    }

    private function oneOff(): CalendarEvent
    {
        return $this->seedEvent(null);
    }

    private function weeklySeries(): CalendarEvent
    {
        return $this->seedEvent(['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8]);
    }

    /** @param array<string,mixed>|null $recurrenceRule */
    private function seedEvent(?array $recurrenceRule): CalendarEvent
    {
        $event = $this->writer->write(
            event:          new CalendarEvent(),
            calendar:       $this->calendar,
            user:           $this->user,
            title:          'Standup',
            startsAt:       $this->start(),
            endsAt:         $this->start()->modify('+30 minutes'),
            timeZone:       'UTC',
            recurrenceRule: $recurrenceRule,
        );

        $this->em->flush();

        return $event;
    }

    /**
     * The nth instance, named by its recurrence id — where the rule put it, not
     * where it is now. Ordering on the current start would renumber the series
     * the moment one instance was moved past another.
     */
    private function instanceAt(CalendarEvent $event, int $index): CalendarEventOccurrence
    {
        $wanted = $this->start()->modify(sprintf('+%d weeks', $index))->format(DATE_ATOM);

        foreach ($event->occurrences as $occurrence) {
            if ($occurrence->recurrenceId?->format(DATE_ATOM) === $wanted) {
                return $occurrence;
            }
        }

        self::fail(sprintf('the series should have an instance %d weeks in', $index));
    }

    private function localStart(CalendarEvent $event, int $index): string
    {
        return (string) $this->instanceAt($event, $index)->startsAt?->format('H:i');
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        $this->em->clear();

        $reloaded = $this->events->find($event->id);

        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->events     = $container->get(CalendarEventRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'timegrid-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Grid';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Grid fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
