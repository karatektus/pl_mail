<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Integration\IntegrationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The three doors iCalendar goes through, driven as requests.
 *
 * Written at this level rather than against the services because half of what
 * has to hold is not in a method body: the Content-Disposition that decides
 * whether a browser downloads or renders, the streamed body that never becomes
 * one string, the CSRF token, the calendars the import picker is willing to
 * offer, and the fact that a feed subscription produces a *read-only* calendar
 * whatever the form said.
 *
 * The feed's transport is scripted. The address is a hostname rather than an
 * address literal on purpose — IntegrationUrlValidator lets a hostname through
 * without resolving it (it says so, and says why), so the SSRF guard behaves in
 * the test exactly as it does in production and nothing reaches the network.
 *
 * The subscribe flow has no browser spec covering its *success* path, and that
 * is a limitation worth naming here rather than discovering later: every address
 * the end-to-end stack can actually reach is loopback or a private range, which
 * the SSRF guard refuses — correctly. This test is what stands in for it.
 */
final class IcsControllerTest extends WebTestCase
{
    /** Not an address literal: see the class docblock. Nothing resolves it. */
    private const string FEED_URL = 'https://feeds.example.test/holidays.ics';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventRepository $events;
    private CalendarRepository $calendars;
    private IntegrationRepository $integrations;
    private User $user;
    private Calendar $calendar;
    private MockHttpClient $http;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function testAnEventDownloadsAsACalendarFileRatherThanRenderingAsAPage(): void
    {
        $client = $this->signIn();
        $event  = $this->seedEvent('standup@plmail', 'Standup');

        $client->request('GET', '/calendar/ics/event/' . $event->id);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('standup.ics', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('UID:standup@plmail', $body);
    }

    /** Somebody else's event is not theirs to download. */
    public function testAnEventBelongingToSomebodyElseIsRefused(): void
    {
        $client = $this->signIn();
        $event  = $this->seedEvent('standup@plmail', 'Standup');

        $event->usr = $this->otherUser();
        $this->em->flush();

        $client->request('GET', '/calendar/ics/event/' . $event->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAWholeCalendarStreamsAsOneDocument(): void
    {
        $client = $this->signIn();

        $this->seedEvent('standup@plmail', 'Standup');
        $this->seedEvent('retro@plmail', 'Retro');

        $client->request('GET', '/calendar/ics/calendar/' . $this->calendar->id);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertInstanceOf(StreamedResponse::class, $response, 'a decade of a calendar must not become one string');
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');

        // Read off the BrowserKit response rather than by calling
        // sendContent() again: a StreamedResponse may only be sent once, and
        // the client has already drained it into the internal response.
        $body = (string) $client->getInternalResponse()->getContent();

        self::assertSame(1, substr_count($body, 'BEGIN:VCALENDAR'));
        self::assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
        self::assertStringContainsString('UID:standup@plmail', $body);
        self::assertStringContainsString('UID:retro@plmail', $body);
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function testTheImportFormOffersOnlyCalendarsThatAcceptWrites(): void
    {
        $client = $this->signIn();

        $mirror             = $this->seedCalendar('Holidays');
        $mirror->isReadOnly = true;
        $this->em->flush();

        $crawler = $client->request('GET', '/calendar/ics/import');

        self::assertResponseIsSuccessful();

        $options = $crawler->filter('select[name="calendarId"] option')->extract(['value']);

        self::assertContains((string) $this->calendar->id, $options);
        self::assertNotContains(
            (string) $mirror->id,
            $options,
            'a read-only mirror could never send what was put on it',
        );
    }

    public function testAnUploadedFileLandsOnTheChosenCalendar(): void
    {
        $client = $this->signIn();

        $this->importFile($client, $this->twoEvents());

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->events->findBy(['calendar' => $this->calendar]));

        $standup = $this->events->findOneByUid($this->calendar, 'meeting-1');

        self::assertNotNull($standup);
        self::assertSame('Standup', $standup->title);
    }

    /** A web page pasted in as a calendar has to come back as a sentence, not a 500. */
    public function testAFileThatIsNotACalendarIsRefusedInsideTheModal(): void
    {
        $client = $this->signIn();

        $this->importFile($client, '<!doctype html><html><body>Not a calendar</body></html>');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not a calendar file', (string) $client->getResponse()->getContent());
    }

    /** The picker is what a person meets; this is what a crafted POST meets. */
    public function testAPostNamingAReadOnlyCalendarIsRefusedRatherThanHonoured(): void
    {
        $client = $this->signIn();

        $mirror             = $this->seedCalendar('Holidays');
        $mirror->isReadOnly = true;
        $this->em->flush();

        $this->importFile($client, $this->twoEvents(), $mirror);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->events->findBy(['calendar' => $mirror]));
    }

    // ── Subscribe ─────────────────────────────────────────────────────────────

    /**
     * The whole path, from the form to a mirrored calendar: normalise the
     * address, fetch it once, name the calendar after the feed, and mark it
     * read-only because a file at a URL accepts nothing.
     */
    public function testSubscribingToAFeedCreatesAReadOnlyCalendarNamedAfterIt(): void
    {
        $client = $this->signIn();

        $this->scriptFeed($this->feedDocument());

        $client->request('POST', '/calendar/ics/subscribe', [
            '_token' => $this->token($client, '/calendar/ics/subscribe'),
            // webcal://, because that is what a "Subscribe" button copies. A
            // form that refused it would refuse the address the user was handed.
            'url'    => str_replace('https://', 'webcal://', self::FEED_URL),
            'name'   => '',
        ]);

        self::assertResponseIsSuccessful();

        $connection = $this->integrations->findOneBy(['usr' => $this->user, 'provider' => Provider::Ics]);

        self::assertNotNull($connection, 'the subscription is a connection like any other');
        self::assertSame(self::FEED_URL, $connection->baseUrl, 'webcal is stored rewritten, once, at the door');

        $mirrored = $this->calendars->findMirroredForIntegration($connection);

        self::assertCount(1, $mirrored, 'a feed is exactly one calendar');
        self::assertSame('German holidays', $mirrored[0]->name);
        self::assertTrue($mirrored[0]->isReadOnly, 'nothing can write to a file at a URL');
        self::assertSame('Europe/Berlin', $mirrored[0]->timeZone);
    }

    /**
     * The SSRF refusal, met from the form. Rendered at 422 so the modal stays
     * open with the address still in the field, rather than thrown.
     */
    public function testAnAddressInsideTheNetworkIsRefusedWithASentence(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/calendar/ics/subscribe', [
            '_token' => $this->token($client, '/calendar/ics/subscribe'),
            'url'    => 'https://127.0.0.1/holidays.ics',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('private network', (string) $client->getResponse()->getContent());
        self::assertCount(0, $this->integrations->findBy(['usr' => $this->user, 'provider' => Provider::Ics]));
    }

    /**
     * An address that answers with a web page leaves nothing behind. There is no
     * credential to correct and no second field to change, so a broken row would
     * be one the user has to notice and delete before pasting the same address
     * with the typo fixed.
     */
    public function testAnAddressThatIsNotACalendarLeavesNoConnectionBehind(): void
    {
        $client = $this->signIn();

        $this->scriptFeed('<!doctype html><html><body>Subscribe here</body></html>');

        $client->request('POST', '/calendar/ics/subscribe', [
            '_token' => $this->token($client, '/calendar/ics/subscribe'),
            'url'    => self::FEED_URL,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(
            0,
            $this->integrations->findBy(['usr' => $this->user, 'provider' => Provider::Ics]),
            'a subscription with nothing to retry is not worth keeping',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function importFile(KernelBrowser $client, string $contents, ?Calendar $calendar = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ics');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $client->request(
            'POST',
            '/calendar/ics/import',
            [
                '_token'     => $this->token($client, '/calendar/ics/import'),
                'calendarId' => ($calendar ?? $this->calendar)->id,
            ],
            ['file' => new UploadedFile($path, 'calendar.ics', 'text/calendar', null, true)],
        );

        @unlink($path);
    }

    /**
     * What the scripted transport will answer with next.
     *
     * The client itself is swapped in by signIn(), before anything has had a
     * chance to instantiate the real one — a container refuses to replace a
     * service already built, and merely booting the kernel in debug mode builds
     * this one. So the instance is fixed early and only its answers are set
     * here.
     */
    private function scriptFeed(string $body): void
    {
        $this->http->setResponseFactory([
            new MockResponse($body, [
                'http_code'        => 200,
                'response_headers' => ['content-type' => 'text/calendar; charset=utf-8', 'etag' => '"v1"'],
            ]),
        ]);
    }

    /**
     * The token the form itself renders.
     *
     * Read out of the rendered modal rather than minted from the token manager,
     * which needs a session the test has not started — and which would also
     * make the test pass against a form that stopped emitting one.
     */
    private function token(KernelBrowser $client, string $formPath): string
    {
        $crawler = $client->request('GET', $formPath);

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function seedEvent(string $uid, string $title): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = $uid;
        $event->title      = $title;
        $event->startsAt   = new DateTimeImmutable('2026-08-10 08:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-08-10 09:00', $utc);
        $event->timeZone   = 'UTC';
        $event->jscalendar = ['@type' => 'Event', 'uid' => $uid, 'title' => $title];

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seedCalendar(string $name, bool $isDefault = false): Calendar
    {
        $calendar            = new Calendar();
        $calendar->usr       = $this->user;
        $calendar->name      = $name;
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = $isDefault;

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function otherUser(): User
    {
        $user            = new User();
        $user->email     = 'ics-other-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Other';
        $user->nameLast  = 'Person';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();

        // First, before the container has had a reason to build the real one:
        // a service already instantiated cannot be replaced, and every request
        // this test makes would otherwise be able to reach the network.
        $this->http = new MockHttpClient([]);
        $container->set('http_client', $this->http);

        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->events       = $container->get(CalendarEventRepository::class);
        $this->calendars    = $container->get(CalendarRepository::class);
        $this->integrations = $container->get(IntegrationRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'ics-controller-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Ics';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $this->seedCalendar('Personal', isDefault: true);

        $client->loginUser($user);

        return $client;
    }

    private function twoEvents(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            SUMMARY:Standup
            END:VEVENT
            BEGIN:VEVENT
            UID:holiday-1
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20260501
            DTEND;VALUE=DATE:20260502
            SUMMARY:Labour Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function feedDocument(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            X-WR-CALNAME:German holidays
            X-WR-TIMEZONE:Europe/Berlin
            BEGIN:VEVENT
            UID:holiday-1
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20260501
            DTEND;VALUE=DATE:20260502
            SUMMARY:Labour Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }
}
