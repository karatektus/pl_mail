<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The sidebar's LABELS and ACCOUNTS sections collapse, and the state is the
 * user's rather than the browser's.
 *
 * The thing actually pinned down here is that the state is RENDERED, not
 * applied afterwards. A disclosure that comes back from the server open and is
 * shut by JavaScript on connect looks identical in a DOM assertion taken after
 * the page settles, and it is the version that makes the sidebar snap closed on
 * every navigation — which is the whole reason this preference moved off
 * localStorage. So the assertions are made against the raw HTML the controller
 * returned, where "no `open` attribute" can only have come from the server.
 */
final class SidebarSectionCollapseTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        parent::tearDown();
    }

    public function testTheSectionsAreExpandedUntilTheUserCollapsesThem(): void
    {
        [$client, $user] = $this->signedIn();

        $html = $this->inbox($client);

        self::assertStringContainsString(
            'data-collapse-key="' . User::SIDEBAR_SECTION_LABELS . '"',
            $html,
            'the labels heading is not a collapsible disclosure at all',
        );

        self::assertTrue(
            $this->isOpen($html, User::SIDEBAR_SECTION_LABELS),
            'an untouched sidebar must render its sections open',
        );
        self::assertTrue($this->isOpen($html, User::SIDEBAR_SECTION_ACCOUNTS));

        // Storing COLLAPSED rather than expanded is what makes this the default
        // without a backfill: the bag is empty for everyone who has never
        // touched it, including every user who existed before this shipped.
        self::assertSame([], $user->collapsedSidebarSections);
    }

    /**
     * The point of the feature, and the requirement that is easiest to satisfy
     * only halfway: `open` must be absent from the HTML itself.
     */
    public function testACollapsedSectionIsRenderedCollapsedRatherThanShutAfterPaint(): void
    {
        [$client, $user] = $this->signedIn();

        $this->begin();

        $user->setSidebarSectionCollapsed(User::SIDEBAR_SECTION_LABELS, true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $html = $this->inbox($client);

        self::assertFalse(
            $this->isOpen($html, User::SIDEBAR_SECTION_LABELS),
            'the collapsed section came back open — it would flash before JavaScript shut it',
        );

        // Only the one that was collapsed. A section toggle that took its
        // neighbour with it would be a shared flag, not a per-section one.
        self::assertTrue($this->isOpen($html, User::SIDEBAR_SECTION_ACCOUNTS));

        self::assertStringContainsString(
            'aria-expanded="false"',
            $html,
            'the disclosure must say it is collapsed, not merely look it',
        );
    }

    /**
     * A collapsed section still renders its badges, and the roll-up on the
     * heading is one the counts endpoint knows how to patch.
     *
     * Hidden rather than omitted is the rule the whole sidebar follows. Drop
     * the markup while a section is shut and refreshCounts() has nothing to
     * write into, so every number inside goes stale for as long as it is
     * closed and is wrong the moment it is reopened.
     */
    public function testACollapsedSectionKeepsBadgesThatTheCountsEndpointCanStillPatch(): void
    {
        [$client, $user] = $this->signedIn();

        $this->begin();

        $user->setSidebarSectionCollapsed(User::SIDEBAR_SECTION_LABELS, true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');

        $keys = $crawler->filter('[data-ui--sidebar-target="badge"]')->each(
            static fn ($node): string => (string) $node->attr('data-count-key'),
        );

        self::assertContains(
            'labels:unread',
            $keys,
            'the collapsed heading carries no roll-up badge',
        );

        $client->request('GET', '/mail/sidebar/counts');

        $counts = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($counts);

        // Every badge on the page, including the ones sealed inside the shut
        // section, has to have somewhere to get its next value from.
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $counts, sprintf('nothing patches "%s"', $key));
        }
    }

    /**
     * The roll-up never claims more than the rows it stands in for.
     *
     * Summing the per-label counts would have been the obvious implementation,
     * and it reports a thread filed under two labels twice — the collapsed
     * heading would then promise more unread than expanding it can show, which
     * is the specific way a rolled-up number stops being believed.
     *
     * Asserted as an inequality over whatever the seed happens to hold, in the
     * style BadgeSemanticsTest uses for the Trash invariant, so it keeps
     * meaning something as the fixture changes: the sum is the ceiling, and a
     * de-duplicated count sits at or below it. A SUM implementation exceeds it
     * the moment any thread carries two labels.
     */
    public function testTheRollUpCountsAConversationOnceHoweverManyLabelsItCarries(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/mail/inbox');

        $client->request('GET', '/mail/sidebar/counts');

        $counts = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($counts);
        self::assertArrayHasKey('labels:unread', $counts);

        $perLabelSum = 0;

        foreach ($counts as $key => $value) {
            // The per-label keys only: "label:<id>", not the account-scoped
            // "label:<id>:account:<id>" duplicates of the same mail.
            if (1 === preg_match('/^label:\d+$/', (string) $key)) {
                $perLabelSum += (int) $value;
            }
        }

        self::assertLessThanOrEqual(
            $perLabelSum,
            $counts['labels:unread'],
            'the roll-up exceeds the sum of the rows under it — it is double counting',
        );

        // And the badge on the page agrees with the endpoint, or the first
        // sync would silently change the number under the user.
        $rendered = $crawler->filter('[data-count-key="labels:unread"]')->first();

        self::assertSame(1, $rendered->count());
        self::assertSame((string) $counts['labels:unread'], trim($rendered->text()));
    }

    public function testThePersistEndpointRefusesAPostWithoutACsrfToken(): void
    {
        [$client, $user] = $this->signedIn();

        $this->begin();

        $client->request(
            'POST',
            '/mail/sidebar/section-collapsed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['key' => User::SIDEBAR_SECTION_LABELS, 'collapsed' => true]),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());

        self::assertSame(
            [],
            $this->reload()->collapsedSidebarSections,
            'a tokenless post wrote the preference anyway',
        );
    }

    /**
     * Stored on the USER, which is what "synced across devices" reduces to
     * here: a second client with its own cookie jar and no shared storage sees
     * the state the first one set.
     */
    public function testASecondSessionSeesTheStateTheFirstOneSet(): void
    {
        [$client, $user] = $this->signedIn();

        $this->begin();

        $user->setSidebarSectionCollapsed(User::SIDEBAR_SECTION_ACCOUNTS, true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertFalse($this->isOpen($this->inbox($client), User::SIDEBAR_SECTION_ACCOUNTS));

        // A second session: the cookie jar emptied, so the session that saw the
        // collapse is gone and a new one is issued at login. Nothing client-side
        // survives, which is the whole claim — and note the test client runs no
        // JavaScript at all, so there was never a localStorage for this to be
        // hiding in either.
        $client->getCookieJar()->clear();
        $client->loginUser($this->reload());

        self::assertFalse(
            $this->isOpen($this->inbox($client), User::SIDEBAR_SECTION_ACCOUNTS),
            'the preference did not follow the user into a new session',
        );
    }

    public function testAnUnknownOrOversizedKeyIsRejectedRatherThanStored(): void
    {
        [$client, $user] = $this->signedIn();

        $this->begin();

        foreach ([['key' => '', 'collapsed' => true], ['key' => str_repeat('x', 300), 'collapsed' => true], ['key' => 'section:labels', 'collapsed' => 'yes']] as $payload) {
            $client->request(
                'POST',
                '/mail/sidebar/section-collapsed',
                server: [
                    'CONTENT_TYPE'   => 'application/json',
                    'HTTP_X_CSRF_TOKEN' => $this->token($client),
                ],
                content: json_encode($payload),
            );

            self::assertSame(400, $client->getResponse()->getStatusCode());
        }

        self::assertSame([], $this->reload()->collapsedSidebarSections);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();

        // The kernel is rebooted between requests by default, which hands each
        // one a new connection — and the transaction these tests write their
        // preference inside lives on the first one, so the change would be
        // invisible to every request after it.
        $client->disableReboot();

        $user = $this->freshUser($client);

        $client->loginUser($user);

        return [$client, $user];
    }

    private function freshUser(KernelBrowser $client): User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        return $user;
    }

    /**
     * The user as the database now has them.
     *
     * A request boots its own container, so the instance the test is holding is
     * detached by the time the response comes back and refresh() on it throws.
     * The identity map is cleared first, or this hands back the same stale
     * object it was asked to replace.
     */
    private function reload(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function begin(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    private function inbox(KernelBrowser $client): string
    {
        $client->request('GET', '/mail/inbox');

        return (string) $client->getResponse()->getContent();
    }

    /**
     * Whether the disclosure carrying this key was SENT with `open` on it.
     *
     * The test client runs no JavaScript, so what the crawler holds is exactly
     * the markup the controller returned — an `open` here cannot have been put
     * there by a Stimulus connect(), which is the distinction the whole feature
     * turns on.
     *
     * The first match is the desktop rail; the drawer renders the same partial
     * a second time with the same keys, and both are asserted below.
     */
    private function isOpen(string $html, string $key): bool
    {
        $nodes = (new Crawler($html))->filter('details[data-collapse-key="' . $key . '"]');

        self::assertGreaterThan(0, $nodes->count(), sprintf('no disclosure for "%s" in the page', $key));

        $open = $nodes->each(static fn ($node): bool => null !== $node->attr('open'));

        // The desktop rail and the mobile drawer are one preference, so they
        // must not disagree about it in the same response.
        self::assertCount(
            1,
            array_unique($open),
            sprintf('the two sidebars rendered "%s" differently', $key),
        );

        return $open[0];
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/mail/inbox');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }
}
