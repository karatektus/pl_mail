<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An account's own inbox has the category tabs the unified inbox has.
 *
 * It did not, for any provider: clicking an account in the sidebar opened a
 * plain list, which on an Outlook-only installation meant the tabs were nowhere
 * a person would look for them. The categories were being worked out all along
 * — the unified inbox shows them — the account page simply never drew them.
 *
 * Four things are pinned because each can be right while the others are wrong:
 * the strip exists exactly when more than one category holds mail, a tab opens
 * that category of THIS account, the links stay on this page rather than
 * jumping to the unified inbox, and the numbers that are about every account
 * together are not drawn on a page about one.
 *
 * All of it only for somebody who switched the account tabs on (Settings →
 * Mail → Categories, User::$accountInboxTabs), which setUp() does. Off — the
 * default — the account page is the account's whole inbox, as it always was;
 * the two tests at the end pin that.
 */
final class AccountInboxTabsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private const string PRIMARY   = 'Brief von einer Person';
    private const string PROMOTION = 'Nur heute zwanzig Prozent';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->user->accountInboxTabs = true;
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheAccountInboxOffersTheCategoriesItHoldsAndOpensOneAtATime(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $html = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringNotContainsString(self::PROMOTION, $html, 'the page opens on Primary, as the unified inbox does');
        self::assertStringContainsString('tab=promotions', $html);

        $promotions = $this->accountHtml('?tab=promotions');

        self::assertStringContainsString(self::PROMOTION, $promotions);
        self::assertStringNotContainsString(self::PRIMARY, $promotions);
    }

    /** The links stay here. A tab that jumped to the unified inbox would lose the account. */
    public function testTheTabLinksStayOnThisAccountsPage(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $html = $this->accountHtml();

        self::assertStringContainsString(
            sprintf('/mail/account/%d?tab=promotions', $this->account->id),
            $html,
        );
        self::assertStringNotContainsString('/mail/inbox?tab=', $html);
    }

    /** Mail of one category only: nothing to choose between, so no strip. */
    public function testWithOneCategoryThereIsNoStrip(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);

        $html = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $html);

        // Narrowed to this page's own links: the sidebar and the settings
        // pages carry `tab=` links of their own.
        self::assertStringNotContainsString(sprintf('/mail/account/%d?tab=', $this->account->id), $html);
    }

    /**
     * The tab being looked at stays even when it is empty, so the ground does
     * not vanish underfoot — the unified inbox's rule, kept.
     */
    public function testTheTabBeingLookedAtStaysEvenWhenEmpty(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);

        $html = $this->accountHtml('?tab=forums');

        self::assertStringNotContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(sprintf('/mail/account/%d?tab=primary', $this->account->id), $html);
    }

    public function testWithoutTabsTheAccountInboxIsOneListAndHasNoStrip(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $this->user->categorySorting->tabs = false;
        $this->em->flush();

        $html = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html);
        self::assertStringNotContainsString('tab=promotions', $html);

        // A link from before the switch opens the whole inbox.
        $stale = $this->accountHtml('?tab=promotions');

        self::assertStringContainsString(self::PRIMARY, $stale);
        self::assertStringContainsString(self::PROMOTION, $stale);
    }

    /**
     * Off by default: clicking an account opens its whole inbox, every
     * category in one list, with no strip — what the page was before it had
     * tabs, for everybody who has not asked for them there.
     */
    public function testByDefaultTheAccountInboxIsItsWholeInboxWithNoStrip(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $this->user->accountInboxTabs = false;
        $this->em->flush();

        self::assertNull($this->user->getSetting(User::SETTING_ACCOUNT_INBOX_TABS), 'off is the absent key');

        $html = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html);
        self::assertStringNotContainsString(sprintf('/mail/account/%d?tab=', $this->account->id), $html);

        // The unified inbox keeps its tabs: the switch is about this page only.
        self::assertStringContainsString('/mail/inbox?tab=promotions', (string) $this->client->request('GET', '/mail/inbox')->html());
    }

    /**
     * A `?tab=` link saved while the switch was on opens the whole inbox once
     * it is off, rather than a narrowed list with no strip to leave it by.
     */
    public function testWithTheSwitchOffAStaleTabLinkOpensTheWholeInbox(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $this->user->accountInboxTabs = false;
        $this->em->flush();

        $html = $this->accountHtml('?tab=promotions');

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html);
    }

    /**
     * Those numbers describe the unified inbox: "new" pills, sender hints and
     * unread tints are summed across every account, and the sidebar controller
     * rewrites any element carrying their keys from that same global payload.
     * Drawn on a page about one account they would say the wrong thing, and be
     * quietly overwritten with it after the next sync.
     */
    public function testTheStripCarriesNoCountsThatAreAboutEveryAccount(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary, unread: 1);
        $this->thread(self::PROMOTION, MessageCategory::Promotions, unread: 1);

        $html = $this->accountHtml();

        // The presence companion: the strip is there, so its absence of
        // counters is a statement about the strip and not about an empty page.
        self::assertStringContainsString('tab=promotions', $html);

        self::assertStringNotContainsString('data-count-key="category:', $html);
        self::assertStringNotContainsString('data-count-key="new:category:', $html);
        self::assertStringNotContainsString('data-count-key="senders:category:', $html);
    }

    // ── the reader's own categories ──────────────────────────────────────────
    //
    // The account page was taught the five built-in tabs and never the ones a
    // reader makes, so mail filed in one was in no tab at all: kept out of
    // Primary by the one-tab rule, and with no tab of its own to be found in.

    /**
     * The reported case, in miniature. A thread filed under "Payments" is not in
     * Primary (it is in exactly one tab) and IS under Payments, which is offered
     * as a tab of this page.
     */
    public function testAThreadFiledInTheReadersOwnCategoryIsUnderItsTabAndNotUnderPrimary(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);
        $this->thread(self::PRIMARY, MessageCategory::Primary);

        $primary = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $primary, 'presence companion: Primary lists its own mail');
        self::assertStringNotContainsString('Zahlung geaendert', $primary, 'a filed thread is listed under Primary too');
        self::assertContains('tab=c' . $payments->id, $this->tabQueries($this->crawl()), 'no tab for the category');
        self::assertStringContainsString('Payments', $this->crawl()->filter('nav a[href*="tab=c' . $payments->id . '"]')->text());

        $own = $this->accountHtml('?tab=c' . $payments->id);

        self::assertStringContainsString('Zahlung geaendert', $own);
        self::assertStringNotContainsString(self::PRIMARY, $own);

        // And the strip says where you are: the open tab, and only it, is the
        // current page. Compared by key — a tab is a fresh object every time.
        $current = $this->crawl('?tab=c' . $payments->id)->filter('nav a[aria-current="page"]');

        self::assertCount(1, $current);
        self::assertStringContainsString('tab=c' . $payments->id, (string) $current->attr('href'));
    }

    /**
     * The built-in category a filed thread was assigned stays a plain fact about
     * it, but it is not a second place to find it: Updates must not list it.
     */
    public function testTheBuiltInTabDoesNotListWhatWasFiledAwayFromIt(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);
        $this->thread('Ein Update', MessageCategory::Updates);

        $updates = $this->accountHtml('?tab=updates');

        self::assertStringContainsString('Ein Update', $updates);
        self::assertStringNotContainsString('Zahlung geaendert', $updates, 'the thread is in two tabs');
    }

    /**
     * Categories belong to the reader, but a tab on THIS page is about THIS
     * account: a category that holds mail only in another of their accounts is
     * a door to an empty room here.
     */
    public function testACategoryHoldingMailOnlyInAnotherAccountIsNotATabHere(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $here = $this->account;
        $this->account = $this->seedAccount();
        $this->filed('Nur im anderen Konto', $payments, MessageCategory::Updates);
        $other = $this->account;
        $this->account = $here;

        self::assertNotContains('tab=c' . $payments->id, $this->tabQueries($this->crawl()), 'a category empty here was drawn as a tab');
        // The strip is there (Primary and Promotions), so the absence is about
        // the category and not about a page with no strip.
        self::assertContains('tab=promotions', $this->tabQueries($this->crawl()));

        $this->account = $other;
        self::assertContains('tab=c' . $payments->id, $this->tabQueries($this->crawl($other)), 'presence companion: the account that holds it offers it');
    }

    /** The tab being looked at stays, as a built-in does, even with nothing in it here. */
    public function testTheOwnTabBeingLookedAtStaysEvenWhenItHoldsNothingHere(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $html = $this->accountHtml('?tab=c' . $payments->id);

        self::assertStringNotContainsString(self::PRIMARY, $html);
        self::assertStringNotContainsString(self::PROMOTION, $html);
        self::assertContains('tab=c' . $payments->id, $this->tabQueries($this->crawl('?tab=c' . $payments->id)));
    }

    /**
     * A key that is not a tab of THIS reader opens the inbox, exactly as it does
     * in the unified one (InboxCustomTabTest): somebody else's category, a
     * deleted one and a malformed one all take the Primary fall-back, and none
     * of them is a 500 or another person's tab.
     */
    #[DataProvider('notTheirTabs')]
    public function testAKeyThatIsNotTheirTabOpensPrimary(string $kind): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);
        $this->thread(self::PRIMARY, MessageCategory::Primary);

        $key = match ($kind) {
            'foreign'   => 'c' . $this->category($this->seedUser(), 'Fremd')->id,
            'deleted'   => $this->deleted($payments),
            default     => 'c1x', // 'malformed': not a key InboxTab ever produced
        };

        $html = $this->accountHtml('?tab=' . $key);

        self::assertStringContainsString(self::PRIMARY, $html, 'did not fall back to Primary');
        self::assertStringNotContainsString('Zahlung geaendert', $html);
        self::assertStringNotContainsString('Fremd', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function notTheirTabs(): iterable
    {
        yield 'another reader\'s category' => ['foreign'];
        yield 'a category deleted since the link was made' => ['deleted'];
        yield 'a key that is not one' => ['malformed'];
    }

    /**
     * The pager counts the tab that is open, as the list does. Hand-derived: one
     * thread in Payments and 51 in Primary make 52 in the inbox; at 50 a page
     * that is two pages of inbox, two of Primary and ONE of Payments. A page
     * count taken from the whole inbox would offer page 2 of Payments, which
     * the list cannot fill.
     */
    public function testThePagerOffersNoPageTheOpenTabDoesNotHave(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);

        for ($i = 0; $i < 51; ++$i) {
            $this->thread(self::PRIMARY . ' ' . $i, MessageCategory::Primary, flush: false);
        }

        $this->em->flush();

        $base = '/mail/account/' . $this->account->id;

        // Presence companion: Primary really has a second page, so the redirect
        // below is the tab's own count at work and not a page limit of two.
        $this->client->request('GET', $base . '?tab=primary&page=2');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $base . '?tab=c' . $payments->id . '&page=2');
        self::assertResponseRedirects($base . '?tab=c' . $payments->id);
    }

    /**
     * A built-in the reader switched off is no tab, and its mail is listed under
     * Primary — the unified inbox's rule, on this page too. Otherwise a
     * conversation in it is in no tab of this page at all.
     */
    public function testASwitchedOffCategoryIsListedUnderPrimaryAndHasNoTab(): void
    {
        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);
        $this->thread('Ein Update', MessageCategory::Updates);

        // Presence companion: with Promotions still on, it is a tab of its own.
        self::assertContains('tab=promotions', $this->tabQueries($this->crawl()));

        $this->user->categorySorting->switchOff([MessageCategory::Promotions]);
        $this->em->flush();

        $html = $this->accountHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html, 'the switched-off category is in no tab');
        self::assertStringNotContainsString('Ein Update', $html);
        self::assertNotContains('tab=promotions', $this->tabQueries($this->crawl()));
    }

    /** With the tabs off the account page is the whole inbox, filed mail included. */
    public function testWithoutTabsTheWholeInboxListsFiledMailToo(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);
        $this->thread(self::PRIMARY, MessageCategory::Primary);

        $this->user->categorySorting->tabs = false;
        $this->em->flush();

        foreach (['', '?tab=c' . $payments->id] as $query) {
            $html = $this->accountHtml($query);

            self::assertStringContainsString('Zahlung geaendert', $html, $query);
            self::assertStringContainsString(self::PRIMARY, $html, $query);
            self::assertNotContains('tab=c' . $payments->id, $this->tabQueries($this->crawl($query)), $query);
        }
    }

    /** The own tab's strip entry carries no cross-account counter either. */
    public function testAnOwnTabCarriesNoCountsThatAreAboutEveryAccount(): void
    {
        $payments = $this->category($this->user, 'Payments');
        $this->filed('Zahlung geaendert', $payments, MessageCategory::Updates);
        $this->thread(self::PRIMARY, MessageCategory::Primary, unread: 1);

        $html = $this->accountHtml();

        self::assertContains('tab=c' . $payments->id, $this->tabQueries($this->crawl()), 'presence companion: the own tab is drawn');
        self::assertStringNotContainsString('data-count-key="category:', $html);
        self::assertStringNotContainsString('data-count-key="new:category:', $html);
        self::assertStringNotContainsString('data-count-key="senders:category:', $html);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @return list<string> the query string of every tab link on this account's strip */
    private function tabQueries(Crawler $crawler): array
    {
        return $crawler
            ->filter('nav a[href*="/mail/account/"]')
            ->each(static fn (Crawler $node): string => (string) parse_url((string) $node->attr('href'), PHP_URL_QUERY));
    }

    /**
     * The account page as a crawler. A string is a query; an Account is another
     * account's page.
     */
    private function crawl(string|Account $target = ''): Crawler
    {
        $account = $target instanceof Account ? $target : $this->account;
        $query   = is_string($target) ? $target : '';

        $crawler = $this->client->request('GET', '/mail/account/' . $account->id . $query);

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function filed(string $subject, InboxCategory $category, MessageCategory $builtIn): MessageThread
    {
        $thread               = $this->thread($subject, $builtIn);
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

    /**
     * Really removed, in the database: its threads are un-filed by the foreign
     * key (ON DELETE SET NULL), as they are in production.
     */
    private function deleted(InboxCategory $category): string
    {
        $id = (int) $category->id;

        $this->connection->executeStatement('DELETE FROM inbox_category WHERE id = ?', [$id]);
        $this->em->clear();

        return 'c' . $id;
    }

    private function accountHtml(string $query = ''): string
    {
        $this->client->request('GET', '/mail/account/' . $this->account->id . $query);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
