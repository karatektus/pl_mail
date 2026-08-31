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

/**
 * Under the unread filter, the tab strip has to say WHERE the unread mail is.
 *
 * The report, with a screenshot: "Unread only" on, Primary selected, the list
 * reading "No messages in this tab" — and the sidebar's Inbox badge reading 66
 * beside it. Both were true. The 66 was sitting in Social and Updates, and
 * nothing on the strip distinguished those tabs from the empty one being looked
 * at, so the only way to find the mail was to click every tab in turn.
 *
 * The reasoning that had kept unread off the tabs is sound and still holds in
 * the ordinary inbox: unread is already said by the bold rows and by the
 * sidebar badge, so a number on a tab would be a third voice saying it. Filtered
 * to unread that reasoning inverts. Every row on screen is unread, so boldness
 * separates nothing; and the badge is one total with no way to name a tab.
 *
 * So the strip gets the one statement nothing else can make, in the one place
 * it is needed, and makes it without a number: the icon wears its category's
 * colour when there is unread mail behind it. What these tests hold:
 *
 *   - the tint appears only where there IS unread, and only under the filter;
 *   - a tinted tab opens on mail — the tint and the list cannot disagree, which
 *     is the original bug restated as an invariant;
 *   - every tinted icon is patchable from the counts endpoint, the same rule
 *     NewMailMarkerTest holds for the dots and the sender hints.
 */
final class InboxTabTintTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** The green half of the promotions pair — the light-theme value. */
    private const string PROMOTIONS_TONE = 'text-green-600';
    private const string SOCIAL_TONE     = 'text-blue-600';

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
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The reported situation, arranged exactly: the tab being looked at holds
     * nothing unread, and the mail is somewhere else on the strip.
     */
    public function testOnlyTheTabsHoldingUnreadAreTinted(): void
    {
        $this->thread('read promo', category: MessageCategory::Promotions, unread: 0);
        $this->thread('unread chatter', category: MessageCategory::Social, unread: 3);

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        self::assertResponseIsSuccessful();

        self::assertStringContainsString(
            self::SOCIAL_TONE,
            $this->iconClass($crawler, MessageCategory::Social),
            'Social is holding the unread mail and is the tab that must say so',
        );

        self::assertStringNotContainsString(
            self::PROMOTIONS_TONE,
            $this->iconClass($crawler, MessageCategory::Promotions),
            'Promotions has only read mail — a tint here is the empty-tab trap again',
        );
    }

    /**
     * The invariant the bug was a violation of. A tinted tab that opens on
     * "No messages in this tab" is worse than no tint at all, so the count
     * behind the colour is read through the same filter the list paginates
     * with.
     */
    public function testATintedTabOpensOnMail(): void
    {
        $this->thread('unread chatter', category: MessageCategory::Social, unread: 2);
        $this->thread('read promo', category: MessageCategory::Promotions, unread: 0);

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        $tinted = [];

        foreach (MessageCategory::cases() as $category) {
            $class = $this->iconClass($crawler, $category);

            if ('' !== $class && true === str_contains($class, 'text-')) {
                $tinted[] = $category;
            }
        }

        self::assertNotEmpty($tinted, 'the fixture puts unread on the strip, so something must be tinted');

        foreach ($tinted as $category) {
            $opened = $this->client->request('GET', '/mail/inbox?unread=1&tab=' . $category->value);

            self::assertGreaterThan(
                0,
                $opened->filter('[data-thread-select]')->count(),
                sprintf('the %s tab is tinted and must open on mail', $category->value),
            );
        }
    }

    /**
     * The tab being looked at is never tinted, however much unread it holds.
     *
     * Reported on sight: Primary tinted red while it was the tab in focus, and
     * it read as breakage rather than as a mark. Two reasons, and the first is
     * the stronger. The tint answers "where is the mail I cannot see", and the
     * active tab is the one place that question is already answered by the list
     * underneath it. The second is that the active tab is accent-coloured
     * throughout — label, underline, filled glyph — so an identity colour
     * dropped into the middle of it looks like a fault.
     */
    public function testTheTabBeingLookedAtIsNeverTinted(): void
    {
        $this->thread('unread here', category: MessageCategory::Primary, unread: 4);
        $this->thread('unread there', category: MessageCategory::Social, unread: 1);

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        self::assertSame(
            0,
            $crawler->filter('[data-tab-icon][data-count-key="category:primary"]')->count(),
            'Primary is the active tab — it carries no tint and nothing for the patcher to tint',
        );

        self::assertStringNotContainsString(
            'text-red-600',
            $this->iconClass($crawler, MessageCategory::Primary),
            'and its glyph stays the accent colour the rest of the active tab wears',
        );

        // The same render, so this also proves the exemption is about focus
        // rather than about the filter having quietly stopped working.
        self::assertStringContainsString(
            self::SOCIAL_TONE,
            $this->iconClass($crawler, MessageCategory::Social),
            'the tabs not being looked at still say where the mail is',
        );

        // And it moves with the focus rather than being pinned to Primary.
        $crawler = $this->client->request('GET', '/mail/inbox?unread=1&tab=social');

        self::assertSame(
            0,
            $crawler->filter('[data-tab-icon][data-count-key="category:social"]')->count(),
            'Social is the active tab now, so it is the one left alone',
        );

        self::assertStringContainsString(
            'text-red-600',
            $this->iconClass($crawler, MessageCategory::Primary),
            'and Primary, no longer in focus, is free to say it is holding unread',
        );
    }

    /**
     * A conversation in the bin tints nothing, and this is the case that was
     * reported rather than a hypothetical.
     *
     * Binning a thread ADDS the Trash label; it does not take the Inbox one
     * away. Every unified-inbox *list* has always excluded trashed threads, and
     * the counts beside it had not — so the strip lit Primary for a thread the
     * list underneath refused to show, on a mailbox whose sidebar badge said
     * there was one unread and whose tabs claimed two.
     *
     * That is the Trash-badge-versus-list disagreement BadgeSemanticsTest was
     * written for, reaching the tab strip by another road, and it is why the
     * tint reads through the same exclusion the list does.
     */
    public function testAThreadInTheBinTintsNothing(): void
    {
        $trash   = $this->seedLabel('Trash', LabelRole::Trash);
        $binned  = $this->thread('binned but unread', category: MessageCategory::Promotions, unread: 1);

        // Exactly the shape the bin leaves behind: both labels, not a swap.
        $binned->addLabel($trash);
        $this->em->flush();

        $this->thread('genuinely unread', category: MessageCategory::Social, unread: 1);

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        self::assertStringNotContainsString(
            self::PROMOTIONS_TONE,
            $this->iconClass($crawler, MessageCategory::Promotions),
            'the only unread thing in Promotions is in the bin, so the tab has nothing to say',
        );

        self::assertStringContainsString(
            self::SOCIAL_TONE,
            $this->iconClass($crawler, MessageCategory::Social),
            'and the tab that really is holding unread still says so',
        );

        // The counts endpoint has to agree, or the first sync puts the colour
        // back on a tab the server just refused to paint.
        $counts = $this->countsPayload();

        self::assertSame(0, $counts['category:promotions'], 'a binned thread is not unread inbox mail');
        self::assertSame(1, $counts['category:social']);
    }

    /**
     * The ordinary inbox is tinted too, and that is a decision rather than an
     * accident of implementation.
     *
     * The unread filter is the case that asked for the tint, but "which tabs
     * have anything waiting" is worth answering before you have filtered for
     * it — and a strip that meant one thing under the filter and nothing
     * outside it would be harder to read than one that always means the same
     * thing.
     */
    public function testTheUnfilteredInboxIsTintedToo(): void
    {
        $this->thread('unread chatter', category: MessageCategory::Social, unread: 3);
        $this->thread('read promo', category: MessageCategory::Promotions, unread: 0);

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertStringContainsString(
            self::SOCIAL_TONE,
            $this->iconClass($crawler, MessageCategory::Social),
            'the tint does not wait for the unread filter',
        );

        // Still a statement about unread rather than about existence: a
        // category with only read mail keeps its tab and loses its colour.
        self::assertStringNotContainsString(
            self::PROMOTIONS_TONE,
            $this->iconClass($crawler, MessageCategory::Promotions),
            'read mail is not something to point at',
        );
    }

    /**
     * The rule NewMailMarkerTest holds for the dots and the sender hints: what
     * the server rendered, the endpoint the sidebar patches from has to be able
     * to speak about, or the first sync leaves a colour nothing can correct.
     */
    public function testEveryTintedIconIsPatchableFromTheCountsPayload(): void
    {
        $this->thread('unread chatter', category: MessageCategory::Social, unread: 1);
        $this->thread('read promo', category: MessageCategory::Promotions, unread: 0);

        $counts = $this->countsPayload();

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        $keys = $crawler->filter('[data-tab-icon]')->each(
            static fn ($icon): string => (string) $icon->attr('data-count-key'),
        );

        self::assertNotEmpty($keys, 'the icons are rendered whatever the count, so they can be re-tinted');

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $counts, sprintf('the endpoint emits no "%s"', $key));
        }

        self::assertSame(1, $counts['category:social']);
        self::assertSame(0, $counts['category:promotions']);
    }

    /**
     * The tone the patcher puts back has to be the tone the server put on, or a
     * sync would repaint a tab in a colour it never wore.
     */
    public function testTheIconCarriesTheToneThePatcherWillReapply(): void
    {
        $this->thread('unread chatter', category: MessageCategory::Social, unread: 1);

        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        $icon = $crawler->filter('[data-tab-icon][data-count-key="category:social"]')->first();

        self::assertGreaterThan(0, $icon->count(), 'Social is on the strip');

        $tone = (string) $icon->attr('data-tone-class');

        self::assertStringContainsString(self::SOCIAL_TONE, $tone);

        foreach (explode(' ', $tone) as $class) {
            self::assertStringContainsString(
                $class,
                (string) $icon->attr('class'),
                'a tinted icon wears every class the patcher would toggle',
            );
        }
    }

    private function iconClass(\Symfony\Component\DomCrawler\Crawler $crawler, MessageCategory $category): string
    {
        $icon = $crawler->filter(sprintf('[data-tab-icon][data-count-key="category:%s"]', $category->value));

        return 0 === $icon->count() ? '' : (string) $icon->first()->attr('class');
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
