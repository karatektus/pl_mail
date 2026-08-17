<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Repository\User\UserRepository;
use App\Service\Mail\SidebarCounts;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A badge and the list under it must be answering the same question.
 *
 * The report: the Trash badge said 188 while the list beneath it said "1–50 of
 * 193", and the badge was red — the same red as Inbox, which means "these want
 * you". Both numbers were true. The badge counted unread and the list counted
 * everything, and nothing said so.
 *
 * The rule chosen, and what these tests hold to:
 *
 *   - Everywhere else a badge counts UNREAD and wears the accent. Inbox,
 *     Starred, Archive, Snoozed, Spam and custom labels are places where unread
 *     means "new mail you have not seen".
 *   - Trash and Drafts count the TOTAL and are styled neutrally. Nobody triages
 *     a bin; the useful number is how much is in it, and a neutral badge says
 *     that without demanding anything.
 *
 * The consequence worth testing is that the Trash badge is now taken from the
 * same countForRole() the list header paginates with, so the two agree by
 * construction and 188-vs-193 cannot recur.
 */
final class BadgeSemanticsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }

    /**
     * @return array<string,int>
     */
    private function counts(KernelBrowser $client): array
    {
        $client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ── the rule, stated once ─────────────────────────────────────────────

    public function testTrashAndDraftsAreTheTotalRoles(): void
    {
        self::assertTrue(SidebarCounts::countsTotal(LabelRole::Trash));
        self::assertTrue(SidebarCounts::countsTotal(LabelRole::Drafts));

        // Everything else is an unread count. Spam especially: unread spam is
        // genuinely new spam, and the badge saying so is the point of it.
        self::assertFalse(SidebarCounts::countsTotal(LabelRole::Inbox));
        self::assertFalse(SidebarCounts::countsTotal(LabelRole::Spam));
        self::assertFalse(SidebarCounts::countsTotal(LabelRole::Archive));
        self::assertFalse(SidebarCounts::countsTotal(LabelRole::Sent));
    }

    /**
     * Sent shows no number at all, and the reason is worth stating where the
     * rule lives.
     *
     * Reported as "marking an inbox thread unread gives Gesendet a badge of 1".
     * It did, and the badge was the accent pill that means "these want you".
     * countUnreadPerRole() sums a THREAD's unread count into every role the
     * thread carries, and a thread's labels are the union of its messages' — so
     * answering a conversation gives the thread a Sent label while the unread
     * message in it is still the incoming one, in the Inbox.
     *
     * Sent is the only role this reaches. Trash and Drafts are totals and are
     * not summed this way; Archive and Spam are moves, so no thread holds them
     * and Inbox at once.
     *
     * @see SidebarCounts::SILENT_ROLES for why the answer is no badge rather
     *      than a better-computed one.
     */
    public function testSentCarriesNoUnreadBadgeForMailThatIsNotInIt(): void
    {
        $client = $this->fixtureClient();

        $sent = $this->seedLabel('Sent', LabelRole::Sent);

        // One conversation, one unread incoming message.
        $thread = $this->thread('QA-01 basic send', unread: 1);

        $counts = static::getContainer()->get(SidebarCounts::class);
        $counts->reset();

        self::assertSame(1, $counts->forRoleBadge(LabelRole::Inbox));
        self::assertSame(0, $counts->forRoleBadge(LabelRole::Sent), 'nothing has been sent yet');

        // Now it is answered. MessageSendService labels the reply Sent and
        // ThreadLabelSynchronizer unions the message labels onto the thread,
        // which is the state this reproduces.
        $thread->addLabel($sent);
        $this->em->flush();
        $counts->reset();

        self::assertSame(
            1,
            $counts->forRoleBadge(LabelRole::Inbox),
            'the unread mail has not moved — it is still the one in the Inbox',
        );
        self::assertSame(
            0,
            $counts->forRoleBadge(LabelRole::Sent),
            'and it is still not in Sent, so Sent has nothing to badge',
        );

        // The endpoint the sidebar patches from has to agree, or the first sync
        // would put the badge back.
        $payload = $this->countsFrom($client);

        self::assertSame(0, $payload['role:sent']);
        self::assertSame(
            0,
            $payload['new:role:sent'],
            'the dot rides on the same over-attribution and must be silent too',
        );
    }

    /**
     * Silence is not the same as a total, and the two rules must not be
     * confused: a Sent badge showing the count of everything ever sent would be
     * a number nobody asked for in the place the reported one was wrong.
     */
    public function testSentIsSilencedRatherThanTurnedIntoATotal(): void
    {
        self::assertFalse(SidebarCounts::badges(LabelRole::Sent));
        self::assertFalse(SidebarCounts::countsTotal(LabelRole::Sent));

        // Everything else still speaks, including the two totals.
        foreach ([LabelRole::Inbox, LabelRole::Trash, LabelRole::Drafts, LabelRole::Spam, LabelRole::Archive] as $role) {
            self::assertTrue(SidebarCounts::badges($role), $role->value . ' must keep its badge');
        }
    }

    /**
     * The reported mismatch, as an invariant: whatever the fixture holds, the
     * badge and the list total are the same number.
     */
    public function testTheTrashBadgeAgreesWithTheTrashListTotal(): void
    {
        $client = $this->signedIn();

        $counts = static::getContainer()->get(SidebarCounts::class);

        self::assertSame(
            $counts->totalForRole(LabelRole::Trash),
            $counts->forRoleBadge(LabelRole::Trash),
            'the Trash badge has to be the total the list paginates',
        );
    }

    /**
     * The endpoint the sidebar patches from must say the same thing the
     * server-rendered badge did, or the first sync silently changes the number
     * under the user.
     */
    public function testTheCountsEndpointUsesTheSameRuleAsTheBadges(): void
    {
        $client = $this->signedIn();
        $counts = $this->counts($client);

        $sidebar = static::getContainer()->get(SidebarCounts::class);

        self::assertSame($sidebar->forRoleBadge(LabelRole::Trash), $counts['role:trash']);
        self::assertSame($sidebar->forRoleBadge(LabelRole::Drafts), $counts['role:drafts']);
        self::assertSame($sidebar->forRoleBadge(LabelRole::Inbox), $counts['role:inbox']);
    }

    // ── styling ───────────────────────────────────────────────────────────

    /**
     * The Trash badge must not wear the accent, which is what made a total read
     * as an unread count demanding attention.
     */
    public function testTheTrashBadgeIsNotStyledAsUnread(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $badge = $client->getCrawler()->filter('[data-count-key="role:trash"]');

        self::assertGreaterThan(0, $badge->count(), 'the Trash badge should be in the sidebar');

        $classes = (string) $badge->first()->attr('class');

        self::assertStringNotContainsString('bg-accent', $classes, 'a total must not wear the unread accent');
        self::assertStringContainsString('text-ink-soft', $classes, 'it should read as neutral');
    }

    public function testTheInboxBadgeStillWearsTheAccent(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $badge = $client->getCrawler()->filter('[data-count-key="role:inbox"]');

        self::assertGreaterThan(0, $badge->count());
        self::assertStringContainsString('bg-accent', (string) $badge->first()->attr('class'));
    }

    /**
     * Drafts is the other total role and was never asserted, which is how it
     * came to be reported alongside Trash.
     */
    public function testTheDraftsBadgeIsNotStyledAsUnreadEither(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $classes = (string) $client->getCrawler()
            ->filter('[data-count-key="role:drafts"]')
            ->first()
            ->attr('class');

        self::assertStringNotContainsString('bg-accent', $classes);
        self::assertStringContainsString('text-ink-soft', $classes);
    }

    /**
     * The rule was right; saying so out loud was not.
     *
     * The follow-up report: "Entwürfe 5" and "Papierkorb 2" are totals, "Posteingang
     * 5" is an unread count, and all three were the same filled pill in the same
     * place at the same size. One was red and one was a faint grey, and a bare
     * number in a pill is a bare number — the shape said "badge", not "how many".
     * Colour alone is also the one channel a reader may not have.
     *
     * So the two kinds now differ in SHAPE as well as tone: a call to action is a
     * filled round pill, a tally is an outlined rectangle. Asserted as "the class
     * lists differ in the roundness", which is the part a person actually sees,
     * rather than as an exact string.
     */
    public function testATotalAndAnUnreadCountAreNotTheSameShape(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $crawler = $client->getCrawler();

        $unread = (string) $crawler->filter('[data-count-key="role:inbox"]')->first()->attr('class');
        $total  = (string) $crawler->filter('[data-count-key="role:trash"]')->first()->attr('class');

        self::assertStringContainsString('rounded-full', $unread, 'an unread count is a pill');
        self::assertStringNotContainsString('rounded-full', $total, 'a total must not be the same pill');
        self::assertStringContainsString('border', $total, 'and reads as an outlined tally instead');
    }

    /**
     * And to a screen reader, where colour and shape both count for nothing,
     * they were both simply the word "five".
     */
    public function testEveryBadgeSaysWhichKindOfNumberItIs(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $badges = $client->getCrawler()->filter('[data-ui--sidebar-target="badge"]');

        self::assertGreaterThan(0, $badges->count());

        $badges->each(static function ($node): void {
            $key  = (string) $node->attr('data-count-key');
            $kind = (string) $node->attr('data-badge-kind');
            $name = (string) $node->attr('aria-label');

            self::assertContains($kind, ['unread', 'total'], sprintf('"%s" has no badge kind', $key));
            self::assertNotSame('', $name, sprintf('"%s" is an unnamed number', $key));
            self::assertStringContainsString(
                trim((string) $node->text()),
                $name,
                sprintf('"%s" is named with a different number than it shows', $key),
            );
        });
    }

    /**
     * The kinds have to agree with the rule rather than being decorated by
     * hand — a Trash badge marked "unread" would be back to the reported fault
     * with an accessible name confirming it.
     */
    public function testTheBadgeKindsMatchTheTotalRolesRule(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $crawler = $client->getCrawler();

        foreach ([LabelRole::Trash, LabelRole::Drafts] as $role) {
            self::assertSame(
                'total',
                $crawler->filter('[data-count-key="role:' . $role->value . '"]')->first()->attr('data-badge-kind'),
                $role->value . ' counts the total, so it must say so',
            );
        }

        self::assertSame(
            'unread',
            $crawler->filter('[data-count-key="role:inbox"]')->first()->attr('data-badge-kind'),
        );
    }

    // ── the new-mail dots, which share this payload ───────────────────────

    /**
     * The dots answer a different question — "has anything arrived here that
     * you have not been shown" rather than "how much of this is unread" — but
     * they are patched by the same controller from the same response, so the
     * same rule applies: every marker in the markup must be findable in the
     * payload, or the first sync after a page load moves markers at random and
     * leaves the rest frozen at whatever the server last said.
     *
     * Asserted over whatever the fixture happens to hold, like the Trash
     * invariant above, so this keeps meaning something as the seed changes.
     */
    public function testEveryRenderedNewDotHasAKeyInTheCountsEndpoint(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $keys = $client->getCrawler()->filter('[data-new-dot]')->each(
            static fn ($node): string => (string) $node->attr('data-count-key'),
        );

        self::assertNotEmpty(
            $keys,
            'the dots are rendered at every count and hidden at zero — an omitted one cannot be patched',
        );

        $counts = $this->counts($client);

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $counts, sprintf('the endpoint emits no "%s"', $key));
        }
    }

    /**
     * And the two families stay apart. A dot key fed to the badge patcher would
     * print a bare number into a 6px circle; an unread count read as a dot
     * would light it for mail that is merely unread. The "new:" namespace is
     * what stops either, so it is asserted rather than assumed.
     */
    public function testTheNewDotKeysAreNamespacedAwayFromTheUnreadBadgeKeys(): void
    {
        $client = $this->signedIn();
        $counts = $this->counts($client);

        $client->request('GET', '/mail/inbox');

        $badgeKeys = $client->getCrawler()->filter('[data-ui--sidebar-target="badge"]')->each(
            static fn ($node): string => (string) $node->attr('data-count-key'),
        );

        foreach ($badgeKeys as $key) {
            self::assertStringStartsNotWith('new:', $key, 'an unread badge must never be fed a new-mail count');
        }

        foreach (array_keys($counts) as $key) {
            if (false === str_starts_with((string) $key, 'new:')) {
                continue;
            }

            self::assertNotContains($key, $badgeKeys);
        }
    }

    // ── the timeout, where the two could most easily disagree ─────────────

    /**
     * Newness now has a 24-hour ceiling, and a ceiling applied in one place and
     * not the other is exactly the 188-vs-193 fault this file exists for — only
     * worse, because a dot carries no number to argue with. A sidebar that
     * says "something arrived here" over a list that badges nothing sends the
     * user looking for mail that is not there.
     *
     * So: a mailbox holding one thread on each side of the window, and the
     * count endpoint asked to agree with the rows actually rendered.
     *
     * Against a fixture mailbox of its own rather than the shared admin seed,
     * because "one in, one out" is the whole point and cannot be arranged in a
     * mailbox whose contents this test did not choose.
     */
    public function testTheNewCountsHonourTheTimeoutThatTheBadgesDo(): void
    {
        $client = $this->fixtureClient();

        $this->thread('arrived just now');
        $this->thread('arrived the day before yesterday', lastMessageAt: 'now -40 hours');

        $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $badged = $client->getCrawler()->filter('[data-thread-new]')->count();

        self::assertSame(1, $badged, 'only the recent one is news');

        // Read BEFORE the render that retires it would be ideal, but the render
        // above has already marked what it showed — so this asks the endpoint
        // the same question the next page load would, and both must say zero.
        $counts = $this->countsFrom($client);

        self::assertSame(
            0,
            $counts['new:category:' . MessageCategory::Primary->value],
            'the shown thread is retired and the old one aged out, so nothing is left to dot',
        );
    }

    /**
     * The agreement that matters most, stated as an invariant: the number of
     * "New" badges the inbox renders is the number the endpoint would put on
     * the Primary tab, at the moment before the render retires them.
     */
    public function testTheCategoryDotCountMatchesTheBadgesTheListRenders(): void
    {
        $client = $this->fixtureClient();

        $this->thread('recent one');
        $this->thread('recent two');
        $this->thread('far too old', lastMessageAt: 'now -3 days');

        // The counts endpoint renders no list, so it retires nothing — this is
        // the state the inbox is about to draw from.
        $expected = $this->countsFrom($client)['new:category:' . MessageCategory::Primary->value];

        $client->request('GET', '/mail/inbox');

        $badged = $client->getCrawler()->filter('[data-thread-new]')->count();

        self::assertSame(2, $expected, 'two inside the window, one aged out');
        self::assertSame($expected, $badged, 'the dot must count exactly what the list badges');
    }

    /**
     * A client signed in as a user whose whole mailbox this test wrote, inside
     * a transaction that tearDown rolls back.
     */
    private function fixtureClient(): KernelBrowser
    {
        $client = static::createClient();

        // Without this the kernel is rebuilt between requests, taking the
        // connection holding the transaction with it, and the fixtures vanish
        // before the second request can see them.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $client->loginUser($this->user);

        return $client;
    }

    /** @return array<string,int> */
    private function countsFrom(KernelBrowser $client): array
    {
        $client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        /** @var array<string,int> */
        return json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        parent::tearDown();
    }
}
