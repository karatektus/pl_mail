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
 * Settings → Calendar → Behaviour: the calendar button's one choice of three,
 * through the page that carries it.
 *
 * How the choice is kept is in CalendarSwitchSkipTest; what is pinned here is
 * that the PAGE reaches it — one group of three, the stored one checked, and a
 * post that records exactly what was chosen.
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

    public function testItIsOneChoiceOfThreeAndStartsOnAllThree(): void
    {
        $crawler = $this->pane();

        self::assertSame(
            ['none', 'split', 'calendar'],
            $crawler->filter('input[type=radio][name="skip"]')->each(
                static fn (Crawler $radio): string => (string) $radio->attr('value'),
            ),
        );
        self::assertSame(
            ['All three', 'Full width only', 'Split only'],
            $crawler->filter('form:has(input[name="skip"]) label')->each(
                static fn (Crawler $label): string => trim($label->text()),
            ),
            'each option is named for the views it leaves, not for the one it skips',
        );
        self::assertSame('none', $this->checked($crawler));
        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
    }

    public function testAChoiceIsStoredAndTheNextPageDrawsIt(): void
    {
        $this->post('split');

        self::assertSame(CalendarSwitchSkip::Split, $this->stored()->calendarSwitchSkip);
        self::assertSame('split', $this->checked($this->pane()));

        // Straight to the other one, with nothing to switch off first.
        $this->post('calendar');

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);
        self::assertSame('calendar', $this->checked($this->pane()));
    }

    public function testChoosingAllThreeAgainPutsTheCycleBack(): void
    {
        $this->post('calendar');
        $this->post('none');

        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
        self::assertSame('none', $this->checked($this->pane()));
    }

    /** A form that does not mention the choice must not move it. */
    public function testAPostThatDoesNotNameAChoiceChangesNothing(): void
    {
        $this->post('calendar');

        $this->client->request('POST', '/settings/calendar-behaviour', ['_token' => $this->token()]);

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);
    }

    /** Not read as "none", which would quietly undo what was stored. */
    public function testAPostNamingSomethingThatIsNotAChoiceChangesNothing(): void
    {
        $this->post('calendar');

        $this->client->request('POST', '/settings/calendar-behaviour', ['_token' => $this->token(), 'skip' => 'both']);

        self::assertSame(CalendarSwitchSkip::Calendar, $this->stored()->calendarSwitchSkip);
    }

    public function testAPostWithoutTheTokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/calendar-behaviour', ['skip' => 'split']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(CalendarSwitchSkip::None, $this->stored()->calendarSwitchSkip);
    }

    private function pane(): Crawler
    {
        $crawler = $this->client->request('GET', '/settings?section=calendar-behaviour');

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** The value of the checked radio — what the pane shows. */
    private function checked(Crawler $crawler): string
    {
        $checked = $crawler->filter('input[type=radio][name="skip"][checked]');

        self::assertCount(1, $checked, 'exactly one option is checked');

        return (string) $checked->attr('value');
    }

    /** The token the pane's own form carries. */
    private function token(): string
    {
        $form = $this->pane()->filter('form:has(input[name="skip"])');

        self::assertCount(1, $form);

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    private function post(string $skip): void
    {
        $this->client->request('POST', '/settings/calendar-behaviour', [
            '_token' => $this->token(),
            'skip'   => $skip,
        ]);

        self::assertResponseRedirects('/settings?section=calendar-behaviour');
    }

    private function stored(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id);
    }
}
