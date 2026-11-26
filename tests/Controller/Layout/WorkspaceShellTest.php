<?php

declare(strict_types=1);

namespace App\Tests\Controller\Layout;

use App\Domain\DTO\Layout\DeviceLayout;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Calendar\CalendarView;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The workspace on the server's side (#755): what is saved, and what the first
 * paint is drawn from.
 *
 * The save endpoint takes the whole document from the page, so it is checked
 * like any other write: a token, and a document that parses, or nothing is
 * written. The page keeps working on a refused save, which makes a wrong
 * refusal hard to notice in a browser — the next reload simply goes back.
 *
 * The first paint is pinned here rather than in a browser spec because the
 * controller moves panes around after it connects: a browser cannot tell a
 * correct first paint from a wrong one fixed a moment later, and the moment is
 * exactly the flash this rendering exists to avoid.
 */
final class WorkspaceShellTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/layout/workspaces';

    private const array LIST_AND_MESSAGE = [
        'workspaces' => [
            ['id' => 'w1', 'name' => 'Triage', 'layout' => ['t' => 'split', 'id' => 's1', 'dir' => 'row', 'sizes' => [0.4, 0.6], 'children' => [
                ['t' => 'pane', 'kind' => 'list'],
                ['t' => 'pane', 'kind' => 'message'],
            ]]],
            ['id' => 'w2', 'name' => 'Planning', 'layout' => ['t' => 'split', 'id' => 's2', 'dir' => 'col', 'sizes' => [0.5, 0.5], 'children' => [
                ['t' => 'pane', 'kind' => 'calendar'],
                ['t' => 'pane', 'kind' => 'list'],
            ]]],
        ],
        'active' => 0,
    ];

    public function testAValidDocumentIsSavedWhole(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, self::LIST_AND_MESSAGE, $this->token($client));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(self::LIST_AND_MESSAGE, $this->stored());
    }

    public function testWithoutATokenNothingIsWritten(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, self::LIST_AND_MESSAGE, null);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->reread()->getSetting(User::SETTING_WORKSPACES));
    }

    public function testADocumentThatDoesNotParseIsRefusedAndTheLastGoodOneKept(): void
    {
        [$client] = $this->signedIn();
        $token    = $this->token($client);

        $this->post($client, self::LIST_AND_MESSAGE, $token);

        $twice = self::LIST_AND_MESSAGE;
        $twice['workspaces'][0]['layout']['children'][1]['kind'] = 'list';

        $this->post($client, $twice, $token);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(self::LIST_AND_MESSAGE, $this->stored());
    }

    public function testTheActiveWorkspaceIsDrawnAsATreeAndTheRestAreParked(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $crawler = $client->request('GET', '/mail/inbox');
        $body    = $crawler->filter('[data-layout--workspace-target="body"]');
        $park    = $crawler->filter('[data-layout--workspace-target="park"]');

        self::assertCount(1, $body->filter('.layout-split[data-layout-split="s1"][data-dir="row"]'));
        self::assertSame(['list', 'message'], $body->filter('[data-layout-pane]')->each(static fn (Crawler $pane): string => (string) $pane->attr('data-layout-pane')));
        self::assertSame(['calendar', 'contacts', 'tasks'], $park->filter('[data-layout-pane]')->each(static fn (Crawler $pane): string => (string) $pane->attr('data-layout-pane')));

        // Parked panes cost nothing: an empty lazy frame each.
        self::assertCount(3, $park->filter('turbo-frame[data-layout-lazy]'));
        self::assertSame('flex-grow: 0.4', (string) $body->filter('.layout-cell')->first()->attr('style'));
    }

    public function testWithAMessagePaneTheMessageIsDrawnInItBesideTheList(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertCount(1, $crawler->filter('[data-layout-root][data-message-beside]'));
        self::assertCount(1, $crawler->filter('[data-layout-pane="message"] [data-mail--mail-pane-target="reading"]'));
        self::assertCount(0, $crawler->filter('[data-layout-pane="list"] [data-mail--mail-pane-target="reading"]'));
    }

    public function testWithoutAMessagePaneTheMessageTakesTheListsPlace(): void
    {
        [$client, $user] = $this->signedIn();
        $document           = self::LIST_AND_MESSAGE;
        $document['active'] = 1;
        $this->store($user, $document);

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertCount(0, $crawler->filter('[data-message-beside]'));
        self::assertCount(1, $crawler->filter('[data-layout-pane="list"] [data-mail--mail-pane-target="reading"]'));
        self::assertCount(1, $crawler->filter('.layout-split[data-dir="col"]'));
    }

    public function testTheDevicesSizesAndFullWidthAreDrawnFromItsCookie(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $client->getCookieJar()->set(new Cookie(DeviceLayout::COOKIE, '{"w1":{"s":{"s1":[0.25,0.75]},"m":"message"}}'));
        $crawler = $client->request('GET', '/mail/inbox');

        self::assertSame('flex-grow: 0.25', (string) $crawler->filter('[data-layout-split="s1"] > .layout-cell')->first()->attr('style'));
        self::assertSame('message', $crawler->filter('[data-layout-root]')->attr('data-layout-max'));
        self::assertCount(1, $crawler->filter('[data-layout-pane="message"][data-maxed]'));
    }

    public function testEveryPaneOnScreenCarriesTheMenu(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $crawler = $client->request('GET', '/mail/inbox');

        foreach (['list', 'message'] as $kind) {
            self::assertGreaterThan(0, $crawler->filter(sprintf('[data-layout-pane="%s"] [data-pane-menu-button]', $kind))->count(), $kind);
        }
    }

    public function testTheTopbarListsTheWorkspacesWithTheActiveOnePressed(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $tabs = $client->request('GET', '/mail/inbox')->filter('[data-layout--workspace-target="workspaceTabs"] button');

        self::assertSame(['Triage', 'Planning'], $tabs->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('true', $tabs->first()->attr('aria-pressed'));
    }

    public function testAFurtherCalendarIsDrawnInAFrameOfItsOwn(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, ['workspaces' => [
            ['id' => 'w1', 'name' => 'Planning', 'layout' => ['t' => 'split', 'id' => 's1', 'dir' => 'row', 'sizes' => [0.5, 0.25, 0.25], 'children' => [
                ['t' => 'pane', 'kind' => 'list'],
                ['t' => 'pane', 'kind' => 'calendar'],
                ['t' => 'pane', 'kind' => 'calendar', 'id' => 'c2'],
            ]]],
        ], 'active' => 0]);

        $body = $client->request('GET', '/mail/inbox')->filter('[data-layout--workspace-target="body"]');

        self::assertSame(['list', 'calendar', 'calendar~c2'], $body->filter('[data-layout-pane]')->each(static fn (Crawler $pane): string => (string) $pane->attr('data-layout-pane')));
        self::assertCount(2, $body->filter('[data-pane-kind="calendar"]'));
        self::assertCount(1, $body->filter('[data-layout-pane="calendar"] turbo-frame#calendar-pane-frame'));
        self::assertCount(1, $body->filter('[data-layout-pane="calendar~c2"] turbo-frame#calendar-pane-frame-c2'));

        // Its links say which pane they are for, so its view is its own.
        $links = $body->filter('#calendar-pane-frame-c2 [data-calendar-views] a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));

        self::assertNotEmpty($links);

        foreach ($links as $href) {
            self::assertStringEndsWith('?pane=c2', $href);
        }
    }

    public function testEachCalendarPaneKeepsItsOwnView(): void
    {
        [$client] = $this->signedIn();
        $first = $this->reread()->calendarPaneView;
        $other = CalendarView::Month === $first ? CalendarView::Day : CalendarView::Month;

        $client->request('GET', '/calendar/' . $other->value . '/2026-11-02?pane=c2');
        self::assertResponseIsSuccessful();

        $user = $this->reread();
        self::assertSame($other, $user->calendarPaneViewFor('c2'));
        self::assertSame($first, $user->calendarPaneView, 'the first calendar pane kept its view');
        self::assertSame($first, $user->calendarPaneViewFor('c3'), 'a pane with nothing stored opens on the first one\'s');

        $frame = $client->request('GET', '/calendar/pane?pane=c2')->filter('turbo-frame#calendar-pane-frame-c2');
        self::assertCount(1, $frame);
        self::assertSame('page', $frame->filter('[data-calendar-views] a[href^="/calendar/' . $other->value . '/"]')->attr('aria-current'));
    }

    public function testTheContactsPageBorrowsThePaneAtFullWidth(): void
    {
        [$client, $user] = $this->signedIn();
        $this->store($user, self::LIST_AND_MESSAGE);

        $crawler = $client->request('GET', '/contacts');
        $root    = $crawler->filter('[data-layout-root]');

        self::assertSame('contacts', $root->attr('data-layout-max'));
        self::assertSame('contacts', $root->attr('data-layout-page'));
        self::assertCount(1, $crawler->filter('[data-layout-borrowed] [data-layout-pane="contacts"][data-maxed]'));
    }

    protected function tearDown(): void
    {
        if (null !== $user = $this->find()) {
            $user->setSetting(User::SETTING_WORKSPACES, null);
            $user->setSetting(User::SETTING_CALENDAR_PANE_VIEWS, null);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $document */
    private function post(KernelBrowser $client, array $document, ?string $token): void
    {
        $client->request('POST', self::PATH, [], [], array_filter([
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]), json_encode($document, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $document */
    private function store(User $user, array $document): void
    {
        $user->setSetting(User::SETTING_WORKSPACES, $document);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** The token the shell renders for its controller — the only place the real one comes from. */
    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/mail/inbox')
            ->filter('[data-layout--workspace-token-value]')
            ->attr('data-layout--workspace-token-value');
    }

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $user   = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $user->setSetting(User::SETTING_WORKSPACES, null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        return [$client, $user];
    }

    /**
     * What is stored, read back through the parser: the column is JSONB, which
     * keeps an object's keys in an order of its own.
     *
     * @return array<string, mixed>|null
     */
    private function stored(): ?array
    {
        return Workspaces::fromArray($this->reread()->getSetting(User::SETTING_WORKSPACES))?->toArray();
    }

    private function reread(): User
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = $this->find();

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function find(): ?User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
    }
}
