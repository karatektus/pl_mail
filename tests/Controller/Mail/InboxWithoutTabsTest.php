<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Service\Mail\ListViewResolver;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An inbox with the category tabs switched off is one list (#29).
 *
 * Three things have to agree about that, and each is asserted here because
 * each could be right while the others are wrong: the list that is drawn, the
 * strip that is not, and — the one where disagreeing costs mail — what "select
 * all in this view" resolves to. A page that shows the whole inbox while a bulk
 * delete reaches only Primary, or the reverse, is the failure worth a test.
 *
 * Mail keeps its category either way. Nothing here changes what is stored.
 */
final class InboxWithoutTabsTest extends WebTestCase
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

        $this->thread(self::PRIMARY, MessageCategory::Primary);
        $this->thread(self::PROMOTION, MessageCategory::Promotions);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testWithTabsTheInboxShowsOneCategoryAndOffersTheOthers(): void
    {
        $html = $this->inboxHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringNotContainsString(self::PROMOTION, $html);
        self::assertStringContainsString('tab=promotions', $html, 'the strip offers the tab the other mail is in');
    }

    public function testWithoutTabsTheInboxIsOneListAndHasNoStrip(): void
    {
        $this->tabsOff();

        $html = $this->inboxHtml();

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html);
        self::assertStringNotContainsString('tab=promotions', $html);
        self::assertStringNotContainsString('data-dnd-category', $html, 'nothing to drop a conversation onto either');
    }

    /** A link from before the switch opens the inbox, all of it. */
    public function testAStaleTabLinkOpensTheWholeInbox(): void
    {
        $this->tabsOff();

        $html = $this->inboxHtml('?tab=promotions');

        self::assertStringContainsString(self::PRIMARY, $html);
        self::assertStringContainsString(self::PROMOTION, $html);
    }

    /**
     * "Select all in this view" means what the page shows.
     *
     * Decided from the person's setting and not from the value the page posts:
     * without tabs the page posts an empty one, and reading that as "Primary"
     * would archive half of what somebody had selected.
     */
    public function testSelectingTheWholeViewFollowsTheSameRule(): void
    {
        $resolver = static::getContainer()->get(ListViewResolver::class);

        $subjects = fn (string $value): array => array_map(
            static fn ($thread): string => (string) $thread->subject,
            $resolver->threadsIn($this->user, 'inbox', $value, false),
        );

        self::assertSame([self::PRIMARY], $subjects('primary'));
        self::assertSame([self::PROMOTION], $subjects('promotions'));

        $this->tabsOff();

        self::assertEqualsCanonicalizing([self::PRIMARY, self::PROMOTION], $subjects(''));
        self::assertEqualsCanonicalizing(
            [self::PRIMARY, self::PROMOTION],
            $subjects('promotions'),
            'a value left over from a tabbed page must not narrow an untabbed inbox',
        );
    }

    /** The counts endpoint stops answering for tabs nobody has. */
    public function testTheCountsCarryNoTabKeysWithoutTabs(): void
    {
        $keys = fn (): array => array_keys(json_decode(
            (string) $this->request('/mail/sidebar/counts'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));

        self::assertContains('category:promotions', $keys());

        $this->tabsOff();

        self::assertSame([], array_values(array_filter(
            $keys(),
            static fn (string $key): bool => str_contains($key, 'category:'),
        )));
        self::assertContains('role:inbox', $keys(), 'everything that is not about tabs is still there');
    }

    private function tabsOff(): void
    {
        $this->user->categorySorting->tabs = false;
        $this->em->flush();
    }

    private function inboxHtml(string $query = ''): string
    {
        return $this->request('/mail/inbox' . $query);
    }

    private function request(string $url): string
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
