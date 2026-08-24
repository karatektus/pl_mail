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
 * A category tab keeps the filter you are looking through.
 *
 * The unread badges in the sidebar are links — clicking one opens that view
 * narrowed to `?unread=1` — and the toolbar grew a chip saying so, with a "Show
 * all" beside it that rebuilds the URL keeping every other narrowing intact,
 * `?tab=` included.
 *
 * The tabs did not do the mirror image of that. Each one linked to
 * `?tab=<category>` and nothing else, so reaching Social from an unread inbox
 * answered with ALL of Social — silently, since the chip went with it, and the
 * only way back into unread was the sidebar badge again. It made the two
 * dimensions of this list mutually exclusive: you could filter, or you could
 * change tab, and doing the second undid the first.
 *
 * The pairing is what is asserted rather than the literal query string, since
 * what matters is that whatever narrows the list survives the click.
 */
final class InboxTabLinksTest extends WebTestCase
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

        // Two categories, because a lone Primary tab is deliberately suppressed
        // — "a heading pretending to be a choice" — and there would be no strip
        // to assert on.
        $this->thread('a promo', category: MessageCategory::Promotions, unread: 1);
        $this->thread('a normal one', unread: 1);
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testATabClickedInsideTheUnreadViewStaysInTheUnreadView(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox?unread=1');

        self::assertResponseIsSuccessful();

        $hrefs = $this->tabHrefs($crawler);

        self::assertNotSame([], $hrefs, 'no tab strip rendered, so nothing was tested');

        foreach ($hrefs as $href) {
            self::assertStringContainsString(
                'unread=1',
                $href,
                sprintf('the tab link "%s" drops the unread filter it is being clicked inside', $href),
            );
        }
    }

    /** And it must not invent one where there was none. */
    public function testTabsInAnUnfilteredInboxCarryNoFilter(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        foreach ($this->tabHrefs($crawler) as $href) {
            self::assertStringNotContainsString('unread', $href, $href);
        }
    }

    /**
     * Page four of Primary is not page four of Promotions, so the one parameter
     * that must NOT survive a tab change is the one the rest of this is about
     * keeping.
     */
    public function testChangingTabStartsAtTheFirstPage(): void
    {
        // page=1 rather than a later one: out-of-range pages redirect, and a
        // redirect has no tab strip in it — the assertion loop would then run
        // over nothing and pass by doing nothing, which is worse than failing.
        $crawler = $this->client->request('GET', '/mail/inbox?unread=1&page=1');

        $hrefs = $this->tabHrefs($crawler);

        self::assertNotSame([], $hrefs, 'no tab strip rendered, so nothing was tested');

        foreach ($hrefs as $href) {
            self::assertStringNotContainsString('page=', $href, $href);
            self::assertStringContainsString('unread=1', $href, $href);
        }
    }

    /**
     * @return list<string>
     */
    private function tabHrefs(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler
            ->filter('[data-list-region="tabs"] a[href*="/mail/inbox"]')
            ->each(static fn ($node): string => (string) $node->attr('href'));
    }
}
