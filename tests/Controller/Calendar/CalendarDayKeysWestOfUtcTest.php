<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A reader west of UTC sees the date a day key names, in every view (#325).
 *
 * The grids are built from day keys, wall dates as strings ("2026-11-06").
 * Twig's `|date` reads such a string as midnight in PHP's zone, which is UTC,
 * and then converts it into Twig's — the reader's, set per request by
 * TwigTimezoneSubscriber. In Los Angeles midnight UTC is 4 or 5 pm the day
 * before, so the day view of Friday the 6th was headed "FRI 5": the weekday
 * comes from `intl_date(…, false)`, which keeps the string's zone, and the
 * number did not. The month view drew its weekend tint on Friday and Saturday
 * and dimmed the 1st as part of the previous month, for the same reason.
 *
 * East of UTC the conversion moves forward and stays on the same day, so every
 * other test here, written in UTC or Berlin, rendered the right number and none
 * of them could notice. Hence a zone well west of UTC, and November 2026, the
 * month the report came from — although nothing about it is November's: the
 * daylight-saving change on the 1st only moves the offset from 7 to 8 hours.
 */
final class CalendarDayKeysWestOfUtcTest extends WebTestCase
{
    private const string ZONE = 'America/Los_Angeles';

    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDayViewIsHeadedWithItsOwnDate(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/day/2026-11-06');
        self::assertResponseIsSuccessful();

        $headings = $this->headings($crawler);
        self::assertSame([['Fri', 6]], $headings, 'the issue\'s screenshot read FRI 5');
    }

    public function testEveryWeekHeadingPairsItsWeekdayWithItsNumber(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/week/2026-11-06');
        self::assertResponseIsSuccessful();

        $headings = $this->headings($crawler);
        self::assertCount(7, $headings);

        // Whichever day the week starts on, the weekday and the number have to
        // name the same date of that week.
        foreach ($headings as [$weekday, $number]) {
            $date = new DateTimeImmutable(sprintf('2026-11-%02d', $number), new DateTimeZone('UTC'));
            self::assertSame($date->format('D'), $weekday, sprintf('%s %d', $weekday, $number));
        }
    }

    public function testEveryMonthCellShowsItsDateItsWeekendAndItsMonth(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/month/2026-11-06');
        self::assertResponseIsSuccessful();

        $cells = $crawler->filter('[data-calendar-grid="month"] [data-day]');
        self::assertCount(42, $cells);

        $today = (new DateTimeImmutable('today', new DateTimeZone(self::ZONE)))->format('Y-m-d');

        $cells->each(static function (Crawler $cell) use ($today): void {
            $key  = (string) $cell->attr('data-day');
            $date = new DateTimeImmutable($key, new DateTimeZone('UTC'));
            $card = $cell->children()->first();

            self::assertSame($date->format('j'), trim($cell->filter('span.tabular-nums')->first()->text()), $key . ' is numbered as itself');

            // Classes rather than data attributes, as the chip assertions in
            // CalendarMonthReadabilityTest are: these two are what a reader
            // sees of the flags, and the flags had no other output.
            self::assertSame(
                '11' !== $date->format('m'),
                str_contains((string) $card->attr('class'), 'opacity-55'),
                $key . ($date->format('m') === '11' ? ' is November\'s' : ' is dimmed as another month\'s'),
            );

            if ($key !== $today) {
                self::assertSame(
                    (int) $date->format('N') >= 6,
                    str_contains((string) $card->attr('class'), 'bg-sunken/40'),
                    $key . ' is tinted as a weekend exactly when it is one',
                );
            }
        });
    }

    /**
     * Each time-grid heading as its weekday and its number, in order.
     *
     * @return list<array{string, int}>
     */
    private function headings(Crawler $crawler): array
    {
        return $crawler->filter('[data-calendar--time-grid-target="heading"]')->each(
            static function (Crawler $heading): array {
                $spans = $heading->filter('span');

                return [trim($spans->eq(0)->text()), (int) trim($spans->eq(1)->text())];
            },
        );
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $em               = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'west-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'West';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $user->timezone  = self::ZONE;
        $em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'West fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = self::ZONE;
        $calendar->isDefault = true;
        $em->persist($calendar);

        $em->flush();

        $client->loginUser($user);

        return $client;
    }
}
