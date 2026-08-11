<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Repository\User\UserRepository;
use App\Twig\SidebarCounts;
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
        self::assertStringContainsString('text-ink-muted', $classes, 'it should read as neutral');
    }

    public function testTheInboxBadgeStillWearsTheAccent(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $badge = $client->getCrawler()->filter('[data-count-key="role:inbox"]');

        self::assertGreaterThan(0, $badge->count());
        self::assertStringContainsString('bg-accent', (string) $badge->first()->attr('class'));
    }
}
