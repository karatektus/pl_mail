<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\CalendarView;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarPuller;
use App\Service\Calendar\CalendarRangeReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One meeting that lasts a weekend, ticked onto two calendars — and the six
 * chips that used to draw.
 *
 * This is one reported bug end to end, and every step of it was individually
 * correct, which is why nothing below can be checked at a smaller distance than
 * this:
 *
 *   An event from Friday afternoon to Sunday afternoon is ONE row with ONE
 *   occurrence spanning the whole of it. It is not three, and it is not a
 *   recurrence. CalendarRangeReader then places that one occurrence on every
 *   day it touches, deliberately — an event that only appeared on its first day
 *   would vanish from the week whose Monday it began before.
 *
 *   Ticking a second calendar writes a second row under the SAME UID, because a
 *   copy of a meeting is that meeting. EventClusterer merges them back into one
 *   chip per day on the strength of that shared UID, and it is the only thing it
 *   merges on.
 *
 *   And then the copy on a mirrored calendar is pushed to Google, which mints
 *   its OWN iCalUID and accepts none from us — `events.insert` has no field for
 *   one. The next pull writes that id over the row's UID, correctly, so the row
 *   stays matchable by every other client reading that calendar. What it also
 *   did was take the row out of its own cluster: the two copies no longer shared
 *   an identity, the merge stopped, and one weekend-long meeting became SIX
 *   chips — two on each of three days — that no later edit could put back
 *   together, because the editor's copy list is keyed on UID as well.
 *
 * Written as requests rather than against the services, because the claim
 * spans the editor's fan-out, the storage, the sync engine and what the month
 * grid finally draws — and the interesting failure is that each of those is
 * fine on its own.
 */
final class SpanningEventCopiesTest extends WebTestCase
{
    private const string TITLE = 'Konzert';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventRepository $events;
    private CalendarRangeReader $reader;
    private CalendarPuller $puller;
    private User $user;
    private Calendar $personal;
    private Calendar $mirror;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── What gets stored ──────────────────────────────────────────────────

    /**
     * The half of the report that turned out not to be a bug, pinned so it
     * cannot become one: a Friday-to-Sunday event is one spanning event, not
     * three short ones and not a series.
     */
    public function testAnEventEndingTwoDaysLaterIsStoredAsOneSpanningOccurrence(): void
    {
        $client = $this->signIn();

        $this->create($client, [$this->personal->id]);
        $this->extendToSunday($client);

        $rows = $this->rows();

        self::assertCount(1, $rows, 'one meeting, one row');

        $event = $rows[0];

        self::assertSame($this->friday()->format(DATE_ATOM), $event->startsAt?->format(DATE_ATOM));
        self::assertSame($this->sunday()->format(DATE_ATOM), $event->endsAt?->format(DATE_ATOM));
        self::assertFalse($event->isRecurring, 'moving the end date does not make a series');
        self::assertCount(1, $event->occurrences, 'one occurrence, covering the whole span');
    }

    /**
     * The per-day placement is correct and stays. Three chips for three days is
     * what a weekend-long meeting looks like on a month grid — the defect was
     * never the count of days.
     */
    public function testASpanningEventIsDrawnOnEveryDayItTouches(): void
    {
        $client = $this->signIn();

        $this->create($client, [$this->personal->id]);
        $this->extendToSunday($client);

        self::assertSame([1, 1, 1], $this->chipsPerDay(), 'one chip on each of the three days');
    }

    // ── The bug ───────────────────────────────────────────────────────────

    /**
     * The report, reproduced: two calendars ticked, the mirrored copy re-keyed
     * by the provider, and a month grid that used to answer with six chips.
     */
    public function testACopyTheProviderRekeysStaysTheSameMeetingAsItsSiblings(): void
    {
        $client = $this->signIn();

        $this->create($client, [$this->personal->id, $this->mirror->id]);
        $this->extendToSunday($client);

        self::assertCount(2, $this->rows(), 'one meeting on two calendars');
        self::assertSame([1, 1, 1], $this->chipsPerDay(), 'and one chip a day while they share a UID');

        $this->rekeyTheMirrorAtTheProvider('google-minted-1@google.com');

        self::assertSame(
            ['google-minted-1@google.com', 'google-minted-1@google.com'],
            array_map(static fn (CalendarEvent $event): string => $event->uid, $this->rows()),
            'the copy the provider never saw follows the UID it gave the one it did',
        );

        self::assertSame(
            [1, 1, 1],
            $this->chipsPerDay(),
            'still one meeting: the re-key must not split a weekend into six chips',
        );
    }

    /**
     * The limit of the rule above, and the reason it has one: a copy that has
     * a remote id of its own is identified at ITS provider by the UID it holds,
     * so re-keying it here would make it unmatchable there — the exact harm the
     * re-key exists to avoid, done to somebody else's row.
     */
    public function testACopyWithARemoteOfItsOwnIsNeverRekeyedBySomebodyElsesProvider(): void
    {
        $client = $this->signIn();

        $this->create($client, [$this->personal->id, $this->mirror->id]);

        // The copy on the local calendar is given a remote of its own, as a
        // second mirrored calendar's row would have.
        $personal             = $this->rowOn($this->personal);
        $personal->remoteId   = 'caldav-href-1';
        $personal->remoteEtag = '"a"';
        $this->em->flush();

        $was = $personal->uid;

        $this->rekeyTheMirrorAtTheProvider('google-minted-2@google.com');

        self::assertSame(
            $was,
            $this->rowOn($this->personal)->uid,
            'a row another remote already identifies keeps the identity that remote knows it by',
        );
    }

    // ── The editor's list ─────────────────────────────────────────────────

    /**
     * Two local calendars mirroring one remote calendar are one destination.
     * Offered, so the list stays true; disabled, so it cannot be ticked; and
     * refused again on the server, because a disabled checkbox is a statement
     * to a browser and never a guarantee.
     */
    public function testASecondMirrorOfOneRemoteCalendarIsOfferedAndRefused(): void
    {
        $client = $this->signIn();

        $twin           = $this->seedCalendar($this->user, 'Shared calendar', '#db2777', CalendarRole::Remote);
        $twin->remoteId = $this->mirror->remoteId;
        $this->em->flush();

        $this->create($client, [$this->personal->id]);

        $crawler = $client->request('GET', '/calendar/event/' . $this->rows()[0]->id . '/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[name="calendars[]"]'), 'every calendar is still offered');

        self::assertCount(
            1,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][disabled]', $twin->id)),
            'the second mirror of one remote calendar cannot be ticked',
        );

        self::assertCount(
            0,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][disabled]', $this->mirror->id)),
            'and the first one still can',
        );

        $this->save($client, $this->rows()[0], [$this->personal->id, $twin->id]);

        self::assertNull(
            $this->events->findOneBy(['calendar' => $twin]),
            'a crafted post naming it writes nothing: one meeting must not reach one provider calendar twice',
        );
    }

    // ── What the month grid prints ────────────────────────────────────────

    /**
     * A day the meeting only RUNS THROUGH does not print the hour it started
     * at on a different day. Three cells reading "15:00 Konzert" is what the
     * report called "3 1h events", and it was the grid's own wording that said
     * so.
     */
    public function testAContinuationDayDoesNotPrintTheStartTimeOfTheDayItBegan(): void
    {
        $client = $this->signIn();

        $this->create($client, [$this->personal->id]);
        $this->extendToSunday($client);

        $crawler = $client->request('GET', '/calendar/month/' . $this->friday()->format('Y-m-d'));

        self::assertResponseIsSuccessful();

        // Read off the chip's own title, which is built from exactly the same
        // showTime decision as the text inside it — and is a string rather than
        // a Tailwind class, so this asserts about what the chip SAYS rather
        // than about how it is laid out.
        $label = static fn (DateTimeImmutable $day): string => (string) $crawler
            ->filter(sprintf('[data-day="%s"] button[title]', $day->format('Y-m-d')))
            ->first()
            ->attr('title');

        // The separator rather than the digits: the hour is printed on the
        // reader's clock format, and this claim is about whether a time is
        // there at all rather than about how it is spelled.
        self::assertStringStartsWith(
            self::TITLE . ' · ',
            $label($this->friday()),
            'the day it begins prints when it begins',
        );

        self::assertSame(
            self::TITLE,
            $label($this->friday()->modify('+1 day')),
            'the day after says the meeting is on, and does not claim it starts again',
        );

        self::assertSame(
            self::TITLE,
            $label($this->friday()->modify('+2 days')),
            'nor does the day it finally ends on',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The meeting, created through the editor the way a browser creates one —
     * no event id, and a tick per calendar it is to land on.
     *
     * @param list<int|null> $calendars
     */
    private function create(KernelBrowser $client, array $calendars): void
    {
        $crawler = $client->request('GET', '/calendar/event/new');

        $client->request('POST', '/calendar/event/save', [
            '_token'    => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'eventId'   => '0',
            'title'     => self::TITLE,
            'timeZone'  => 'UTC',
            'startsAt'  => $this->friday()->format('Y-m-d\TH:i'),
            'endsAt'    => $this->friday()->modify('+1 hour')->format('Y-m-d\TH:i'),
            'view'      => CalendarView::Month->value,
            'calendars' => array_map(strval(...), $calendars),
        ]);

        self::assertResponseRedirects();
    }

    /**
     * The gesture the report describes: reopen the meeting and move only its
     * end date, leaving every box the editor ticked as it found it.
     */
    private function extendToSunday(KernelBrowser $client): void
    {
        $event   = $this->rows()[0];
        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        $client->request('POST', '/calendar/event/save', [
            '_token'    => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'eventId'   => (string) $event->id,
            'title'     => self::TITLE,
            'timeZone'  => 'UTC',
            'startsAt'  => $this->friday()->format('Y-m-d\TH:i'),
            'endsAt'    => $this->sunday()->format('Y-m-d\TH:i'),
            'view'      => CalendarView::Month->value,
            'calendars' => $crawler->filter('input[name="calendars[]"][checked]')->each(
                static fn ($node): string => (string) $node->attr('value'),
            ),
        ]);

        self::assertResponseRedirects();
    }

    /** @param list<int|null> $calendars */
    private function save(KernelBrowser $client, CalendarEvent $event, array $calendars): void
    {
        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        $client->request('POST', '/calendar/event/save', [
            '_token'    => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'eventId'   => (string) $event->id,
            'title'     => self::TITLE,
            'timeZone'  => 'UTC',
            'startsAt'  => $this->friday()->format('Y-m-d\TH:i'),
            'endsAt'    => $this->friday()->modify('+1 hour')->format('Y-m-d\TH:i'),
            'view'      => CalendarView::Month->value,
            'calendars' => array_map(strval(...), $calendars),
        ]);
    }

    /**
     * What Google does to an event plMail created: it comes back carrying an
     * iCalUID of Google's own and an etag that has moved, so the pull writes
     * rather than skipping.
     *
     * The row is given the remote id and etag the push would have stored first,
     * because that is the state this window is answering — a create that has
     * already gone out.
     */
    private function rekeyTheMirrorAtTheProvider(string $uid): void
    {
        $mirrored = $this->rowOn($this->mirror);

        $mirrored->remoteId   = 'google-event-1';
        $mirrored->remoteEtag = '"1"';
        $this->em->flush();

        $this->puller->apply(
            $this->managed($this->mirror),
            new CalendarChangeSet([
                new RemoteEvent(
                    remoteId:   'google-event-1',
                    etag:       '"2"',
                    uid:        $uid,
                    jscalendar: $mirrored->jscalendar,
                    startsAt:   $mirrored->startsAt,
                    endsAt:     $mirrored->endsAt,
                ),
            ], 'sync-token-2'),
        );

        $this->em->flush();
    }

    /**
     * The chips on the three days the meeting touches, in order.
     *
     * Through the real reader rather than by counting rows: the merge is a
     * read-time grouping, and the only way to be sure it happened is to ask the
     * thing that does it.
     *
     * @return list<int>
     */
    private function chipsPerDay(): array
    {
        $range = $this->reader->read($this->managedUser(), CalendarView::Month, $this->friday());

        $counts = [];

        foreach ([0, 1, 2] as $offset) {
            $key      = $this->friday()->modify(sprintf('+%d days', $offset))->format('Y-m-d');
            $counts[] = count(array_filter(
                $range['days'][$key] ?? [],
                static fn (OccurrenceCluster $cluster): bool => self::TITLE === $cluster->primary->event?->title,
            ));
        }

        return $counts;
    }

    /**
     * The meeting's rows, oldest first.
     *
     * Re-read on every call and never cached in a property: each request
     * through the browser resets the container's services, which detaches
     * whatever the last call was holding.
     *
     * @return list<CalendarEvent>
     */
    private function rows(): array
    {
        return $this->events->findBy(['usr' => $this->managedUser()], ['id' => 'ASC']);
    }

    private function rowOn(Calendar $calendar): CalendarEvent
    {
        $event = $this->events->findOneBy(['calendar' => $this->managed($calendar)]);

        self::assertNotNull($event);

        return $event;
    }

    /** @param Calendar $calendar a row this test seeded, possibly since detached */
    private function managed(Calendar $calendar): Calendar
    {
        $managed = $this->em->find(Calendar::class, $calendar->id);

        self::assertNotNull($managed);

        return $managed;
    }

    private function managedUser(): User
    {
        $user = $this->em->find(User::class, $this->user->id);

        self::assertNotNull($user);

        return $user;
    }

    /**
     * Relative to the run rather than a literal date: occurrences are only
     * materialised inside a horizon around now, so a fixed weekend is a suite
     * that passes until that weekend leaves the window.
     */
    private function friday(): DateTimeImmutable
    {
        return new DateTimeImmutable('next friday 15:00', new DateTimeZone('UTC'));
    }

    private function sunday(): DateTimeImmutable
    {
        return $this->friday()->modify('+2 days')->setTime(16, 0);
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->events     = $container->get(CalendarEventRepository::class);
        $this->reader     = $container->get(CalendarRangeReader::class);
        $this->puller     = $container->get(CalendarPuller::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'spanning-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Spanning';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $this->personal = $this->seedCalendar($user, 'Personal', '#2563eb', CalendarRole::Default, isDefault: true);

        // Named after the address on purpose: a Google account's primary
        // calendar is called the account's own address by Google, and the
        // calendar plMail provisions for that mail account is named the same
        // thing. That is what "but both calendars are my gmail calendar" meant.
        $this->mirror           = $this->seedCalendar($user, 'me@gmail.com', '#16a34a', CalendarRole::Remote);
        $this->mirror->remoteId = 'me@gmail.com';

        $this->em->flush();

        $this->user = $user;
        $client->loginUser($user);

        return $client;
    }

    private function seedCalendar(
        User         $user,
        string       $name,
        string       $color,
        CalendarRole $role,
        bool         $isDefault = false,
    ): Calendar {
        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = $name;
        $calendar->color     = $color;
        $calendar->role      = $role;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = $isDefault;

        $this->em->persist($calendar);

        return $calendar;
    }
}
