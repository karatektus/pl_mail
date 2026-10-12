<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Domain\Enum\Calendar\CalendarSwitchSkip;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Calendar → Behaviour: with a position skipped, the topbar's
 * calendar button is a two-position switch instead of the three-way cycle.
 *
 * The server's half of that is what the page says before any JavaScript runs:
 * the label for a screen reader (what pressing it does next) and the value the
 * ui--split controller reads to know which position to skip. The click itself
 * is in tests/e2e/calendar-pane.spec.ts. Asserted against the raw response,
 * like CalendarSwitchFirstPaintTest.
 */
final class CalendarSwitchRenderTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{CalendarSwitchSkip, CalendarPaneMode, string}> */
    public static function labels(): iterable
    {
        // The default, unchanged — the presence companion to the skipping rows.
        yield 'cycling, from the mail' => [CalendarSwitchSkip::None, CalendarPaneMode::Mail, 'calendar.pane.mode.split'];
        yield 'cycling, from split' => [CalendarSwitchSkip::None, CalendarPaneMode::Split, 'calendar.pane.mode.calendar'];
        yield 'skip split, from the mail' => [CalendarSwitchSkip::Split, CalendarPaneMode::Mail, 'calendar.pane.mode.calendar'];
        yield 'skip split, from split' => [CalendarSwitchSkip::Split, CalendarPaneMode::Split, 'calendar.pane.mode.mail'];
        yield 'skip split, from the calendar' => [CalendarSwitchSkip::Split, CalendarPaneMode::Calendar, 'calendar.pane.mode.mail'];
        yield 'skip calendar, from the mail' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Mail, 'calendar.pane.mode.split'];
        yield 'skip calendar, from split' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Split, 'calendar.pane.mode.mail'];
        yield 'skip calendar, from the calendar' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Calendar, 'calendar.pane.mode.mail'];
    }

    #[DataProvider('labels')]
    public function testTheLabelNamesWhereAPressLeads(CalendarSwitchSkip $skip, CalendarPaneMode $mode, string $key): void
    {
        $crawler = $this->openInbox($skip, $mode);

        $expected = static::getContainer()->get(TranslatorInterface::class)->trans($key);

        self::assertSame($expected, trim($crawler->filter('a[data-calendar-toggle] [data-calendar-mode-label]')->text()));
    }

    /** @return iterable<string, array{CalendarSwitchSkip}> */
    public static function skips(): iterable
    {
        yield 'cycling' => [CalendarSwitchSkip::None];
        yield 'skip split' => [CalendarSwitchSkip::Split];
        yield 'skip calendar' => [CalendarSwitchSkip::Calendar];
    }

    #[DataProvider('skips')]
    public function testTheShellTellsTheControllerWhichPositionIsSkipped(CalendarSwitchSkip $skip): void
    {
        $shell = $this->openInbox($skip, CalendarPaneMode::Mail)->filter('[data-controller~="ui--split"]');

        self::assertSame($skip->value, $shell->attr('data-ui--split-skip-value'));
    }

    private function openInbox(CalendarSwitchSkip $skip, CalendarPaneMode $mode): Crawler
    {
        $user            = new User();
        $user->email     = 'skip-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Skip';
        $user->nameLast  = 'Switch';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'x';

        $user->calendarPaneMode   = $mode;
        $user->calendarSwitchSkip = $skip;

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[data-calendar-toggle]'));

        return $crawler;
    }
}
