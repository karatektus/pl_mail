<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Calendar\CalendarSwitchSkip;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Calendar → Behaviour: the two calendar-button switches, through
 * the page that carries them.
 *
 * The entity's exclusion rule is in CalendarSwitchSkipTest; what is pinned
 * here is that the PAGE reaches it — each switch is its own form posting only
 * its own field, and the pane draws what is stored.
 */
final class CalendarBehaviourSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'behaviour-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Calendar';
        $user->nameLast  = 'Behaviour';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->user = $user;
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheNavigationOffersItUnderCalendar(): void
    {
        $crawler = $this->pane();

        $link = $crawler->filter('a[href*="section=calendar-behaviour"]');

        self::assertCount(1, $link);
        self::assertSame('Behaviour', trim($link->text()));
        self::assertSame('page', $link->attr('aria-current'), 'and it is the section that is open');
    }

    public function testBothSwitchesStartOffAndTheButtonStillCycles(): void
    {
        $crawler = $this->pane();

        self::assertSame('0', $this->checked($crawler, 'skipSplit'));
        self::assertSame('0', $this->checked($crawler, 'splitOnly'));
        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
    }

    public function testSwitchingSkipSplitOnStoresItAndTheNextPageDrawsIt(): void
    {
        $this->post('skipSplit', '1');

        self::assertSame(CalendarSwitchSkip::Split, $this->stored()->calendarSwitchSkip);

        $crawler = $this->pane();
        self::assertSame('1', $this->checked($crawler, 'skipSplit'));
        self::assertSame('0', $this->checked($crawler, 'splitOnly'), 'the companion: the other one is still off');
    }

    public function testSwitchingTheOtherOneOnSwitchesTheFirstOff(): void
    {
        $this->post('skipSplit', '1');
        $this->post('splitOnly', '1');

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);

        $crawler = $this->pane();
        self::assertSame('1', $this->checked($crawler, 'splitOnly'));
        self::assertSame('0', $this->checked($crawler, 'skipSplit'), 'the page shows what the server stored, not what was clicked last');

        $this->post('skipSplit', '1');

        self::assertSame(CalendarSwitchSkip::Split, $this->stored()->calendarSwitchSkip);
    }

    public function testSwitchingOneOffLeavesTheOtherAlone(): void
    {
        $this->post('splitOnly', '1');
        $this->post('skipSplit', '0');

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);

        $this->post('splitOnly', '0');

        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
    }

    /** A form that does not mention a switch must not move it. */
    public function testAPostThatNamesNeitherSwitchChangesNothing(): void
    {
        $this->post('splitOnly', '1');

        $this->client->request('POST', '/settings/calendar-behaviour', ['_token' => $this->token('skipSplit')]);

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);
    }

    public function testAPostWithoutTheTokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/calendar-behaviour', ['skipSplit' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
    }

    private function pane(): Crawler
    {
        $crawler = $this->client->request('GET', '/settings?section=calendar-behaviour');

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** The value of the checked radio in the named group — what the pane shows. */
    private function checked(Crawler $crawler, string $field): string
    {
        $checked = $crawler->filter(sprintf('input[type=radio][name="%s"][checked]', $field));

        self::assertCount(1, $checked, sprintf('exactly one %s radio is checked', $field));

        return (string) $checked->attr('value');
    }

    /** The token the pane's own form for this switch carries. */
    private function token(string $field): string
    {
        $form = $this->pane()->filter(sprintf('form:has(input[name="%s"])', $field));

        self::assertCount(1, $form);

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    private function post(string $field, string $value): void
    {
        $this->client->request('POST', '/settings/calendar-behaviour', [
            '_token' => $this->token($field),
            $field   => $value,
        ]);

        self::assertResponseRedirects('/settings?section=calendar-behaviour');
    }

    private function stored(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id);
    }
}
