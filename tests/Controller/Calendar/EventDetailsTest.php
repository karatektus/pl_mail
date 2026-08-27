<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reading an event without being asked to change it.
 *
 * The only way to look at an event used to be the editor, so answering "when is
 * this and where did it come from?" meant opening a form — and the three things
 * people most often want are not on that form at all. The description and the
 * participants live in the JSCalendar overlay, which the form does not edit;
 * the provenance is a link the form has never carried.
 *
 * These assert on what the panel SAYS rather than on its markup, because the
 * point of it is the reading.
 */
final class EventDetailsTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItShowsWhatTheEventSaysWithoutAnyInputs(): void
    {
        $client = $this->signIn();
        $event  = $this->event();

        $crawler = $client->request('GET', '/calendar/event/'.$event->id.'/details');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sprint review', $crawler->filter('h2')->text());
        self::assertStringContainsString('Room 3', $crawler->text());

        // Not a form. That is the request, in as many words: a read-only view
        // that asks for nothing.
        self::assertCount(0, $crawler->filter('form'));
        self::assertCount(0, $crawler->filter('input[name], textarea, select'));
    }

    /**
     * The overlay fields, which are the reason this view exists — the editor
     * cannot show them because it does not edit them.
     */
    public function testItShowsTheDescriptionAndParticipantsFromTheOverlay(): void
    {
        $client = $this->signIn();
        $event  = $this->event();

        $crawler = $client->request('GET', '/calendar/event/'.$event->id.'/details');
        $text    = $crawler->text();

        self::assertStringContainsString('Bring the numbers', $text);
        self::assertStringContainsString('Priya Raman', $text);
        self::assertStringContainsString('jonas@example.com', $text);
    }

    /** Edit is still reachable — one step in, rather than being the front door. */
    public function testEditIsOneStepAway(): void
    {
        $client = $this->signIn();
        $event  = $this->event();

        $crawler = $client->request('GET', '/calendar/event/'.$event->id.'/details');

        self::assertGreaterThan(
            0,
            $crawler->filter('a[href*="/edit"]')->count(),
            'the details panel must still lead to the editor',
        );
    }

    /**
     * An event nobody extracted has no provenance, and renders without the
     * block rather than with an empty one — the same rule the happening-soon
     * panel follows, where a link to a message that is not there is worse than
     * no link.
     */
    public function testAHandMadeEventShowsNoProvenance(): void
    {
        $client = $this->signIn();
        $event  = $this->event();

        $crawler = $client->request('GET', '/calendar/event/'.$event->id.'/details');

        self::assertCount(0, $crawler->filter('a[data-turbo-frame="_top"]'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A weekend-long event says so, at BOTH ends.
     *
     * It printed the start day, the start time and the end time — "Friday 28
     * August · 15:00 – 16:00" for a festival running to Sunday afternoon. Every
     * component of that is a real value, which is why it read as correct: it is
     * an hour on the wrong scale, and the grid beside it drew the span properly
     * the whole time, so the two disagreed with each other in the same window.
     */
    public function testAnEventRunningOverDaysNamesBothOfThem(): void
    {
        $client = $this->signIn();

        $start = new DateTimeImmutable('2026-08-28 15:00', new DateTimeZone('Europe/Berlin'));

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Konglo Festival',
            startsAt: $start,
            // Friday afternoon to Sunday afternoon.
            endsAt:   $start->modify('+2 days')->modify('+1 hour'),
            timeZone: 'Europe/Berlin',
            isAllDay: false,
            location: 'Burg Herzberg',
        );

        $this->em->flush();

        $line = $client->request('GET', '/calendar/event/' . $event->id . '/details')
            ->filter('h2 + p')->first()->text();

        self::assertStringContainsString('August 28', $line, 'the day it starts');
        self::assertStringContainsString('August 30', $line, 'the day it ends — this was missing');

        // The shape that made it look right: one date carrying two times.
        // The shape that made the bug look right: ONE day carrying two times.
        // Both day names present is the claim; how they are spelled is a setting.
        self::assertSame(
            2,
            preg_match_all('/August (28|30)/u', $line),
            'both days have to be named — naming one and two times reads as an hour',
        );
    }

    private function event(): CalendarEvent
    {
        $start = new DateTimeImmutable('2026-09-10 09:00', new DateTimeZone('Europe/Berlin'));

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Sprint review',
            startsAt: $start,
            endsAt:   $start->modify('+1 hour'),
            timeZone: 'Europe/Berlin',
            isAllDay: false,
            location: 'Room 3',
            jscalendarOverlay: [
                'description'  => "Bring the numbers.\nAnd the good chairs.",
                'participants' => [
                    'priya' => ['name' => 'Priya Raman', 'email' => 'priya@example.com'],
                    'jonas' => ['email' => 'jonas@example.com'],
                ],
            ],
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

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'eventdetails-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Event';
        $user->nameLast  = 'Reader';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Details fixture';
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
