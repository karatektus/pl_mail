<?php

declare(strict_types=1);

namespace App\Tests\Controller\Sharing;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Domain\Enum\Theme\Theme;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\Sharing\PublicLinkToken;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The two pages a stranger sees look like plMail's calendar, and still say only
 * what they are allowed to.
 *
 * SharedCalendarLeakTest asserts the negative — that no byte of the response
 * carries something the link withheld — and BookingEndpointTest asserts the
 * booking actually happens. Neither has an opinion about what is on screen, and
 * for a long time nothing did: the shared calendar was a list of days and the
 * booking page was a wall of times, both correct and neither recognisable as
 * belonging to the application they came out of. That is a defect a browser can
 * see and a test suite cannot, which is how it survived.
 *
 * So this file asserts the STRUCTURE the redesign put there, and only the parts
 * that would be a real regression if they went:
 *
 *   **There is a month grid, and it is the application's own.** One
 *   `[data-calendar-grid="month"]` with 42 cells, drawn by the same partial the
 *   authenticated calendar embeds — which is asserted here too, because a shell
 *   extracted from a template with no render test of its own is a shell that can
 *   break silently.
 *
 *   **A cell outside the published window is distinguishable from a free one.**
 *   The single most dangerous thing a grid could do to this feature is imply
 *   the owner is free on days the link never covered.
 *
 *   **The redaction survives the new markup.** A busy/free link draws its chips
 *   and its rows without the title; a link ticked for titles draws it in both.
 *   The leak test proves nothing got out; this proves something got IN, which is
 *   the half that a page rendering nothing at all would also pass.
 *
 *   **The pages wear the owner's theme, and nothing else of theirs.** Rendered
 *   with a real appearance on the owning account, and still with no cookie —
 *   resolving a theme must not be the thing that quietly starts a session.
 *
 *   **The booking form is reachable.** A week of columns, seven of them, empty
 *   days named rather than dropped, and a slot radio inside the form that posts
 *   to the booking route.
 *
 * Class names are deliberately not asserted anywhere. They are layout and they
 * are allowed to change; `data-day`, `data-day-unpublished`, `data-booking-day`
 * and `data-shared-entry` are the structure and are not.
 */
final class PublicCalendarStyleTest extends WebTestCase
{
    private const string SECRET_TITLE = 'Zqx-style-title-must-not-leak';

    /** Six weeks. The grid's whole point is that this number never changes. */
    private const int MONTH_CELLS = 42;

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private PublicLinkToken $tokens;
    private User $user;
    private Calendar $calendar;
    private CalendarShareLink $link;
    private string $shareToken;
    private string $bookingToken;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── The shared calendar is a calendar ─────────────────────────────────────

    public function testTheSharedPageDrawsAMonthGrid(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter('[data-calendar-grid="month"]'),
            'the shared page has no month grid, so it is still a list',
        );

        self::assertCount(
            self::MONTH_CELLS,
            $crawler->filter('[data-calendar-grid="month"] [data-day]'),
            'a month grid that is not six weeks changes height as you page through it',
        );
    }

    /**
     * The window under the heading is a date range, not a translation key.
     *
     * It was `calendar.share.window` — a node with two children the settings
     * form uses for its radio labels — so every recipient of every shared link
     * has been reading the literal string "calendar.share.window" under the
     * title since the feature shipped. Nothing failed, because nothing had an
     * opinion about what was on the page.
     */
    public function testTheWindowIsPrintedRatherThanItsTranslationKey(): void
    {
        $client = $this->boot();

        $client->request('GET', '/share/' . $this->shareToken);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('calendar.share.', $body, 'a translation key reached the page');
        self::assertStringContainsString(new DateTimeImmutable('today')->format('j M Y'), $body);
    }

    /**
     * A rolling fortnight leaves most of a month unpublished, and those cells
     * must not read as free days. This is the assertion that stops the grid
     * turning into a claim nobody made.
     */
    public function testDaysOutsideTheWindowAreMarkedAndTheSharedOnesAreNot(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        $unpublished = $crawler->filter('[data-day][data-day-unpublished]')->count();

        self::assertGreaterThan(
            0,
            $unpublished,
            'a fourteen-day link covered every cell of a 42-cell month, which cannot be',
        );

        self::assertLessThan(
            self::MONTH_CELLS,
            $unpublished,
            'every cell was marked unpublished, so the window itself is missing',
        );

        $tomorrow = $this->tomorrow()->format('Y-m-d');

        self::assertCount(
            0,
            $crawler->filter(sprintf('[data-day="%s"][data-day-unpublished]', $tomorrow)),
            'a day inside the rolling window was drawn as if the link did not cover it',
        );
    }

    /** The entry is drawn in the cell it belongs to, as a chip, with a mark. */
    public function testAnEntryDrawsAChipInItsOwnCell(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        $cell = sprintf('[data-day="%s"]', $this->tomorrow()->format('Y-m-d'));

        self::assertCount(1, $crawler->filter($cell . ' [data-shared-entry]'));
        self::assertCount(1, $crawler->filter($cell . ' [data-shared-entry-mark]'));
    }

    /**
     * The grid is new markup over old data, so the redaction has to be proved
     * over the new markup as well. Busy/free: a chip, and no title in it.
     */
    public function testABusyFreeChipSaysBusyAndNothingElse(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        $chip = $crawler->filter(
            sprintf('[data-day="%s"] [data-shared-entry]', $this->tomorrow()->format('Y-m-d')),
        );

        self::assertStringContainsString('Busy', $chip->text());
        self::assertStringNotContainsString(self::SECRET_TITLE, $chip->html());
        self::assertStringNotContainsString(
            self::SECRET_TITLE,
            (string) $client->getResponse()->getContent(),
            'the grid put a redacted title somewhere else on the page',
        );
    }

    /** And ticking the box has to reach the chip, or the test above proves nothing. */
    public function testATitleTickedForIsDrawnInTheChipAndInTheDayList(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $this->link->reveal([ShareDetail::Title]);
        $this->em->flush();

        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        self::assertStringContainsString(
            self::SECRET_TITLE,
            $crawler->filter(sprintf('[data-day="%s"]', $this->tomorrow()->format('Y-m-d')))->html(),
            'the title was revealed but the grid cell did not draw it',
        );

        self::assertGreaterThan(
            1,
            substr_count((string) $client->getResponse()->getContent(), self::SECRET_TITLE),
            'the title reached the grid but not the day list under it',
        );
    }

    /**
     * Paging is bounded to the months the link publishes. A rolling fortnight
     * touches one month or two and never more, so there is always at least one
     * end with no step beyond it.
     */
    public function testMonthPagingCannotLeaveTheWindow(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        $steps = $crawler->filter('a[href*="month="]');

        self::assertLessThanOrEqual(
            1,
            $steps->count(),
            'a fourteen-day window offered more than one month to page into',
        );

        // And a month far outside the window is clamped rather than answered,
        // because a public URL is hand-edited and an empty month it produced
        // would read as "free all that month".
        $client->request('GET', '/share/' . $this->shareToken . '?month=1999-01');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $client->getCrawler()->filter('[data-day]:not([data-day-unpublished])')->count(),
            'a nonsense month answered a page with nothing published on it',
        );
    }

    // ── The theme is the owner's ──────────────────────────────────────────────

    public function testTheSharedPageIsDrawnInTheOwnersTheme(): void
    {
        $client = $this->boot();

        $this->user->appearance->theme  = Theme::Dusk;
        $this->user->appearance->accent = '#123456';
        $this->em->flush();

        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        self::assertSame('dusk', $this->rootAttribute($crawler, 'data-theme'));
        self::assertStringContainsString(
            '--rgb-accent:18 52 86',
            $this->rootAttribute($crawler, 'style'),
            "the owner's accent did not reach the page",
        );
    }

    public function testTheBookingPageIsDrawnInTheOwnersTheme(): void
    {
        $client = $this->boot();

        $this->user->appearance->theme = Theme::Dark;
        $this->em->flush();

        $crawler = $client->request('GET', '/book/' . $this->bookingToken);

        self::assertSame('dark', $this->rootAttribute($crawler, 'data-theme'));
        self::assertStringContainsString(
            'dark',
            $this->rootAttribute($crawler, 'class'),
            'a dark theme did not put the `dark` class on <html>, so nothing inside switches',
        );
    }

    /**
     * Paper, which is what a fresh account is on. Not "no theme": the `:root`
     * fallback is what a stylesheet resolves against before a user is known,
     * and a public page rendering in it is the complaint this change came from.
     */
    public function testAnOwnerWhoNeverChoseGetsPaperRatherThanNothing(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/share/' . $this->shareToken);

        self::assertSame('paper', $this->rootAttribute($crawler, 'data-theme'));
    }

    /**
     * Resolving an appearance reads a row the request already had in hand. It
     * must not be the thing that starts a session — that would put a cookie on
     * every fetch of a public URL, which is the cost both these controllers
     * exist to avoid.
     */
    public function testResolvingTheOwnersThemeStartsNoSession(): void
    {
        $client = $this->boot();

        $this->user->appearance->theme = Theme::Nord;
        $this->em->flush();

        $client->request('GET', '/share/' . $this->shareToken);
        self::assertSame([], $client->getResponse()->headers->getCookies());

        $client->request('GET', '/book/' . $this->bookingToken);
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    // ── The booking page is a week, and the form still works ──────────────────

    public function testTheBookingPageDrawsAWeekWithEveryDayInIt(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/book/' . $this->bookingToken);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-booking-week]'));
        self::assertCount(
            7,
            $crawler->filter('[data-booking-day]'),
            'a week with fewer than seven columns drops the days with nothing free',
        );
    }

    /**
     * The fixture is bookable Monday to Friday, so the week on screen always
     * contains a Saturday and a Sunday with nothing on them — and the page has
     * to say so rather than leave a gap the reader has to interpret.
     */
    public function testADayWithNothingFreeSaysSoRatherThanVanishing(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/book/' . $this->bookingToken);

        $named = 0;

        foreach ($crawler->filter('[data-booking-day]') as $node) {
            if (true === str_contains(new Crawler($node)->text(), 'Nothing free')) {
                $named++;
            }
        }

        self::assertGreaterThan(0, $named, 'a day with no free times was drawn as an empty box');
    }

    public function testTheSlotPickerIsInsideTheFormThatBooks(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/book/' . $this->bookingToken);

        $form = $crawler->filter(sprintf('form[action="/book/%s"]', $this->bookingToken));

        self::assertCount(1, $form);
        self::assertGreaterThan(
            0,
            $form->filter('[data-booking-week] input[name="slot"]')->count(),
            'the week has no slot radios, so nothing on it can be chosen',
        );
        self::assertCount(1, $form->filter('input[name="w"]'), 'a refused booking would come back on another week');
        self::assertCount(1, $form->filter('button[type="submit"]'));
    }

    /**
     * Paging is bounded by what the page offers. The fixture reaches seven days
     * ahead, so there is one week or two and never a step past the horizon into
     * a week of empty columns that looks like a week the owner has no time in.
     */
    public function testWeekPagingCannotLeaveWhatThePageOffers(): void
    {
        $client  = $this->boot();
        $crawler = $client->request('GET', '/book/' . $this->bookingToken);

        self::assertLessThanOrEqual(1, $crawler->filter('a[href*="w="]')->count());

        $client->request('GET', '/book/' . $this->bookingToken . '?w=1999-01-04');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $client->getCrawler()->filter('input[name="slot"]')->count(),
            'a nonsense week answered a page with nothing bookable on it',
        );
    }

    // ── The extraction did not cost the authenticated calendar its grid ───────

    /**
     * calendar/_view_month.html.twig now embeds the shell the public page uses.
     * Nothing else rendered that template in this suite, so an extraction that
     * broke it would have been found by a person rather than by a build.
     */
    public function testTheAuthenticatedMonthViewStillRendersThroughTheShell(): void
    {
        $client = $this->boot();
        $this->eventTomorrow();

        $client->loginUser($this->user);

        $crawler = $client->request('GET', '/calendar/month/' . $this->tomorrow()->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-calendar-grid="month"]'));
        self::assertCount(self::MONTH_CELLS, $crawler->filter('[data-calendar-grid="month"] [data-day]'));

        // The authenticated calendar supplies every cell it draws, so nothing in
        // it is ever "outside the window" — that state belongs to the shared
        // page alone, and leaking it here would dim a third of the month.
        self::assertCount(0, $crawler->filter('[data-day-unpublished]'));

        self::assertStringContainsString(
            self::SECRET_TITLE,
            $crawler->filter(sprintf('[data-day="%s"]', $this->tomorrow()->format('Y-m-d')))->html(),
            'the owner cannot see their own event on their own calendar',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function rootAttribute(Crawler $crawler, string $attribute): string
    {
        return (string) $crawler->filter('html')->attr($attribute);
    }

    /**
     * Tomorrow in the fixture's zone.
     *
     * Tomorrow rather than today, so the event is inside the rolling window
     * whatever hour the suite runs at — an event at 10:00 "today" is in the
     * past by lunchtime, and a past occurrence is a different test.
     */
    private function tomorrow(): DateTimeImmutable
    {
        return new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Europe/Berlin'));
    }

    private function eventTomorrow(): CalendarEvent
    {
        $start = $this->tomorrow();

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    self::SECRET_TITLE,
            startsAt: $start,
            endsAt:   $start->modify('+1 hour'),
            timeZone: 'Europe/Berlin',
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
        $user->email     = 'public-style-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Public';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $user->timezone  = 'Europe/Berlin';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Personal';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Berlin';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $shareToken = $this->tokens->mint();

        $link              = new CalendarShareLink();
        $link->usr         = $user;
        $link->name        = 'For the recruiter';
        $link->tokenDigest = $this->tokens->digest($shareToken);
        $link->windowMode  = ShareWindow::Rolling;
        $link->rollingDays = 14;
        $link->cover([$calendar]);
        $this->em->persist($link);

        $bookingToken = $this->tokens->mint();

        // Monday to Friday deliberately, unlike BookingEndpointTest's every-day
        // fixture: the Saturday and Sunday columns are what prove a day with
        // nothing free is named rather than dropped. A seven-day horizon always
        // contains weekday slots, so the page is never empty whichever day the
        // suite runs on.
        $page                = new BookingPage();
        $page->usr           = $user;
        $page->calendar      = $calendar;
        $page->name          = 'Intro call';
        $page->tokenDigest   = $this->tokens->digest($bookingToken);
        $page->timeZone      = 'Europe/Berlin';
        $page->weekdays      = [1, 2, 3, 4, 5];
        $page->startMinute   = 0;
        $page->endMinute     = BookingPage::MINUTES_IN_DAY;
        $page->slotMinutes   = 30;
        $page->noticeMinutes = 0;
        $page->horizonDays   = 7;
        $page->checkAgainst([$calendar]);
        $this->em->persist($page);

        $this->em->flush();

        $this->user         = $user;
        $this->calendar     = $calendar;
        $this->link         = $link;
        $this->shareToken   = $shareToken;
        $this->bookingToken = $bookingToken;

        return $client;
    }
}
