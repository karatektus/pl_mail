<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The inbox of somebody who has a tab of their own.
 *
 * What is pinned is what the reader sees and can do with it: the tab exists
 * while it holds mail and not before (the strip's own rule), it lists its own
 * conversations and the built-in tab it left no longer does, an empty one is
 * still somewhere to drop a conversation, and a key that is not theirs opens the
 * inbox rather than another person's tab or an error.
 *
 * The counts endpoint is here because the strip patches itself from it after
 * every sync, by key: a tab drawn with no matching key in that payload is a tab
 * whose dot and number never update.
 */
final class InboxCustomTabTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private InboxCategory $shipping;

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

        $this->shipping = $this->category($this->user, 'Shipping');
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testACategoryHoldingMailIsATabOfItsOwnAndListsItsMail(): void
    {
        $this->filed('a carrier notice', $this->shipping);
        $this->thread('an ordinary promotion', MessageCategory::Promotions, unread: 1);
        $this->thread('a normal one', unread: 1);

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $strip = $crawler->filter('[data-list-region="tabs"]');
        self::assertStringContainsString('Shipping', $strip->text(), 'a category holding mail has no tab');
        self::assertContains('tab=c' . $this->shipping->id, $this->tabQueries($crawler));

        $this->client->request('GET', '/mail/inbox?tab=c' . $this->shipping->id);

        self::assertResponseIsSuccessful();
        $own = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('a carrier notice', $own);
        self::assertStringNotContainsString('an ordinary promotion', $own);
        self::assertStringNotContainsString('a normal one', $own);
    }

    /**
     * The conversation is in ONE tab. Promotions must not list the notice that
     * has a tab of its own, though its built-in category is still Promotions —
     * and it must still list the promotion that has none.
     */
    public function testTheBuiltInTabNoLongerListsWhatLeftItForTheReadersOwn(): void
    {
        $this->filed('a carrier notice', $this->shipping, MessageCategory::Promotions);
        $this->thread('an ordinary promotion', MessageCategory::Promotions, unread: 1);

        $this->client->request('GET', '/mail/inbox?tab=promotions');

        self::assertResponseIsSuccessful();
        $promotions = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('an ordinary promotion', $promotions);
        self::assertStringNotContainsString('a carrier notice', $promotions, 'a conversation is listed in two tabs');
    }

    public function testACategoryHoldingNothingHasNoTabButIsStillSomewhereToDrop(): void
    {
        $this->thread('a normal one', unread: 1);
        $this->thread('a promo', MessageCategory::Promotions, unread: 1);

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertNotContains('tab=c' . $this->shipping->id, $this->tabQueries($crawler), 'an empty category was drawn as a tab');

        $ghost = $crawler->filter('[data-dnd-category="c' . $this->shipping->id . '"][data-dnd-ghost]');
        self::assertCount(1, $ghost, 'an empty category cannot be dropped onto');
        self::assertStringContainsString('Shipping', $ghost->text());
    }

    public function testAKeyThatIsNotTheirsOpensTheInboxRatherThanSomebodyElsesTab(): void
    {
        $stranger = $this->category($this->seedUser(), 'Not yours');
        $this->thread('a normal one', unread: 1);

        $this->client->request('GET', '/mail/inbox?tab=c' . $stranger->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('a normal one', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCountsEndpointCarriesTheReadersTabForTheStripToPatch(): void
    {
        $this->filed('a carrier notice', $this->shipping, MessageCategory::Promotions, unread: 1);

        $this->client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $key     = 'c' . $this->shipping->id;

        self::assertSame(1, $payload['category:' . $key] ?? null, 'the unread count of the tab is missing');
        // The values, not only the keys: the thread is unread and just arrived,
        // so it is new, and the hint names who it is from.
        self::assertSame(1, $payload['new:category:' . $key] ?? null);
        self::assertSame('sender@example.test', $payload['senders:category:' . $key] ?? null);
        self::assertSame(0, $payload['new:category:promotions'] ?? null, 'the built-in tab it left is still counting it as new');
        // And the built-in it was filed away from is not counting it.
        self::assertSame(0, $payload['category:promotions'] ?? null);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @return list<string> the query string of every tab link in the strip */
    private function tabQueries(Crawler $crawler): array
    {
        return $crawler
            ->filter('[data-list-region="tabs"] a[href*="/mail/inbox"]')
            ->each(static fn (Crawler $node): string => (string) parse_url((string) $node->attr('href'), PHP_URL_QUERY));
    }

    private function filed(
        string $subject,
        InboxCategory $category,
        MessageCategory $builtIn = MessageCategory::Promotions,
        int $unread = 1,
    ): MessageThread {
        $thread               = $this->thread($subject, $builtIn, unread: $unread);
        $thread->userCategory = $category;
        $this->em->flush();

        return $thread;
    }

    private function category(User $owner, string $name): InboxCategory
    {
        $category       = new InboxCategory();
        $category->usr  = $owner;
        $category->name = $name;
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }
}
