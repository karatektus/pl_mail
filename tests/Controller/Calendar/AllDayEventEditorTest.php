<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Opening an all-day event in the editor, and ticking the box on a timed one.
 *
 * The reported symptom was in this template and nowhere else: **an all-day
 * invitation opened reading "02:00 – 02:00"**. An all-day event is floating —
 * its columns hold a wall date at midnight and it carries no zone at all — and
 * both halves of the editor treated it as an instant. The render converted it to
 * the reader's clock, which east of UTC adds the offset; the save read the
 * digits that came back in the posted zone, which subtracts it again, so the
 * round trip looked stable and was wrong on screen the whole time.
 *
 * Written as requests rather than against the controller, because the two halves
 * have to agree and only a request exercises both. The fixture reads in
 * Europe/Berlin because +2 is where the two hours in the bug report came from.
 *
 * Also here: ticking "all day" on an event that already has hours. That box sits
 * beside two datetime-local fields which keep whatever they were showing, so
 * without snapping, "all day" arrived meaning 09:00–10:00 — an event that claims
 * to be all day, is stored as a floating hour, and is drawn in the band as
 * though it filled the day.
 */
final class AllDayEventEditorTest extends WebTestCase
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

    /** The bug, as the user saw it. */
    public function testAnAllDayEventOpensAtMidnightRatherThanAtTheLocalOffset(): void
    {
        $client = $this->signIn();
        $event  = $this->allDay();

        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $this->day()->format('Y-m-d') . 'T00:00',
            $crawler->filter('input[name="startsAt"]')->attr('value'),
        );
        self::assertSame(
            $this->day()->modify('+1 day')->format('Y-m-d') . 'T00:00',
            $crawler->filter('input[name="endsAt"]')->attr('value'),
        );
    }

    /** And the box is ticked, so a save does not quietly turn it back into hours. */
    public function testTheAllDayBoxIsTicked(): void
    {
        $client = $this->signIn();
        $event  = $this->allDay();

        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        self::assertNotNull($crawler->filter('input[name="isAllDay"]')->attr('checked'));
    }

    /**
     * The round trip. Re-saving what the editor showed must leave the stored
     * columns exactly where they were — the failure mode being an event that
     * walks two hours further from midnight every time somebody fixes its
     * title.
     */
    public function testReSavingAnAllDayEventLeavesItWhereItWas(): void
    {
        $client = $this->signIn();
        $event  = $this->allDay();

        $this->save(
            $client,
            $event,
            $this->day()->format('Y-m-d') . 'T00:00',
            $this->day()->modify('+1 day')->format('Y-m-d') . 'T00:00',
            isAllDay: true,
        );

        $stored = $this->reload($event);

        self::assertSame($this->day()->format('Y-m-d') . ' 00:00', $stored->startsAt->format('Y-m-d H:i'));
        self::assertSame(
            $this->day()->modify('+1 day')->format('Y-m-d') . ' 00:00',
            $stored->endsAt->format('Y-m-d H:i'),
        );
        self::assertNull($stored->timeZone, 'a floating event must not acquire one on a save');
    }

    /**
     * Ticking the box on an event that has hours. The fields still hold 09:00
     * and 10:00; "all day" has to mean the whole day anyway, or the checkbox is
     * a label that changes nothing but the icon.
     */
    public function testTickingAllDaySnapsTheEventToWholeDays(): void
    {
        $client = $this->signIn();
        $event  = $this->timed();

        $this->save(
            $client,
            $event,
            $this->day()->format('Y-m-d') . 'T09:00',
            $this->day()->format('Y-m-d') . 'T10:00',
            isAllDay: true,
        );

        $stored = $this->reload($event);

        self::assertTrue($stored->isAllDay);
        self::assertSame($this->day()->format('Y-m-d') . ' 00:00', $stored->startsAt->format('Y-m-d H:i'));
        self::assertSame(
            $this->day()->modify('+1 day')->format('Y-m-d') . ' 00:00',
            $stored->endsAt->format('Y-m-d H:i'),
            'an all-day event that ends where it starts is a row no view can draw',
        );
    }

    /**
     * The control, and the reason the save cannot simply read everything in
     * UTC: a timed event is an instant, and its digits are the posted zone's.
     * 09:00 Berlin is 07:00 stored.
     */
    public function testATimedEventIsStillReadInThePostedZone(): void
    {
        $client = $this->signIn();
        $event  = $this->timed();

        $this->save(
            $client,
            $event,
            $this->day()->format('Y-m-d') . 'T09:00',
            $this->day()->format('Y-m-d') . 'T10:00',
            isAllDay: false,
            timeZone: 'Europe/Berlin',
        );

        $stored = $this->reload($event);

        self::assertFalse($stored->isAllDay);
        self::assertSame(
            $this->day()->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            $stored->startsAt->format('Y-m-d H:i'),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function save(
        KernelBrowser $client,
        CalendarEvent $event,
        string        $startsAt,
        string        $endsAt,
        bool          $isAllDay,
        string        $timeZone = 'Europe/Berlin',
    ): void {
        $payload = [
            '_token'    => $this->token($client),
            'eventId'   => $event->id,
            'calendars' => [(string) $this->calendar->id],
            'title'     => (string) $event->title,
            'timeZone'  => $timeZone,
            'startsAt'  => $startsAt,
            'endsAt'    => $endsAt,
        ];

        if (true === $isAllDay) {
            $payload['isAllDay'] = '1';
        }

        $client->request('POST', '/calendar/event/save', $payload);

        self::assertResponseRedirects();

        // Written through the ORM in another request; without this the
        // assertions read the instance this test still holds.
        $this->em->clear();
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/calendar/event/new')
            ->filter('form[action$="/calendar/event/save"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        $reloaded = $this->events->find((int) $event->id);

        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * The day everything here is on. Relative to the run rather than a literal
     * date, for the reason every calendar test here is: occurrences only exist
     * inside a horizon around now.
     */
    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 00:00', new DateTimeZone('Europe/Berlin'));
    }

    /** Floating, as the materialiser writes one: wall date at midnight, no zone. */
    private function allDay(): CalendarEvent
    {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($this->day()->format('Y-m-d') . ' 00:00', $utc);

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Public holiday',
            startsAt: $start,
            endsAt:   $start->modify('+1 day'),
            timeZone: null,
            isAllDay: true,
        );

        $this->em->flush();

        return $event;
    }

    private function timed(): CalendarEvent
    {
        $start = $this->day()->setTime(9, 0);

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Standup',
            startsAt: $start,
            endsAt:   $start->modify('+1 hour'),
            timeZone: 'Europe/Berlin',
        );

        $this->em->flush();

        return $event;
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
        $user->email     = 'alldayeditor-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'All';
        $user->nameLast  = 'Day';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        // The zone the editor prints on — TwigTimezoneSubscriber reads it off
        // the user, and +2 is where the two hours in the bug report came from.
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'All-day editor fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Berlin';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
