<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ParticipationStatus;
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
 * What the month view, the calendar toolbar and the now line draw since #253.
 *
 * The behaviour in a browser — which day a tap selects, the line moving on the
 * minute, the toolbar fitting a phone — is in calendar-month-narrow.spec.ts.
 * This is the markup that behaviour stands on, asserted where it is cheap:
 *
 *   - a month chip is its title first, then its time range and place, and an
 *     invitation nobody has answered carries the dashed bar;
 *   - every day of the six weeks has its list under the grid, only today's is
 *     open at first, an empty one says so, and each list adds to its own day;
 *   - the toolbar carries a short label beside the long one, a week inside
 *     one month of this year names that month once, and one across two months
 *     names only its months on the narrowest row;
 *   - the month and the time grid mount calendar--now on the view's clock.
 */
final class CalendarMonthReadabilityTest extends WebTestCase
{
    private const string ZONE = 'UTC';

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

    public function testAMonthChipIsItsTitleFirstThenItsTimeAndPlace(): void
    {
        $client = $this->signIn();
        $today  = new DateTimeImmutable('today', new DateTimeZone(self::ZONE));

        $this->event('Quartalsplanung Q4', $today->setTime(10, 0), $today->setTime(11, 0), 'Raum Elbe');

        $crawler = $client->request('GET', '/calendar/month');
        self::assertResponseIsSuccessful();

        $chip = $crawler->filter(sprintf('[data-calendar-grid="month"] [data-day="%s"] button[data-event-chip]', $today->format('Y-m-d')))
            ->reduce(static fn ($node) => str_contains($node->text(), 'Quartalsplanung'));
        self::assertCount(1, $chip, 'the event is drawn on its day');

        // Placed by the chip's grid rather than by source order, which puts the
        // time first for every other view's sake: the title takes row 1 and the
        // details row 2.
        $title   = $chip->filter('span')->reduce(static fn ($node) => 'Quartalsplanung Q4' === trim($node->text()));
        $details = $chip->filter('span')->reduce(static fn ($node) => str_contains($node->text(), 'Raum Elbe') && str_contains($node->text(), '10:00'));

        self::assertCount(1, $title);
        self::assertStringContainsString('row-start-1', (string) $title->attr('class'), 'the title is on the first line');
        self::assertStringContainsString('line-clamp-2', (string) $title->attr('class'), 'and may take a second');
        self::assertStringContainsString('row-start-2', (string) $details->first()->attr('class'), 'the details are under it');
        self::assertSame('10:00–11:00 · Raum Elbe', preg_replace('/\s+/', ' ', trim($details->first()->text())));
    }

    public function testAnInvitationNobodyHasAnsweredHasADashedBar(): void
    {
        $client = $this->signIn();
        $today  = new DateTimeImmutable('today', new DateTimeZone(self::ZONE));

        $invite = $this->event('Einladung: Retro', $today->setTime(12, 0), $today->setTime(13, 0));
        $invite->myParticipation = ParticipationStatus::NeedsAction;
        $this->event('Accepted meeting', $today->setTime(14, 0), $today->setTime(15, 0))
            ->myParticipation = ParticipationStatus::Accepted;
        $this->em->flush();

        $crawler = $client->request('GET', '/calendar/month');
        $day     = $crawler->filter(sprintf('[data-calendar-grid="month"] [data-day="%s"]', $today->format('Y-m-d')));

        self::assertCount(1, $day->filter('[data-awaiting-bar]'), 'only the unanswered one is dashed');
    }

    public function testEveryDayHasAListUnderTheGridAndOnlyTodaysIsOpen(): void
    {
        $client = $this->signIn();
        $today  = (new DateTimeImmutable('today', new DateTimeZone(self::ZONE)))->format('Y-m-d');

        $crawler = $client->request('GET', '/calendar/month');

        $lists = $crawler->filter('[data-list-day]');
        self::assertCount(42, $lists, 'one list per day of the six weeks');

        $open = $lists->reduce(static fn ($node) => null === $node->attr('hidden'));
        self::assertCount(1, $open);
        self::assertSame($today, $open->attr('data-list-day'), 'the list starts on today');

        self::assertSame(
            '/calendar/event/new?start=' . $today,
            $open->filter('button[data-ui--modal-src-value]')->attr('data-ui--modal-src-value'),
            "a day's + adds to that day",
        );
        self::assertStringContainsString('Nothing on this day', $open->text(), 'an empty day says so');
    }

    public function testTheToolbarCarriesAShortLabelAndAWeekNamesItsMonthOnce(): void
    {
        $client = $this->signIn();

        // The 3rd to the 9th: seven days inside one month of this year,
        // whichever month the suite runs in.
        $anchor = new DateTimeImmutable('first day of this month', new DateTimeZone(self::ZONE));
        $anchor = $anchor->modify('+2 days');

        $crawler = $client->request('GET', '/calendar/week/' . $anchor->format('Y-m-d'));
        self::assertResponseIsSuccessful();

        $labels = $crawler->filter('[data-calendar-position] > span');
        self::assertCount(2, $labels, 'a long label and a short one');

        self::assertMatchesRegularExpression(
            '/^\p{L}{3,4}\.? 3–9$/u',
            trim($labels->eq(1)->text()),
            'English puts the month in front of the two days: "Oct 3–9"',
        );

        // A week into the next month has a third form, for a 320px phone: its
        // months alone, which fit where "Oct 26–Nov 1" was cut.
        $crawler = $client->request('GET', '/calendar/week/2026-10-26');
        $tightest = $crawler->filter('[data-calendar-position] > span')->eq(1)->children('span');

        self::assertCount(2, $tightest, 'the short label and the one for the narrowest row');
        self::assertSame('Oct–Nov', trim($tightest->eq(1)->text()));

        $crawler = $client->request('GET', '/calendar/month');
        self::assertSame(
            (new DateTimeImmutable('now'))->format('F'),
            trim($crawler->filter('[data-calendar-position] > span')->eq(1)->text()),
            'this year\'s month is named without its year',
        );
    }

    public function testTheMonthAndTheTimeGridKeepNowOnTheViewsClock(): void
    {
        $client = $this->signIn();
        $today  = (new DateTimeImmutable('today', new DateTimeZone(self::ZONE)))->format('Y-m-d');

        foreach (['month', 'day', 'week'] as $view) {
            $crawler = $client->request('GET', '/calendar/' . $view);
            $now     = $crawler->filter('[data-controller~="calendar--now"]');

            self::assertCount(1, $now, $view . ' mounts calendar--now once');
            self::assertSame($today, $now->attr('data-calendar--now-today-value'), $view);
            self::assertNotEmpty($now->attr('data-calendar--now-zone-value'), $view);
        }

        $crawler = $client->request('GET', '/calendar/day');
        self::assertCount(1, $crawler->filter('[data-calendar--now-target="line"]'), "today's line is the target that moves");
    }

    private function event(
        string            $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string           $location = null,
    ): CalendarEvent {
        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $endsAt,
            timeZone: self::ZONE,
            location: $location,
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
        $user->email     = 'month-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Month';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        // The view's clock, and a 24-hour one, so "today" and "10:00" mean
        // the same here as on the page.
        $user->timezone  = self::ZONE;
        $user->setSetting(User::SETTING_CLOCK, '24');
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Month fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = self::ZONE;
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
