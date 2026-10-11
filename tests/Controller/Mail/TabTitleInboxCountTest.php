<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The tab's "(n)" is the Inbox's unread count on every page that is not a
 * mail folder, not only on the Inbox itself.
 *
 * The report: with mail waiting, the tab read "(3) Inbox — Mail" on the Inbox
 * and plain "Settings — Mail" one click away, so the count that says "go and
 * read something" vanished whenever the person was configuring or looking at
 * the calendar. Those pages render the sidebar, so the number was on screen
 * in the sidebar and missing from the tab.
 *
 * Mail folders are the exception and keep what they had: each says its own
 * number or none (the bin counts a total, Sent counts nothing, Archive has no
 * badge). Putting the Inbox's count on them would label a folder with a figure
 * that is not about it.
 *
 * Fixture, worked out by hand: two conversations hold unread mail, with 1 and
 * 3 unread messages. The count is of conversations — 2, NOT the 4 messages.
 */
final class TabTitleInboxCountTest extends WebTestCase
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

        $this->seedLabel('Trash', LabelRole::Trash);

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
     * @return iterable<string, array{string}>
     */
    public static function nonMailPages(): iterable
    {
        yield 'settings' => ['/settings'];
        yield 'calendar' => ['/calendar'];
    }

    #[DataProvider('nonMailPages')]
    public function testAPageThatIsNotAFolderCarriesTheInboxCountInItsTitle(string $url): void
    {
        $this->thread('unread one', unread: 1);
        $this->thread('unread three', unread: 3);
        $this->thread('read', unread: 0);

        $html = $this->page($url);

        self::assertStringStartsWith('(2) ', $this->title($html), 'two conversations, not the four messages in them');
    }

    /**
     * The sidebar sync rewrites the tab from the key the page names. A page
     * that shows the count on load and names no key would show it stale after
     * the first thing is read.
     */
    #[DataProvider('nonMailPages')]
    public function testAPageThatIsNotAFolderTellsTheSidebarToKeepTheTitleInStep(string $url): void
    {
        $this->thread('unread one', unread: 1);

        self::assertStringContainsString(
            'name="title-count-key" content="role:inbox"',
            $this->page($url),
        );
    }

    /**
     * The presence companion above would pass for a page that always printed
     * a number. With nothing unread there must be nothing in front of the name.
     */
    #[DataProvider('nonMailPages')]
    public function testNothingUnreadLeavesTheTitleBare(string $url): void
    {
        $this->thread('read', unread: 0);

        $title = $this->title($this->page($url));

        self::assertStringNotContainsString('(', $title);
        self::assertNotSame('', $title);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherFolders(): iterable
    {
        yield 'archive, which has no count of its own' => ['/mail/archive'];
        yield 'the bin, which counts a total and holds none' => ['/mail/trash'];
    }

    #[DataProvider('otherFolders')]
    public function testAnotherFolderDoesNotBorrowTheInboxCount(string $url): void
    {
        $this->thread('unread one', unread: 1);
        $this->thread('unread three', unread: 3);

        $title = $this->title($this->page($url));

        self::assertStringNotContainsString('(', $title, 'a folder is not labelled with the inbox\'s figure');
        self::assertNotSame('', $title);
    }

    /**
     * Presence companion to the folder test: the Inbox itself still says it,
     * so "no prefix on folders" is not an always-empty title.
     */
    public function testTheInboxStillCarriesItsOwnCount(): void
    {
        $this->thread('unread one', unread: 1);
        $this->thread('unread three', unread: 3);

        self::assertStringStartsWith('(2) ', $this->title($this->page('/mail/inbox')));
    }

    private function page(string $url): string
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function title(string $html): string
    {
        preg_match('#<title>(.*?)</title>#s', $html, $matches);

        return trim(preg_replace('/\s+/', ' ', $matches[1] ?? ''));
    }
}
