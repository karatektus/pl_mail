<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Appearance → "Calendar button": with it on, the topbar's calendar
 * button is a two-position switch, Mail ↔ full-width calendar, instead of the
 * three-way cycle.
 *
 * The server's half of that is what the page says before any JavaScript runs:
 * the label for a screen reader (what pressing it does next) and the value the
 * ui--split controller reads to skip Split. The click itself is in
 * tests/e2e/calendar-pane.spec.ts. Asserted against the raw response, like
 * CalendarSwitchFirstPaintTest.
 */
final class CalendarSwitchDirectTest extends WebTestCase
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

    /** @return iterable<string, array{bool, CalendarPaneMode, string}> */
    public static function labels(): iterable
    {
        // The default, unchanged — the presence companion to the direct rows.
        yield 'cycling, from the mail' => [false, CalendarPaneMode::Mail, 'calendar.pane.mode.split'];
        yield 'cycling, from split' => [false, CalendarPaneMode::Split, 'calendar.pane.mode.calendar'];
        yield 'direct, from the mail' => [true, CalendarPaneMode::Mail, 'calendar.pane.mode.calendar'];
        yield 'direct, from split' => [true, CalendarPaneMode::Split, 'calendar.pane.mode.mail'];
        yield 'direct, from the calendar' => [true, CalendarPaneMode::Calendar, 'calendar.pane.mode.mail'];
    }

    #[DataProvider('labels')]
    public function testTheLabelNamesWhereAPressLeads(bool $direct, CalendarPaneMode $mode, string $key): void
    {
        $crawler = $this->openInbox($direct, $mode);

        $expected = static::getContainer()->get(TranslatorInterface::class)->trans($key);

        self::assertSame($expected, trim($crawler->filter('a[data-calendar-toggle] [data-calendar-mode-label]')->text()));
    }

    public function testTheShellTellsTheControllerWhichSwitchItIs(): void
    {
        $direct = $this->openInbox(true, CalendarPaneMode::Mail)->filter('[data-controller~="ui--split"]');
        self::assertSame('true', $direct->attr('data-ui--split-direct-value'));

        $cycling = $this->openInbox(false, CalendarPaneMode::Mail)->filter('[data-controller~="ui--split"]');
        self::assertSame('false', $cycling->attr('data-ui--split-direct-value'));
    }

    private function openInbox(bool $direct, CalendarPaneMode $mode): Crawler
    {
        $user            = new User();
        $user->email     = 'direct-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Direct';
        $user->nameLast  = 'Switch';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'x';

        $user->calendarPaneMode          = $mode;
        $user->appearance->calendarDirect = $direct;

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[data-calendar-toggle]'));

        return $crawler;
    }
}
