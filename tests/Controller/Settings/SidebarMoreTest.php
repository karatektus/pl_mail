<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SidebarRow;
use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Choosing which system folders wait behind "More" in the sidebar.
 *
 * Two halves that have to agree and are tested together on purpose: the form
 * that saves the choice, and the sidebar that renders it. A setting that saves
 * and is ignored, or a sidebar that moves rows without the form having said so,
 * each pass a test of their own half.
 *
 * Which rows are IN the sidebar at all is not this setting's business and is
 * pinned here only to show it stays out of the way: the eye toggle in label
 * settings, an empty Snoozed, a Spam nobody switched on. Placement decides
 * where a row sits, never whether it exists.
 *
 * A user of its own with no mail and no labels unless a test seeds them, so
 * the layout asserted is the one a fresh install has: Inbox, Starred, Sent and
 * Drafts on top, Archive and Trash behind More (Spam, Unread and Snoozed are
 * absent until their label is on or something is snoozed).
 */
final class SidebarMoreTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── The form ──────────────────────────────────────────────────────────

    /**
     * Everything but the Inbox, in the sidebar's order, with today's More block
     * ticked — so opening the page changes nothing and saving it unchanged is
     * a no-op.
     */
    public function testTheFormOffersEveryRowButTheInboxWithTodaysDefaultTicked(): void
    {
        $page  = $this->settings();
        $boxes = $page->filter('form[action="/settings/sidebar/more"] input[type="checkbox"][name="rows[]"]');

        // A missing translation renders as its own key, on a page that is
        // otherwise fine.
        self::assertStringNotContainsString('settings.sidebar.', $page->text());
        self::assertCount(1, $page->filter('form[action="/settings/sidebar/more"]'));

        // The entry that says "the form was submitted", without which a form
        // with every box cleared posts nothing and "nothing behind More" could
        // never be saved from the page. The other half of
        // testClearingEveryBoxStoresAnEmptyList(), which posts it by hand.
        self::assertCount(
            1,
            $page->filter('form[action="/settings/sidebar/more"] input[type="hidden"][name="rows[]"][value=""]'),
        );

        self::assertSame(
            ['unread', 'starred', 'sent', 'drafts', 'snoozed', 'archive', 'spam', 'trash'],
            $boxes->each(static fn (Crawler $box): string => (string) $box->attr('value')),
        );

        self::assertSame(
            ['snoozed', 'archive', 'spam', 'trash'],
            $boxes->reduce(static fn (Crawler $box): bool => null !== $box->attr('checked'))
                ->each(static fn (Crawler $box): string => (string) $box->attr('value')),
        );
    }

    public function testSavingStoresTheChoiceAndTheFormShowsIt(): void
    {
        $this->post(['rows' => ['trash', '', 'starred']]);

        self::assertResponseRedirects();
        self::assertSame(['starred', 'trash'], $this->reread()->getSetting(User::SETTING_SIDEBAR_MORE));

        $ticked = $this->settings()
            ->filter('form[action="/settings/sidebar/more"] input[type="checkbox"][name="rows[]"][checked]')
            ->each(static fn (Crawler $box): string => (string) $box->attr('value'));

        self::assertSame(['starred', 'trash'], $ticked);
    }

    /**
     * Every box cleared posts no `rows` entry but the hidden empty one the form
     * always carries, and that is a choice: nothing behind More. A stored
     * empty list, not a cleared key — the key being absent is the default.
     */
    public function testClearingEveryBoxStoresAnEmptyList(): void
    {
        self::assertNull($this->reread()->getSetting(User::SETTING_SIDEBAR_MORE), 'the fixture starts on the default');

        $this->post(['rows' => ['']]);

        self::assertResponseRedirects();
        self::assertSame([], $this->reread()->getSetting(User::SETTING_SIDEBAR_MORE));
    }

    /**
     * A row the form never offered is somebody editing the request. The Inbox
     * is the interesting one: it is a real row, just not a choice.
     */
    public function testARowThatWasNotOfferedIsRefusedAndNothingIsStored(): void
    {
        $this->post(['rows' => ['archive', 'nope']]);
        self::assertResponseStatusCodeSame(404);

        $this->post(['rows' => ['inbox']]);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->reread()->getSetting(User::SETTING_SIDEBAR_MORE));
    }

    /** A post that does not mention the rows does not move them. */
    public function testAPostWithoutRowsChangesNothing(): void
    {
        $this->post(['rows' => ['sent']]);
        $this->post([]);

        self::assertSame(['sent'], $this->reread()->getSetting(User::SETTING_SIDEBAR_MORE));
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/sidebar/more', ['rows' => ['sent']]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reread()->getSetting(User::SETTING_SIDEBAR_MORE));
    }

    // ── The sidebar ───────────────────────────────────────────────────────

    public function testAnUntouchedSidebarKeepsTodaysLayout(): void
    {
        self::assertSame(
            ['top' => ['inbox', 'starred', 'sent', 'drafts'], 'more' => ['archive', 'trash']],
            $this->layout('/mail/inbox'),
        );
    }

    /**
     * Moves in both directions at once, so neither is a one-way trick: Sent and
     * Drafts go behind More, Archive and Trash come out. Each group keeps the
     * sidebar's own order whatever order the choice was made in.
     */
    public function testRowsMoveBetweenTheTopAndMore(): void
    {
        $this->choose([SidebarRow::Drafts, SidebarRow::Sent]);

        self::assertSame(
            ['top' => ['inbox', 'starred', 'archive', 'trash'], 'more' => ['sent', 'drafts']],
            $this->layout('/mail/inbox'),
        );

        // Starred is not a label and has no route in the label list, and it
        // moves like any other.
        $this->choose([SidebarRow::Starred]);

        self::assertSame(
            ['top' => ['inbox', 'sent', 'drafts', 'archive', 'trash'], 'more' => ['starred']],
            $this->layout('/mail/inbox'),
        );
    }

    /**
     * Behind More is indented under the disclosure, on top it is not. The
     * indent is the only thing that says which group a row is in once the
     * disclosure is open, and it has to follow the row.
     */
    public function testTheIndentFollowsThePlacement(): void
    {
        $this->choose([SidebarRow::Sent]);

        $page = $this->client->request('GET', '/mail/inbox');

        $sent    = $page->filter('#sidebar [data-sidebar-row="sent"]');
        $archive = $page->filter('#sidebar [data-sidebar-row="archive"]');

        self::assertCount(1, $sent);
        self::assertStringContainsString('pl-9', (string) $sent->attr('class'));
        self::assertCount(1, $archive);
        self::assertStringContainsString('px-3', (string) $archive->attr('class'));
        self::assertStringNotContainsString('pl-9', (string) $archive->attr('class'));
    }

    /**
     * A disclosure that opens onto nothing does nothing, so with every row up
     * top there is no More at all — not an empty one.
     */
    public function testMoreIsNotDrawnWhenNothingIsBehindIt(): void
    {
        $this->choose([]);

        $page = $this->client->request('GET', '/mail/inbox');

        self::assertCount(0, $page->filter('#sidebar [data-sidebar-more]'));
        self::assertSame(
            ['inbox', 'starred', 'sent', 'drafts', 'archive', 'trash'],
            $this->rowsIn($page, '#sidebar nav > [data-sidebar-row]'),
        );
    }

    /**
     * The same rule when something IS chosen but all of it is switched off in
     * label settings: only the choice is empty in effect, not in the bag.
     */
    public function testMoreIsNotDrawnWhenEverythingBehindItIsSwitchedOff(): void
    {
        $this->hide(LabelRole::Trash);

        $this->choose([SidebarRow::Trash]);

        $page = $this->client->request('GET', '/mail/inbox');

        self::assertCount(0, $page->filter('#sidebar [data-sidebar-more]'));
        self::assertNotContains('trash', $this->rowsIn($page, '#sidebar [data-sidebar-row]'));

        // The presence companion: switched back on it is drawn, behind More.
        $this->show(LabelRole::Trash);

        self::assertSame(['trash'], $this->layout('/mail/inbox')['more']);
    }

    /**
     * Placement decides where a row sits and never whether it exists. Spam and
     * Unread are absent until their label is switched on, Trash is switched
     * off, and none of that moves with the row.
     *
     * Hand-derived. Seeded: Spam on, Unread on, Trash off. Chosen behind More:
     * Unread and Archive. Present rows in sidebar order are inbox, unread,
     * starred, sent, drafts, archive, spam (trash is off; snoozed has nothing
     * snoozed). Unread and Archive are behind More; the rest are on top.
     */
    public function testPlacementNeverChangesWhichRowsExist(): void
    {
        $spam   = $this->seedLabel('Spam', LabelRole::Spam);
        $unread = $this->seedLabel('Unread', LabelRole::Unread);
        $this->hide(LabelRole::Trash);

        $this->choose([SidebarRow::Unread, SidebarRow::Archive]);

        self::assertSame(
            ['top' => ['inbox', 'starred', 'sent', 'drafts', 'spam'], 'more' => ['unread', 'archive']],
            $this->layout('/mail/inbox'),
        );

        // Switch the two labels off and the same choice shows neither row: the
        // choice alone never conjures one.
        $this->setVisible($spam, false);
        $this->setVisible($unread, false);

        self::assertSame(
            ['top' => ['inbox', 'starred', 'sent', 'drafts'], 'more' => ['archive']],
            $this->layout('/mail/inbox'),
        );
    }

    /**
     * What makes a row a drop target or a marker is the row's, not the
     * group's. A move that rebuilt the markup for one placement and not the
     * other would leave a row that takes drops on top and refuses them behind
     * More, which no test of "is it in the list" would notice.
     *
     * Archive carries a folder id once the user has an Archive label; Starred
     * and Unread carry their own markers.
     */
    public function testDropTargetsAndMarkersSurviveEitherPlacement(): void
    {
        $archive = $this->seedLabel('Archive', LabelRole::Archive);
        $this->seedLabel('Unread', LabelRole::Unread);

        $markers = [
            'archive' => ['data-dnd-folder', (string) $archive->id],
            'starred' => ['data-dnd-star', ''],
            'unread'  => ['data-dnd-unread', ''],
        ];

        $placements = [
            // Today's: Archive behind More, Starred and Unread on top.
            [SidebarRow::Archive],
            // Reversed: Archive on top, Starred and Unread behind More.
            [SidebarRow::Starred, SidebarRow::Unread],
        ];

        foreach ($placements as $behind) {
            $this->choose($behind);

            $page = $this->client->request('GET', '/mail/inbox');

            foreach ($markers as $row => [$attribute, $value]) {
                $node = $page->filter('#sidebar [data-sidebar-row="' . $row . '"]');

                self::assertCount(1, $node, $row . ' is not in the sidebar');
                self::assertSame($value, $node->attr($attribute), $row . ' lost ' . $attribute);
            }
        }
    }

    /**
     * Each row's badge is the row's own and goes with it.
     *
     * What the nine hand-written rows did, kept when they became one macro, and
     * written here from those rows rather than from the macro: Inbox, Starred,
     * Archive and Spam link their number to their own unread view; Unread and
     * Sent show a plain unread number; Drafts and Trash show a TOTAL, not an
     * unread count; and Spam's is the quiet one, because unread junk is not a
     * call to action. Asserted in all three layouts so that neither group is
     * the one that happens to be right.
     *
     * Snoozed has the same shape as Archive and is left out only because
     * showing it takes a snoozed conversation.
     */
    public function testEveryRowKeepsItsBadgeWhereverItSits(): void
    {
        $this->seedLabel('Spam', LabelRole::Spam);
        $this->seedLabel('Unread', LabelRole::Unread);

        $router     = static::getContainer()->get(RouterInterface::class);
        $unreadView = static fn (SidebarRow $row): string => $router->generate($row->route(), ['unread' => 1]);

        // count key => [element, badge kind, href, quiet]
        $expected = [
            'role:inbox'   => ['a', 'unread', $unreadView(SidebarRow::Inbox), false],
            'unread'       => ['span', 'unread', null, false],
            'starred'      => ['a', 'unread', $unreadView(SidebarRow::Starred), false],
            'role:sent'    => ['span', 'unread', null, false],
            'role:drafts'  => ['span', 'total', null, false],
            'role:archive' => ['a', 'unread', $unreadView(SidebarRow::Archive), false],
            'role:spam'    => ['a', 'unread', $unreadView(SidebarRow::Spam), true],
            'role:trash'   => ['span', 'total', null, false],
        ];

        $offered = array_values(array_filter(
            SidebarRow::cases(),
            static fn (SidebarRow $row): bool => false === $row->isPinned(),
        ));

        foreach ([[], SidebarRow::defaultBehindMore(), $offered] as $behind) {
            $this->choose($behind);

            $page = $this->client->request('GET', '/mail/inbox');

            foreach ($expected as $key => [$element, $kind, $href, $quiet]) {
                $badge = $page->filter('#sidebar [data-count-key="' . $key . '"]');
                $where = $key . ' with ' . count($behind) . ' rows behind More';

                self::assertCount(1, $badge, $where . ': no such badge');
                self::assertSame($element, $badge->nodeName(), $where);
                self::assertSame($kind, $badge->attr('data-badge-kind'), $where);
                self::assertSame($href, $badge->attr('href'), $where);
                self::assertSame($quiet, str_contains((string) $badge->attr('class'), 'bg-ink-faint/20'), $where . ': quiet');
            }
        }
    }

    /**
     * More comes back open when you are somewhere inside it — the sidebar is
     * re-rendered on every navigation, so a closed one would snap shut on the
     * row just clicked. "Inside it" is whatever the person put there.
     */
    public function testMoreIsOpenOnAPageInsideItAndShutElsewhere(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);

        $isOpen = fn (string $path): bool => null !== $this->client->request('GET', $path)
            ->filter('#sidebar [data-sidebar-more]')->attr('open');

        $this->choose([SidebarRow::Sent]);

        self::assertTrue($isOpen($router->generate('app_mail_sent')), 'Sent is behind More and is the open page');
        self::assertFalse($isOpen($router->generate('app_mail_inbox')));
        // Archive is not behind More any more: being on it must not open it.
        self::assertFalse($isOpen($router->generate('app_mail_archive')));

        // And the default still opens on Archive, which is behind More there.
        $this->choose(SidebarRow::defaultBehindMore());

        self::assertTrue($isOpen($router->generate('app_mail_archive')));
    }

    public function testEveryRowRouteExists(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);

        foreach (SidebarRow::cases() as $row) {
            self::assertNotSame('', $router->generate($row->route()), $row->value);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * The sidebar's system rows, split into the two groups, from the desktop
     * rail's copy. `#sidebar nav > …` is the top group, which are direct
     * children of the nav; the More group sits inside its <details>.
     *
     * @return array{top: list<string>, more: list<string>}
     */
    private function layout(string $path): array
    {
        $page = $this->client->request('GET', $path);

        return [
            'top'  => $this->rowsIn($page, '#sidebar nav > [data-sidebar-row]'),
            'more' => $this->rowsIn($page, '#sidebar [data-sidebar-more] [data-sidebar-row]'),
        ];
    }

    /** @return list<string> */
    private function rowsIn(Crawler $page, string $selector): array
    {
        return $page->filter($selector)->each(static fn (Crawler $row): string => (string) $row->attr('data-sidebar-row'));
    }

    /**
     * Writes the choice through a FRESH copy of the user.
     *
     * Every request resets the entity manager, so the User this test created
     * is detached the moment the first page has been fetched — and a flush
     * after that stores nothing, silently, which reads as the app ignoring the
     * setting. Found the hard way: the first change in a test lands, the next
     * does not.
     *
     * @param list<SidebarRow> $rows
     */
    private function choose(array $rows): void
    {
        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        $user->setSidebarMoreRows($rows);
        $this->em->flush();
    }

    /** Same reason as choose(): the label is looked up again rather than trusted. */
    private function setVisible(Label $label, bool $visible): void
    {
        $fresh = $this->em->find(Label::class, $label->id);

        self::assertInstanceOf(Label::class, $fresh);

        $fresh->isVisible = $visible;
        $this->em->flush();
    }

    /** The user's label for a role, switched off — created if they have none. */
    private function hide(LabelRole $role): void
    {
        $label = $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user->id, 'role' => $role])
            ?? $this->seedLabel(ucfirst($role->value), $role);

        $this->setVisible($label, false);
    }

    private function show(LabelRole $role): void
    {
        $label = $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user->id, 'role' => $role]);

        self::assertInstanceOf(Label::class, $label);

        $this->setVisible($label, true);
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): void
    {
        $token = (string) $this->settings()
            ->filter('form[action="/settings/sidebar/more"] input[name="_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/settings/sidebar/more', ['_token' => $token, ...$fields]);
    }

    private function settings(): Crawler
    {
        return $this->client->request('GET', '/settings?section=general');
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->find($this->user->id);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
