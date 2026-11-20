<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Folders under their account, tags under LABELS (#221 step 3).
 *
 * On a plain IMAP account a label that is a folder there is listed under the
 * account and not under LABELS, which holds the labels put on mail; a tag is
 * under LABELS and not under the account. "Folders in Labels" lists folders
 * under LABELS again.
 */
final class SidebarFoldersUnderAccountsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->user->setAccountExpanded((int) $this->account->id, true);
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

    public function testAFolderIsUnderItsAccountAndATagUnderLabels(): void
    {
        $this->folder('Projects');
        $this->tag('Later');

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame(['Later'], $this->names($crawler, '#label-list'));
        self::assertContains('Projects', $this->names($crawler, '#account-folders-' . $this->account->id));
        self::assertNotContains('Later', $this->names($crawler, '#account-folders-' . $this->account->id));
    }

    public function testFoldersInLabelsListsThemThereAgain(): void
    {
        $this->folder('Projects');
        $this->tag('Later');

        self::assertSame('0', $this->checkedIn('folders_in_labels'), 'off by default');

        $this->switchView('folders_in_labels', true);

        self::assertTrue($this->em->find(User::class, $this->user->id)?->foldersInLabels);
        self::assertSame('1', $this->checkedIn('folders_in_labels'));

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame(['Later', 'Projects'], $this->names($crawler, '#label-list'));
    }

    /**
     * By account (#690): every label on the account is under it, tags with
     * folders, and LABELS keeps only the label on no account. Switched in its
     * own card in Settings → Labels, like the other On/Off switches there.
     */
    public function testByAccountListsEveryLabelUnderItsAccount(): void
    {
        $this->folder('Projects');
        $this->tag('Later');
        $this->seedLabel('Someday');

        self::assertSame('0', $this->checkedIn('by_account'), 'off by default');

        $this->switchView('by_account', true);

        self::assertTrue($this->em->find(User::class, $this->user->id)?->labelsByAccount);

        $crawler  = $this->client->request('GET', '/mail/inbox');
        $folders  = '#account-folders-' . $this->account->id;

        self::assertSame(['Someday'], $this->names($crawler, '#label-list', ['Projects', 'Later', 'Someday']));
        self::assertSame(['Later', 'Projects'], $this->names($crawler, $folders, ['Projects', 'Later', 'Someday']));
        self::assertCount(1, $crawler->filter($folders . ' [data-label-tag-dot]'), 'the tag wears its colour dot, the folder none');
        self::assertCount(1, $crawler->filter($folders . ' button[data-account-label-new]'));
    }

    /**
     * A label on another account too is marked under each, and the mark names
     * the others — renaming or deleting it from the merged list reaches them.
     * Dropping on it there labels this account's mail rather than moving it.
     */
    public function testByAccountMarksALabelThatIsOnAnotherAccountToo(): void
    {
        $this->user->labelsByAccount = true;
        $shared                      = $this->tag('Later');
        $this->tag('Projects');

        $other   = $this->seedAccount();
        $binding = new LabelBinding();
        $binding->label   = $shared;
        $binding->account = $other;
        $this->em->persist($binding);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mail/inbox');
        $marks   = $crawler->filter('#account-folders-' . $this->account->id . ' [data-label-shared]');

        self::assertCount(1, $marks, 'Later only: Projects is on this account alone');
        self::assertStringContainsString($other->displayAddress, (string) $marks->attr('title'));
        self::assertCount(1, $crawler->filter('#account-folders-' . $this->account->id . ' [data-dnd-label="' . $shared->id . '"][data-dnd-account="' . $this->account->id . '"]'));
    }

    /**
     * The collapsed LABELS heading counts the mail of the labels it holds.
     * By account those are only labels on no account, which no mail carries,
     * so it shows nothing — it used to show every labelled unread
     * conversation of every account over a section with none of them in it.
     */
    public function testByAccountTheLabelsHeadingCountsNothing(): void
    {
        $later  = $this->tag('Later');
        $thread = $this->thread('Unread and labelled', unread: 1);
        $thread->addLabel($later);
        $this->em->flush();

        self::assertGreaterThan(0, $this->labelsUnread(), 'merged, the heading counts it');

        $this->user->labelsByAccount = true;
        $this->em->flush();

        self::assertSame(0, $this->labelsUnread());
    }

    /**
     * Several accounts open at once (#690): opening one leaves the others
     * open, each is rendered into the first paint, and closing one closes it
     * alone. `account: null` still closes them all, which the browser suite
     * resets with, `all` opens or closes every one, and somebody else's
     * account is not remembered.
     */
    public function testSeveralAccountsStayOpenAtOnce(): void
    {
        $this->tag('Later');
        $club     = $this->seedAccount();
        $stranger = $this->seedAccount();
        $stranger->usr = $this->seedUser();
        $this->em->flush();

        $this->expand(['account' => $club->id, 'expanded' => true]);
        $this->expand(['account' => $stranger->id, 'expanded' => true]);

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame([(int) $this->account->id, (int) $club->id], $this->reloadedUser()->expandedAccountIds);
        self::assertCount(1, $crawler->filter('#account-folders-' . $this->account->id . ':not(.hidden)'));
        self::assertCount(1, $crawler->filter('#account-folders-' . $club->id . ':not(.hidden)'));

        $this->expand(['account' => $this->account->id, 'expanded' => false]);

        self::assertSame([(int) $club->id], $this->reloadedUser()->expandedAccountIds);

        $this->expand(['account' => null]);

        self::assertSame([], $this->reloadedUser()->expandedAccountIds);

        // Open all, from the ACCOUNTS heading: every one of the user's
        // accounts, nobody else's. Close all empties it again.
        $this->expand(['all' => true]);

        self::assertEqualsCanonicalizing([(int) $this->account->id, (int) $club->id], $this->reloadedUser()->expandedAccountIds);

        $this->expand(['all' => false]);

        self::assertSame([], $this->reloadedUser()->expandedAccountIds);
    }

    /** A bag written before #690 holds one id, and that account stays open. */
    public function testASingleStoredAccountReadsAsThatOneOpen(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SIDEBAR_ACCOUNT, 42);

        self::assertSame([42], $user->expandedAccountIds);
        self::assertTrue($user->isAccountExpanded(42));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** Which segment of a switch in the "Labels in the sidebar" card is checked. */
    private function checkedIn(string $key): string
    {
        $crawler = $this->client->request('GET', '/settings/labels');

        return (string) $crawler->filter('#card-label-view input[name="labels-' . $key . '"][checked]')->attr('value');
    }

    /** What settings--toggle posts when a segment is picked, and what it gets back. */
    private function switchView(string $key, bool $on): void
    {
        $crawler = $this->client->request('GET', '/settings/labels');
        $token   = (string) $crawler->filter('#card-label-view [data-settings--toggle-token-value]')->attr('data-settings--toggle-token-value');

        $this->client->request('POST', '/settings/labels/view', ['key' => $key, 'enabled' => $on ? '1' : '0', '_token' => $token]);

        self::assertResponseIsSuccessful();

        // Answered with the sidebar, both copies, so the switch shows at once.
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('target="sidebar"', $body);
        self::assertStringContainsString('target="sidebar-drawer-inner"', $body);
    }

    /** @param array<string, mixed> $body */
    private function expand(array $body): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');
        $token   = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request(
            'POST',
            '/mail/sidebar/account-expanded',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: (string) json_encode($body),
        );

        self::assertResponseIsSuccessful();
    }

    private function reloadedUser(): User
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);
        $this->user = $user;

        return $user;
    }

    /** The LABELS heading's number, as the counts endpoint patches it. */
    private function labelsUnread(): int
    {
        $this->client->request('GET', '/mail/sidebar/counts');

        $counts = json_decode((string) $this->client->getResponse()->getContent(), true);

        return (int) $counts['labels:unread'];
    }

    private function tag(string $name): Label
    {
        $label = $this->seedLabel($name);

        $binding          = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $this->account;
        $this->em->persist($binding);
        $this->em->flush();

        return $label;
    }

    private function folder(string $name): Label
    {
        $label = $this->tag($name);

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);

        $label->bindingFor($this->account)->mailbox = $mailbox;
        $this->em->flush();

        return $label;
    }

    /**
     * @param list<string> $known the names to look for
     *
     * @return list<string> the custom labels named in that part of the sidebar
     */
    private function names(Crawler $crawler, string $where, array $known = ['Projects', 'Later']): array
    {
        $names = $crawler->filter($where . ' .nav-item')->each(static fn (Crawler $row): string => trim($row->text()));

        return array_values(array_filter(
            array_map(static fn (string $text): string => trim((string) preg_replace('/\s+\d+$/', '', $text)), $names),
            static fn (string $name): bool => in_array($name, $known, true),
        ));
    }
}
