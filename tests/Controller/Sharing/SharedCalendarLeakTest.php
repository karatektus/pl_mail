<?php

declare(strict_types=1);

namespace App\Tests\Controller\Sharing;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\Sharing\PublicLinkToken;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a busy/free link actually sends over the wire.
 *
 * ShareLinkReaderTest asserts that the redaction produces the right objects.
 * This asserts the thing that matters to the person who sent the link: that no
 * byte of the response — markup, attribute, script, JSON, or the .ics beside it
 * — contains anything the link did not reveal. A guarantee nobody has checked
 * from the far end is a claim, and the ways of breaking it are all things
 * somebody would do for a good reason: a `title=` attribute for a tooltip, a
 * `data-` attribute for a Stimulus controller, a JSON payload for a future
 * grid, an .ics built from the event because the exporter was right there.
 *
 * Written as HealthTest::testItLeaksNothingAboutTheInstance is, and for the same
 * reason it exists: an endpoint anyone can reach reports only what it was meant
 * to, and an addition that leaks something has to fail the suite rather than
 * merely be regrettable.
 *
 * The fixture titles are deliberately distinctive strings. A search for "Board
 * meeting" would be satisfied by any two English words appearing anywhere in a
 * Tailwind class list; these cannot occur by accident.
 *
 * The three other properties asserted here are the ones a session would break:
 * the page must answer without one, must not set a cookie, and must 404
 * identically for a revoked link and a token nobody ever minted.
 */
final class SharedCalendarLeakTest extends WebTestCase
{
    private const string SECRET_TITLE       = 'Zqx-title-must-not-leak';
    private const string SECRET_PLACE       = 'Zqx-location-must-not-leak';
    private const string SECRET_DESCRIPTION = 'Zqx-description-must-not-leak';
    private const string SECRET_PERSON      = 'Zqx-participant-must-not-leak';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private PublicLinkToken $tokens;
    private User $user;
    private Calendar $calendar;
    private CalendarShareLink $link;
    private string $token;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testABusyFreeLinkLeaksNothingConcreteIntoTheHtml(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $client->request('GET', '/share/' . $this->token);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        foreach ($this->secrets() as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $body,
                sprintf('a busy/free page put %s somewhere in the response', $secret),
            );
        }

        // And the page is not simply empty: the busy block has to be there, or
        // this test would pass against a link that showed nothing at all.
        self::assertStringContainsString('Busy', $body);
    }

    public function testABusyFreeLinkLeaksNothingConcreteIntoTheIcs(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $client->request('GET', '/share/' . $this->token . '/calendar.ics');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');

        $body = (string) $client->getResponse()->getContent();

        foreach ($this->secrets() as $secret) {
            self::assertStringNotContainsString($secret, $body, sprintf('the .ics carried %s', $secret));
        }

        self::assertStringContainsString('BEGIN:VEVENT', $body, 'the file has no events, so it proves nothing');
        self::assertStringContainsString('SUMMARY:Busy', $body);
    }

    /**
     * The event's own UID is the meeting's identity everywhere it exists, so
     * publishing it would let a recipient who already holds the invitation
     * match it against the anonymous block — concrete data by the back door.
     */
    public function testTheIcsDoesNotCarryTheEventsRealUid(): void
    {
        $client = $this->boot();
        $event  = $this->eventTomorrow();

        $client->request('GET', '/share/' . $this->token . '/calendar.ics');

        self::assertStringNotContainsString($event->uid, (string) $client->getResponse()->getContent());
    }

    /** Ticking a box has to actually reveal it, or the test above proves nothing. */
    public function testARevealingLinkDoesShowWhatItWasTickedFor(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $this->link->reveal([ShareDetail::Title]);
        $this->em->flush();

        $client->request('GET', '/share/' . $this->token);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(self::SECRET_TITLE, $body);
        self::assertStringNotContainsString(self::SECRET_PLACE, $body, 'ticking Titles revealed the location too');
        self::assertStringNotContainsString(self::SECRET_DESCRIPTION, $body);
    }

    /**
     * A public page that started a session would set a cookie per visitor and
     * per poll of the .ics. It is why these pages have their own layout — the
     * app one renders a CSRF token into a meta tag, and that alone starts one.
     */
    public function testThePageAnswersWithoutStartingASession(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $client->request('GET', '/share/' . $this->token);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [],
            $client->getResponse()->headers->getCookies(),
            'the shared page set a cookie, which means a session per visitor',
        );
    }

    public function testARevokedLinkAnswersExactlyLikeATokenNobodyMinted(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $this->link->revokedAt = new DateTimeImmutable();
        $this->em->flush();

        $client->request('GET', '/share/' . $this->token);
        $revoked = $client->getResponse()->getStatusCode();

        $client->request('GET', '/share/' . str_repeat('a', 43));
        $unknown = $client->getResponse()->getStatusCode();

        self::assertSame(404, $revoked);
        self::assertSame($unknown, $revoked, 'a revoked link is distinguishable from an unknown one');
    }

    /** Opening the page records that somebody did; the .ics poll deliberately does not. */
    public function testOpeningThePageRecordsAViewAndSubscribingDoesNot(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        self::assertNull($this->link->lastViewedAt);

        $client->request('GET', '/share/' . $this->token);

        // Compared to the second, not as objects: the column stores seconds,
        // so an instance still in the identity map carries microseconds the
        // one read back from the database does not — a difference about
        // Doctrine's hydration rather than about what was written.
        $viewed = $this->reread()->lastViewedAt?->format('Y-m-d H:i:s');
        self::assertNotNull($viewed);

        $client->request('GET', '/share/' . $this->token . '/calendar.ics');

        self::assertSame(
            $viewed,
            $this->reread()->lastViewedAt?->format('Y-m-d H:i:s'),
            'a subscribed client moved "last opened"',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * The link, read back from the database after a request.
     *
     * Not EntityManager::refresh(), which throws here: the kernel browser's
     * request cycle clears the identity map, so the instance this test is
     * holding is detached by the time the response arrives. Re-reading through
     * the repository is also the more honest check — it asserts what was
     * written, not what is in memory.
     */
    private function reread(): CalendarShareLink
    {
        $link = static::getContainer()
            ->get(CalendarShareLinkRepository::class)
            ->findOneByDigest($this->tokens->digest($this->token));

        self::assertNotNull($link);

        return $link;
    }

    /** @return list<string> */
    private function secrets(): array
    {
        return [self::SECRET_TITLE, self::SECRET_PLACE, self::SECRET_DESCRIPTION, self::SECRET_PERSON];
    }

    private function eventTomorrow(): CalendarEvent
    {
        $zone  = new DateTimeZone('Europe/Berlin');
        $start = new DateTimeImmutable('tomorrow 10:00', $zone);

        $event = $this->writer->write(
            event:             new CalendarEvent(),
            calendar:          $this->calendar,
            user:              $this->user,
            title:             self::SECRET_TITLE,
            startsAt:          $start,
            endsAt:            $start->modify('+1 hour'),
            timeZone:          'Europe/Berlin',
            location:          self::SECRET_PLACE,
            description:       self::SECRET_DESCRIPTION,
            jscalendarOverlay: [
                'participants' => [
                    'one' => ['@type' => 'Participant', 'name' => self::SECRET_PERSON, 'email' => 'zqx@example.test'],
                ],
            ],
        );

        $this->em->flush();

        return $event;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->tokens     = $container->get(PublicLinkToken::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'share-leak-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Share';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Personal';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Berlin';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $token = $this->tokens->mint();

        $link              = new CalendarShareLink();
        $link->usr         = $user;
        $link->name        = 'For the recruiter';
        $link->tokenDigest = $this->tokens->digest($token);
        $link->windowMode  = ShareWindow::Rolling;
        $link->rollingDays = 14;
        $link->cover([$calendar]);
        $this->em->persist($link);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->link     = $link;
        $this->token    = $token;

        return $client;
    }
}
