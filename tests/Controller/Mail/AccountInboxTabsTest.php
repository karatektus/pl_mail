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

    private function accountHtml(string $query = ''): string
    {
        $this->client->request('GET', '/mail/account/' . $this->account->id . $query);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
