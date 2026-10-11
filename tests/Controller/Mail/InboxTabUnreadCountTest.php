<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every category tab says how many conversations it holds unread — the same
 * number the sidebar's Inbox badge adds up.
 *
 * The report: the Inbox badge read 1, and clicking it opened Primary with
 * nothing unread in it. The one unread conversation was in Promotions. The tab
 * carried a colour on its icon, which says "something is here" to somebody who
 * knows what the colour means and nothing at all to somebody who does not; the
 * number on the badge had no counterpart on the strip to find it by.
 *
 * Fixture, with the figures worked out from it rather than read off the page:
 *
 *   Primary    two unread conversations (holding 1 and 5 unread messages) and
 *              one read one                              → 2, NOT 6 messages
 *   Social     one unread conversation (3 unread messages) → 1
 *   Promotions one read conversation                       → 0, badge hidden
 *
 * The sidebar's Inbox badge counts conversations too, so it is 2 + 1 + 0 = 3.
 * That equality is the invariant: the badge and the strip are two views of one
 * number, and a strip that adds up to something else is the original report
 * again.
 */
final class InboxTabUnreadCountTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);

        $this->thread('primary unread one', category: MessageCategory::Primary, unread: 1);
        $this->thread('primary unread five', category: MessageCategory::Primary, unread: 5);
        $this->thread('primary read', category: MessageCategory::Primary, unread: 0);
        $this->thread('social unread', category: MessageCategory::Social, unread: 3);
        $this->thread('promo read', category: MessageCategory::Promotions, unread: 0);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEveryTabShowsTheNumberOfConversationsHoldingUnread(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        self::assertSame('2', $this->badgeText($crawler, MessageCategory::Primary), 'two unread conversations, not the six messages in them');
        self::assertSame('1', $this->badgeText($crawler, MessageCategory::Social));
        self::assertTrue($this->badgeVisible($crawler, MessageCategory::Primary));
        self::assertTrue($this->badgeVisible($crawler, MessageCategory::Social));

        // The presence companion to the two above: a tab holding only read mail
        // keeps its badge element for the patcher but does not show it. An
        // always-hidden badge would pass every assertion that only looks at the
        // tabs that have something to say.
        self::assertSame(1, $crawler->filter($this->badgeSelector(MessageCategory::Promotions))->count(), 'rendered, so a sync can reveal it');
        self::assertFalse($this->badgeVisible($crawler, MessageCategory::Promotions), 'and hidden while there is nothing unread');
    }

    /**
     * The tab being looked at is the case that was left out of the colour on
     * purpose, and the number does not follow it there: Primary is open, holds
     * two unread, and says so — the sidebar says "Inbox 3" beside it while the
     * list shows two of them.
     */
    public function testTheTabBeingLookedAtShowsItsNumberToo(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame('2', $this->badgeText($crawler, MessageCategory::Primary), 'Primary is the active tab');
        self::assertTrue($this->badgeVisible($crawler, MessageCategory::Primary));

        $crawler = $this->client->request('GET', '/mail/inbox?tab=social');

        self::assertSame('1', $this->badgeText($crawler, MessageCategory::Social), 'Social is the active tab now');
        self::assertTrue($this->badgeVisible($crawler, MessageCategory::Social));
        self::assertSame('2', $this->badgeText($crawler, MessageCategory::Primary), 'and the number does not leave Primary when it is no longer open');
    }

    public function testTheTabsAddUpToTheSidebarInboxBadge(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        $sum = 0;

        foreach (MessageCategory::cases() as $category) {
            $sum += (int) $this->badgeText($crawler, $category);
        }

        self::assertSame(3, $sum, '2 + 1 + 0, worked out from the fixture');
        self::assertSame(3, $this->countsPayload()['role:inbox'], 'and that is what the sidebar badge is told');
    }

    /**
     * The binned thread is the one that made the tint lie (see
     * InboxTabTintTest), and a number would lie louder.
     */
    public function testAThreadInTheBinIsNotCounted(): void
    {
        $trash  = $this->seedLabel('Trash', LabelRole::Trash);
        $binned = $this->thread('promo binned but unread', category: MessageCategory::Promotions, unread: 1);

        // Both labels, as binning leaves it — not a swap.
        $binned->addLabel($trash);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertFalse($this->badgeVisible($crawler, MessageCategory::Promotions), 'the only unread thing in Promotions is in the bin');
        self::assertSame('1', $this->badgeText($crawler, MessageCategory::Social), 'while a tab that really holds unread still says so');
    }

    /**
     * What the server rendered, the endpoint a sync patches from has to be able
     * to say again — and say the same: a badge nothing can correct goes on
     * showing yesterday's number after the first mail is read.
     */
    public function testEveryBadgeIsPatchableFromTheCountsPayload(): void
    {
        $counts  = $this->countsPayload();
        $crawler = $this->client->request('GET', '/mail/inbox');

        $badges = $crawler->filter('[data-tab-unread]');

        self::assertGreaterThan(0, $badges->count());

        $badges->each(function (Crawler $badge) use ($counts): void {
            $key = (string) $badge->attr('data-count-key');

            self::assertArrayHasKey($key, $counts, sprintf('the endpoint emits no "%s"', $key));
            self::assertSame((string) $counts[$key], trim($badge->text()), sprintf('"%s" is not the number the server rendered', $key));
        });
    }

    /**
     * A bare "2" on a tab is a number without a noun, so a screen reader is
     * given the sentence the sidebar's own badge uses — and the patcher the
     * template to rewrite it with, or a sync leaves "2 unread" on a tab that
     * now holds five.
     */
    public function testTheBadgeIsLabelledAndCarriesTheTemplateThePatcherReapplies(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        $badge = $crawler->filter($this->badgeSelector(MessageCategory::Primary))->first();

        self::assertSame('2 unread', $badge->attr('aria-label'));
        self::assertSame('%count% unread', $badge->attr('data-label-template'));
    }

    public function testTheUnreadOnlyViewShowsTheNumbersToo(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        self::assertSame('2', $this->badgeText($crawler, MessageCategory::Primary));
        self::assertSame('1', $this->badgeText($crawler, MessageCategory::Social));
    }

    public function testNobodyWithTheTabsSwitchedOffGetsABadge(): void
    {
        // Presence first: the same user, the same mailbox, tabs on.
        $crawler = $this->client->request('GET', '/mail/inbox');
        self::assertGreaterThan(0, $crawler->filter('[data-tab-unread]')->count());

        $this->user->categorySorting->tabs = false;
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame(0, $crawler->filter('[data-tab-unread]')->count(), 'there is no strip to carry one');
    }

    private function badgeSelector(MessageCategory $category): string
    {
        return sprintf('[data-tab-unread][data-count-key="category:%s"]', $category->value);
    }

    private function badgeText(Crawler $crawler, MessageCategory $category): string
    {
        $badge = $crawler->filter($this->badgeSelector($category));

        return 0 === $badge->count() ? '(no badge)' : trim($badge->first()->text());
    }

    private function badgeVisible(Crawler $crawler, MessageCategory $category): bool
    {
        $badge = $crawler->filter($this->badgeSelector($category));

        if (0 === $badge->count()) {
            return false;
        }

        return false === in_array('hidden', explode(' ', (string) $badge->first()->attr('class')), true);
    }

    /** @return array<string,int> */
    private function countsPayload(): array
    {
        $this->client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
