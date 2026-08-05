<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedOccurrence;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\Sharing\PublicLinkToken;
use App\Service\Calendar\Sharing\ShareLinkReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A shared link reveals exactly what its checkboxes say and nothing more, and
 * the event's own privacy is the ceiling over them.
 *
 * The redaction happens here rather than in the template, and that is the whole
 * design: what comes back is SharedOccurrence, whose fields are already null
 * where the link revealed nothing. A template cannot leak what it was never
 * given, which is why there is no `if` in the public page that anyone could
 * forget. These tests assert on the DTO because the DTO is the guarantee;
 * SharedCalendarLeakTest asserts the same thing from outside, over the whole
 * response body, because a guarantee nobody has looked at from the far end is
 * a claim.
 *
 * Four things have to hold, and each of them fails silently:
 *
 *   **Busy/free means busy/free.** Every concrete field is null. This is the
 *   default state of a link and the one people will rely on.
 *
 *   **A tick reveals one field, not the row.** Ticking Title must not bring the
 *   location along, or "share when I am busy and what it is called" quietly
 *   publishes a home address.
 *
 *   **Privacy wins over the ticks.** A meeting marked Private is a plain busy
 *   block whatever the link says, and one marked Secret is not there at all —
 *   which is what EventPrivacy's docblock promised before anything used it. A
 *   link is a decision about an audience and a privacy is a decision about one
 *   meeting; the narrower has to win, or the wider is a way to undo it in bulk.
 *
 *   **A cancelled meeting is not a claim on the owner's time**, so it must not
 *   render as busy at an hour they are free.
 *
 * Against a real container and a real database, in a transaction that is never
 * committed. The occurrence rows this reads come from RecurrenceMaterialiser
 * through CalendarEventWriter, and the behaviour worth pinning is the one that
 * emerges from them together.
 */
final class ShareLinkReaderTest extends KernelTestCase
{
    private const string SECRET_TITLE   = 'Board meeting about the acquisition';
    private const string SECRET_PLACE   = '14 Privet Drive, Little Whinging';
    private const string SECRET_DETAILS = 'Bring the signed term sheet';

    private EntityManagerInterface $em;
    private Connection $connection;
    private ShareLinkReader $reader;
    private CalendarEventWriter $writer;
    private PublicLinkToken $tokens;
    private User $user;
    private Calendar $calendar;
    private CalendarShareLink $link;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reader     = $container->get(ShareLinkReader::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->tokens     = $container->get(PublicLinkToken::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testABusyFreeLinkRevealsTimesAndNothingElse(): void
    {
        $this->eventAt('10:00', '11:00');

        $entry = $this->onlyEntry();

        self::assertNull($entry->title, 'a busy/free link handed the template a title');
        self::assertNull($entry->location);
        self::assertNull($entry->description);
        self::assertSame([], $entry->participants);
        self::assertFalse($entry->isDetailed());

        // The times are the point of the link and are always there.
        self::assertSame('08:00', $entry->startsAt->format('H:i'), 'Berlin 10:00 in June is 08:00 UTC');
        self::assertSame('09:00', $entry->endsAt->format('H:i'));
    }

    public function testTickingTitlesRevealsTheTitleAndNothingBesideIt(): void
    {
        $this->eventAt('10:00', '11:00');

        $this->link->reveal([ShareDetail::Title]);
        $this->em->flush();

        $entry = $this->onlyEntry();

        self::assertSame(self::SECRET_TITLE, $entry->title);
        self::assertNull($entry->location, 'ticking Titles brought the location along');
        self::assertNull($entry->description, 'ticking Titles brought the description along');
        self::assertSame([], $entry->participants, 'ticking Titles brought the participants along');
    }

    public function testEachTickUnlocksItsOwnFieldOnly(): void
    {
        $this->eventAt('10:00', '11:00');

        $this->link->reveal([ShareDetail::Location, ShareDetail::Description]);
        $this->em->flush();

        $entry = $this->onlyEntry();

        self::assertNull($entry->title);
        self::assertSame(self::SECRET_PLACE, $entry->location);
        self::assertSame(self::SECRET_DETAILS, $entry->description);
    }

    /**
     * The ceiling. The link says "show everything"; the meeting says "not the
     * subject", and the meeting wins.
     */
    public function testAPrivateEventStaysABusyBlockWhateverTheLinkReveals(): void
    {
        $event          = $this->eventAt('10:00', '11:00');
        $event->privacy = EventPrivacy::Private;
        $this->em->flush();

        $this->link->reveal(ShareDetail::cases());
        $this->em->flush();

        $entry = $this->onlyEntry();

        self::assertNull($entry->title, 'a private event published its title on a fully-revealing link');
        self::assertNull($entry->location);
        self::assertNull($entry->description);
        self::assertSame([], $entry->participants);

        // It is still there as a busy block: private means "you may know I am
        // occupied", which is what makes it different from secret.
        self::assertSame('08:00', $entry->startsAt->format('H:i'));
    }

    /** Secret is not "busy with no detail" — its existence is the detail. */
    public function testASecretEventDoesNotAppearAtAll(): void
    {
        $event          = $this->eventAt('10:00', '11:00');
        $event->privacy = EventPrivacy::Secret;
        $this->em->flush();

        self::assertSame([], $this->entries(), 'a secret event was published as a busy block');
    }

    public function testACancelledEventIsNotShownAsBusy(): void
    {
        $event         = $this->eventAt('10:00', '11:00');
        $event->status = EventStatus::Cancelled;
        $this->em->flush();

        self::assertSame([], $this->entries(), 'a called-off meeting still claimed the owner was busy');
    }

    /**
     * A link covering nothing shows nothing. That is what a save naming only
     * calendars the user does not own produces, and it has to render as an
     * empty page rather than as the whole diary.
     */
    public function testALinkCoveringNoCalendarsShowsNothing(): void
    {
        $this->eventAt('10:00', '11:00');

        $this->link->cover([]);
        $this->em->flush();

        self::assertSame([], $this->entries());
    }

    public function testAnEventOnACalendarTheLinkDoesNotCoverIsNotShown(): void
    {
        $other           = new Calendar();
        $other->usr      = $this->user;
        $other->name     = 'Not shared';
        $other->role     = CalendarRole::Custom;
        $other->timeZone = 'Europe/Berlin';
        $this->em->persist($other);
        $this->em->flush();

        $this->eventAt('10:00', '11:00', $other);

        self::assertSame([], $this->entries());
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    public function testARevokedLinkResolvesToNothing(): void
    {
        $token = $this->tokens->mint();

        $this->link->tokenDigest = $this->tokens->digest($token);
        $this->em->flush();

        self::assertNotNull($this->reader->resolve($token));

        $this->link->revokedAt = new DateTimeImmutable();
        $this->em->flush();

        self::assertNull($this->reader->resolve($token), 'a revoked link still answers');
    }

    /** A token nobody minted must be indistinguishable from one that was revoked. */
    public function testAnUnknownTokenResolvesToNothing(): void
    {
        self::assertNull($this->reader->resolve('a-token-nobody-ever-minted'));
    }

    // ── The window ────────────────────────────────────────────────────────────

    public function testARollingWindowStartsAtTheOwnersTodayAndRunsForItsDayCount(): void
    {
        $view = $this->reader->read($this->link, $this->now());

        self::assertSame('2026-06-01', $view->from->format('Y-m-d'));
        self::assertSame('2026-06-08', $view->to->format('Y-m-d'), 'a seven-day rolling window ended somewhere else');
        self::assertCount(7, $view->days, 'every day in the window is rendered, including the empty ones');
        self::assertSame('Europe/Berlin', $view->zone);
    }

    /**
     * The end date the owner typed is the last day shown, not the last day
     * before the last day shown. An exclusive read of an inclusive field is how
     * a "conference week" link silently loses its Friday.
     */
    public function testAFixedWindowIncludesTheLastDayTheOwnerNamed(): void
    {
        $this->link->windowMode = ShareWindow::Fixed;
        $this->link->startsOn   = new DateTimeImmutable('2026-06-03');
        $this->link->endsOn     = new DateTimeImmutable('2026-06-05');
        $this->em->flush();

        $view = $this->reader->read($this->link, $this->now());

        self::assertSame(['2026-06-03', '2026-06-04', '2026-06-05'], array_keys($view->days));
    }

    /**
     * A half-saved fixed range falls back to the rolling behaviour rather than
     * rendering an empty page, which would be indistinguishable from a diary
     * that happens to be clear.
     */
    public function testAFixedWindowWithNoDatesFallsBackRatherThanShowingNothing(): void
    {
        $this->link->windowMode = ShareWindow::Fixed;
        $this->em->flush();

        self::assertCount(7, $this->reader->read($this->link, $this->now())->days);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-01 06:00:00', new DateTimeZone('Europe/Berlin'));
    }

    /** @return list<SharedOccurrence> */
    private function entries(): array
    {
        $entries = [];

        foreach ($this->reader->read($this->link, $this->now())->days as $day) {
            foreach ($day as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function onlyEntry(): SharedOccurrence
    {
        $entries = $this->entries();

        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function eventAt(string $from, string $to, ?Calendar $calendar = null): CalendarEvent
    {
        $zone = new DateTimeZone('Europe/Berlin');

        $event = $this->writer->write(
            event:       new CalendarEvent(),
            calendar:    $calendar ?? $this->calendar,
            user:        $this->user,
            title:       self::SECRET_TITLE,
            startsAt:    new DateTimeImmutable('2026-06-01 ' . $from . ':00', $zone),
            endsAt:      new DateTimeImmutable('2026-06-01 ' . $to . ':00', $zone),
            timeZone:    'Europe/Berlin',
            location:    self::SECRET_PLACE,
            description: self::SECRET_DETAILS,
        );

        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'share-reader-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Share';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        // Marked default because that is the calendar CalendarTimeResolver
        // reads the user's zone off — a fixture without one resolves to the
        // server's zone, and the window assertions below would then be about
        // UTC rather than about the owner's day.
        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Personal';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Berlin';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $link              = new CalendarShareLink();
        $link->usr         = $user;
        $link->name        = 'For the recruiter';
        $link->tokenDigest = hash('sha256', uniqid('', true));
        $link->windowMode  = ShareWindow::Rolling;
        $link->rollingDays = 7;
        $link->cover([$calendar]);
        $this->em->persist($link);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->link     = $link;
    }
}
