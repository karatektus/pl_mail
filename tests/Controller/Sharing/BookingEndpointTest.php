<?php

declare(strict_types=1);

namespace App\Tests\Controller\Sharing;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarBooking;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarBookingRepository;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\Sharing\PublicLinkToken;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The one unauthenticated POST on this install that creates rows and sends
 * mail.
 *
 * That combination is the definition of a spam vector, and the token does not
 * help: the person abusing a published booking page is holding exactly the same
 * URL as the person using it. So the properties worth asserting from outside
 * are the ones that bound it and the ones a session would break.
 *
 *   **The limit is reached.** Written as LoginThrottlingTest is, and for the
 *   reason that test gives about itself: an inactive limiter is indistinguishable
 *   from a working one until somebody is actually attacked, and nothing anywhere
 *   says the throttle never engaged. Before this, one script against one
 *   published page could fill a week of somebody's diary and send a confirmation
 *   to a forged address for every hour of it.
 *
 *   **The GET is not limited.** Reading a booking page is what it is for, and a
 *   limit there would let one stranger take somebody's published page off the
 *   internet by refreshing it.
 *
 *   **A booking actually happens**, and lands as an event marked
 *   EventSource::Booking on the calendar the page named. Without this the test
 *   above would pass against an endpoint that refused everything.
 *
 *   **No session.** These pages have their own layout precisely because the app
 *   one starts one, and a cookie per visitor on a public page is a cost that
 *   arrives quietly.
 *
 * The limiter's counters live in a cache pool that outlives the process, so
 * setUp clears it — the same thing LoginThrottlingTest does, and for the same
 * reason: left alone they accumulate across runs until every test in this file
 * "passes" for the wrong reason.
 */
final class BookingEndpointTest extends WebTestCase
{
    /**
     * One past the limiter's allowance of six an hour, with headroom. Which
     * exact attempt trips it is an implementation detail; that it trips at all
     * is not.
     */
    private const int ATTEMPTS = 9;

    private EntityManagerInterface $em;
    private Connection $connection;
    private PublicLinkToken $tokens;
    private User $user;
    private Calendar $calendar;
    private BookingPage $page;
    private string $token;

    protected function setUp(): void
    {
        static::bootKernel();

        $pool = static::getContainer()->get('cache.rate_limiter');

        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

        static::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheBookingPageIsReadableWithoutAnAccount(): void
    {
        $client = $this->boot();

        $client->request('GET', '/book/' . $this->token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Intro call', (string) $client->getResponse()->getContent());
    }

    public function testThePageAnswersWithoutStartingASession(): void
    {
        $client = $this->boot();

        $client->request('GET', '/book/' . $this->token);

        self::assertSame(
            [],
            $client->getResponse()->headers->getCookies(),
            'the booking page set a cookie, which means a session per visitor',
        );
    }

    public function testAStrangerCanTakeASlotAndItBecomesABookedEvent(): void
    {
        $client = $this->boot();

        $client->request('POST', '/book/' . $this->token, [
            'slot'     => $this->slotKey($client),
            'name'     => 'Ada Lovelace',
            'email'    => 'ada@example.test',
            'note'     => 'About the engine',
            'timeZone' => 'Europe/Berlin',
        ]);

        // A redirect, not a 200: Turbo is on the public page — the layout loads
        // the application bundle for its stylesheet — and it refuses to render a
        // 200 answered to a form post, so the browser sits on the form it just
        // submitted. A browser spec found that; this is what keeps it found.
        self::assertResponseRedirects();

        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $bookings = static::getContainer()->get(CalendarBookingRepository::class)
            ->findUpcomingForUser($this->user, new DateTimeImmutable('-1 day'));

        self::assertCount(1, $bookings);
        self::assertSame('Ada Lovelace', $bookings[0]->bookerName);
        self::assertSame('About the engine', $bookings[0]->note);

        $event = static::getContainer()->get(CalendarEventRepository::class)->find((int) $bookings[0]->event->id);

        self::assertNotNull($event);
        self::assertSame(EventSource::Booking, $event->source);
        self::assertSame($this->calendar->id, $event->calendar?->id);
    }

    /**
     * A posted instant the page never offered must be refused rather than
     * written, or the endpoint is "POST any hour you like into somebody's
     * calendar".
     */
    public function testAnInstantThePageNeverOfferedIsRefused(): void
    {
        $client = $this->boot();

        $client->request('POST', '/book/' . $this->token, [
            'slot'  => '2026-06-01 01:00:00',
            'name'  => 'Chancer',
            'email' => 'chancer@example.test',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->bookings());
    }

    public function testTheBookingPostIsEventuallyRefusedFromOneAddress(): void
    {
        $client = $this->boot();

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $client->request('POST', '/book/' . $this->token, [
                'slot'  => '2026-06-01 01:00:00',
                'name'  => 'Flooder',
                'email' => 'flooder@example.test',
            ]);
        }

        self::assertResponseStatusCodeSame(
            429,
            sprintf('the booking endpoint accepted %d consecutive posts without throttling', self::ATTEMPTS),
        );
    }

    /**
     * Reading the page is what it is for. A limit here would hand any stranger
     * a way to take somebody's published page off the internet.
     */
    public function testReadingThePageIsNotThrottled(): void
    {
        $client = $this->boot();

        for ($attempt = 0; $attempt < self::ATTEMPTS * 2; $attempt++) {
            $client->request('GET', '/book/' . $this->token);
        }

        self::assertResponseIsSuccessful();
    }

    public function testADisabledPageAnswersExactlyLikeATokenNobodyMinted(): void
    {
        $client = $this->boot();

        $this->page->isEnabled = false;
        $this->em->flush();

        $client->request('GET', '/book/' . $this->token);
        $disabled = $client->getResponse()->getStatusCode();

        $client->request('GET', '/book/' . str_repeat('b', 43));
        $unknown = $client->getResponse()->getStatusCode();

        self::assertSame(404, $disabled);
        self::assertSame($unknown, $disabled, 'a disabled page is distinguishable from one that never existed');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return list<CalendarBooking> */
    private function bookings(): array
    {
        return static::getContainer()->get(CalendarBookingRepository::class)
            ->findUpcomingForUser($this->user, new DateTimeImmutable('-1 day'));
    }

    /**
     * The first slot the page is currently offering, as the form would post it.
     *
     * Read out of the rendered page rather than computed, so this test asserts
     * what a browser would actually submit — a slot key the template spelled
     * differently from the reader would make every booking "no longer being
     * offered", which is exactly the failure worth catching here.
     */
    private function slotKey(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/book/' . $this->token);
        $value   = $crawler->filter('input[name="slot"]')->first()->attr('value');

        return (string) $value;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->tokens     = $container->get(PublicLinkToken::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'booking-endpoint-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Booking';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Bookings';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Berlin';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $token = $this->tokens->mint();

        // Open every day, so the page has slots whichever day the suite runs on
        // — a Monday-to-Friday fixture would make this file fail at weekends,
        // which is the worst kind of flake because it looks like a real bug two
        // days out of seven.
        $page                = new BookingPage();
        $page->usr           = $user;
        $page->calendar      = $calendar;
        $page->name          = 'Intro call';
        $page->tokenDigest   = $this->tokens->digest($token);
        $page->timeZone      = 'Europe/Berlin';
        $page->weekdays      = [1, 2, 3, 4, 5, 6, 7];
        $page->startMinute   = 0;
        $page->endMinute     = BookingPage::MINUTES_IN_DAY;
        $page->slotMinutes   = 30;
        $page->noticeMinutes = 0;
        $page->horizonDays   = 7;
        $page->checkAgainst([$calendar]);
        $this->em->persist($page);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->page     = $page;
        $this->token    = $token;

        return $client;
    }
}
