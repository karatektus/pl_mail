<?php

declare(strict_types=1);

namespace App\Tests\Controller\CalDav;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A CalDAV client can find its way from the root to an event, over HTTP, with
 * nothing but an app password.
 *
 * Discovery is a chain and every link is a separate request: the root names the
 * principal, the principal names the calendar-home-set, the home lists the
 * collections, the collection lists its resources. A client that loses the
 * thread at any point shows an empty account and no error, so the value of this
 * test is that it walks the whole chain the way Akonadi and DAVx5 do rather
 * than asserting each endpoint in isolation.
 *
 * The colour and the name are asserted because they are the point of doing this
 * over CalDAV at all: an ICS feed can carry the events, and only a collection
 * per calendar carries the sidebar the user already has in plMail.
 */
final class CalDavDiscoveryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private string $secret;
    private Calendar $calendar;
    private CalendarEvent $event;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnUnauthenticatedRequestIsChallengedRatherThanServed(): void
    {
        $client = $this->boot();

        $client->request('PROPFIND', '/caldav/');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testTheRootNamesThePrincipal(): void
    {
        $client = $this->boot();

        $body = $this->dav($client, 'PROPFIND', '/caldav/');

        self::assertStringContainsString('current-user-principal', $body);
        self::assertStringContainsString(
            sprintf('/caldav/principals/%d/', $this->user->id),
            $body,
            'the root must point at this user and no other',
        );
    }

    public function testThePrincipalNamesTheCalendarHome(): void
    {
        $client = $this->boot();

        $body = $this->dav($client, 'PROPFIND', sprintf('/caldav/principals/%d/', $this->user->id));

        self::assertStringContainsString('calendar-home-set', $body);
        self::assertStringContainsString(sprintf('/caldav/calendars/%d/', $this->user->id), $body);
    }

    /** The sidebar: one collection per calendar, with its name and its colour. */
    public function testTheHomeListsEachCalendarWithItsNameAndColour(): void
    {
        $client = $this->boot();

        $body = $this->dav($client, 'PROPFIND', sprintf('/caldav/calendars/%d/', $this->user->id), '1');

        self::assertStringContainsString($this->paths(), $body, 'the collection must be listed');
        self::assertStringContainsString('Work', $body);
        self::assertStringContainsString('#3366ff', $body, 'the colour is why this is CalDAV and not an ICS feed');
        self::assertStringContainsString('sync-token', $body, 'without one, every sync is a full read');
    }

    public function testACollectionListsItsResourcesWithEtags(): void
    {
        $client = $this->boot();

        $body = $this->dav($client, 'PROPFIND', $this->paths(), '1');

        self::assertStringContainsString(rawurlencode($this->event->uid) . '.ics', $body);
        self::assertStringContainsString('getetag', $body);
    }

    public function testAResourceIsServedAsICalendar(): void
    {
        $client = $this->boot();

        $client->request(
            'GET',
            $this->paths() . rawurlencode($this->event->uid) . '.ics',
            [],
            [],
            ['HTTP_AUTHORIZATION' => $this->basic()],
        );

        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/calendar', (string) $response->headers->get('Content-Type'));
        self::assertNotNull($response->headers->get('ETag'), 'without an ETag a client refetches everything forever');

        $body = (string) $response->getContent();

        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('BEGIN:VEVENT', $body);
        self::assertStringContainsString($this->event->uid, $body);
    }

    /** Another user's calendar is not found, never forbidden — the same rule Calendar/get follows. */
    public function testAnotherUsersCalendarIsNotFound(): void
    {
        $client = $this->boot();

        $client->request(
            'PROPFIND',
            sprintf('/caldav/calendars/%d/', $this->user->id + 9999),
            [],
            [],
            ['HTTP_AUTHORIZATION' => $this->basic()],
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------- helpers

    private function dav(KernelBrowser $client, string $method, string $path, string $depth = '0'): string
    {
        $client->request($method, $path, [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'HTTP_DEPTH'         => $depth,
        ]);

        self::assertSame(207, $client->getResponse()->getStatusCode(), $method . ' ' . $path . ' should be multistatus');

        return (string) $client->getResponse()->getContent();
    }

    private function basic(): string
    {
        return 'Basic ' . base64_encode($this->user->email . ':' . $this->secret);
    }

    private function paths(): string
    {
        return sprintf('/caldav/calendars/%d/%d/', $this->user->id, $this->calendar->id);
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->seed($container->get(CalendarEventWriter::class));

        return $client;
    }

    private function seed(CalendarEventWriter $writer): void
    {
        $user            = new User();
        $user->email     = 'caldav-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cal';
        $user->nameLast  = 'Dav';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $minted       = ApiToken::create($user, 'CalDAV test');
        $this->secret = $minted['secret'];
        $this->em->persist($minted['token']);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Work';
        $calendar->color    = '#3366ff';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);
        $this->em->flush();

        $event      = new CalendarEvent();
        $event->uid = 'caldav-test-' . uniqid('', true) . '@plmail.test';

        $writer->write(
            event: $event,
            calendar: $calendar,
            user: $user,
            title: 'Kickoff',
            startsAt: new DateTimeImmutable('+3 days 09:00'),
            endsAt: new DateTimeImmutable('+3 days 10:00'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->event    = $event;
    }
}
