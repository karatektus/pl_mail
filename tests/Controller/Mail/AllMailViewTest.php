<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * All Mail (#57): every conversation outside Spam and the bin, across the
 * reader's accounts or within one, and opt-in.
 *
 * Which conversations each list holds is MessageThreadRepositoryTest's. Here
 * is what a page and a click do with it: the rows are hidden until the All
 * Mail label is switched on, and one switch shows both; the lists answer
 * whether or not the rows are shown; each list names itself to the frame;
 * and archiving or snoozing a row leaves it in place, because All Mail holds
 * archived and snoozed mail too, where the bin takes it out.
 */
final class AllMailViewTest extends WebTestCase
{
    use SeedsMarkerFixtures;

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

    /**
     * Off until switched on, and one switch for both rows: the one across
     * every account and the one under each account.
     */
    public function testTheRowsAreOptInAndOneSwitchShowsBoth(): void
    {
        $accountRow = sprintf('a[href="/mail/account/%d/all"]', $this->account->id);

        self::assertSame(0, $this->client->request('GET', '/mail/inbox')->filter('#sidebar a[href="/mail/all"]')->count());
        self::assertSame(0, $this->folderRows()->filter($accountRow)->count());

        // Opening label settings is what creates the label — hidden, bound to
        // no account — so it can be switched on there.
        $this->client->request('GET', '/settings?section=labels');

        $allMail = $this->allMailLabel();

        self::assertNotNull($allMail, 'label settings lists it, so it has to exist');
        self::assertFalse($allMail->isVisible);
        self::assertCount(0, $allMail->bindings, 'bound to no account, so no provider or JMAP client sees it');
        self::assertFalse($allMail->role?->acceptsMoves(), 'there is nothing to move into a list that holds everything');

        self::assertSame(0, $this->client->request('GET', '/mail/inbox')->filter('#sidebar a[href="/mail/all"]')->count());

        // Read again: the request cleared the entity manager on its way out.
        $allMail = $this->allMailLabel();
        self::assertNotNull($allMail);

        $allMail->isVisible = true;
        $this->em->flush();

        self::assertSame(1, $this->client->request('GET', '/mail/inbox')->filter('#sidebar a[href="/mail/all"]')->count());
        self::assertSame(1, $this->folderRows()->filter($accountRow)->count());
    }

    /**
     * Hidden is about the sidebar, not the lists: a bookmark keeps working, as
     * Unread's does.
     */
    public function testTheListsAnswerWhileTheRowsAreHidden(): void
    {
        $this->client->request('GET', '/mail/all');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/mail/account/%d/all', $this->account->id));
        self::assertResponseIsSuccessful();
    }

    /**
     * One account's All Mail names itself `all_mail` with the account's id,
     * lists archived mail its Inbox does not, and pages like any list.
     */
    public function testAnAccountsAllMailListsWhatItsInboxDoesNot(): void
    {
        $thread = $this->thread('Archived long ago');
        $thread->labels->clear();
        $this->em->flush();

        $url = sprintf('/mail/account/%d', $this->account->id);

        $this->client->request('GET', $url . '/all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('#inbox-list-frame[data-sync-scope="all_mail"][data-list-scope-value="%d"]', $this->account->id));
        self::assertSelectorExists('#thread_' . $thread->id);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#thread_' . $thread->id);

        $this->client->request('GET', $url . '/all?page=999');
        self::assertResponseRedirects();
    }

    /**
     * Archived or snoozed from an account's All Mail, a conversation is still
     * in it, so the row is redrawn — without the account dot, as every row
     * here is the same account — and not taken away. The bin takes it out.
     */
    public function testInAnAccountsListArchiveAndSnoozeStayWhileTrashLeaves(): void
    {
        $thread = $this->thread('Kept in view');

        // A second account, so that a row drawn with the account dot shows.
        $this->seedAccount();

        $url   = sprintf('/mail/account/%d/all', $this->account->id);
        $token = $this->token($url);
        $view  = ['scope' => 'all_mail', 'value' => (string) $this->account->id];

        foreach (['archive' => [], 'snooze' => ['until' => '2030-01-01T00:00:00Z']] as $action => $payload) {
            $this->post(sprintf('/status/thread/%d/%s', $thread->id, $action), $token, $view + $payload);

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, $this->body());
            self::assertStringNotContainsString('data-account-corner', $this->body());

            if ('archive' === $action) {
                preg_match('#/status/undo/[a-f0-9]{32}#', $this->body(), $undo);

                self::assertNotEmpty($undo, 'archiving still offers its Undo');

                $this->post($undo[0], $token, []);
                self::assertResponseIsSuccessful();
            }

            $this->client->request('GET', $url);
            self::assertSelectorExists('#thread_' . $thread->id);
        }

        $this->post('/status/bulk/archive', $token, $view + ['ids' => [$thread->id]]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, $this->body());
        self::assertStringNotContainsString('data-account-corner', $this->body());

        $this->post(sprintf('/status/thread/%d/trash', $thread->id), $token, $view);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $url);
        self::assertSelectorNotExists('#thread_' . $thread->id);
    }

    /**
     * The list across every account is the same scope with no value, and
     * keeps an archived or snoozed row the same way.
     */
    public function testTheUnifiedListKeepsArchivedAndSnoozedRows(): void
    {
        $thread = $this->thread('Across accounts');
        $this->seedAccount();

        $this->client->request('GET', '/mail/all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#inbox-list-frame[data-sync-scope="all_mail"][data-list-scope-value=""]');
        self::assertSelectorExists('#thread_' . $thread->id);

        $token = $this->token('/mail/all');
        $view  = ['scope' => 'all_mail', 'value' => ''];

        foreach (['archive', 'snooze'] as $action) {
            $this->post(sprintf('/status/thread/%d/%s', $thread->id, $action), $token, $view + ['until' => '2030-01-01T00:00:00Z']);

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, $this->body());
        }

        $this->post('/status/bulk/archive', $token, $view + ['ids' => [$thread->id]]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, $this->body());

        $this->client->request('GET', '/mail/all');
        self::assertSelectorExists('#thread_' . $thread->id);

        $this->client->request('GET', '/mail/all?page=999');
        self::assertResponseRedirects();
    }

    /**
     * The list names itself from the browser, so a value naming an account
     * that is not the reader's is a list that holds nothing: the row goes, as
     * it would from any list it does not belong to.
     */
    public function testAValueNamingSomebodyElsesAccountKeepsNoRow(): void
    {
        $thread = $this->thread('Mine');
        $mine   = $this->user;

        $this->user = $this->seedUser();
        $foreign    = $this->seedAccount();
        $this->user = $mine;

        $token = $this->token('/mail/all');

        $this->post(sprintf('/status/thread/%d/archive', $thread->id), $token, ['scope' => 'all_mail', 'value' => (string) $foreign->id]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="remove" target="thread_' . $thread->id, $this->body());
    }

    public function testForeignAndMalformedAccountsAreRefused(): void
    {
        $mine = $this->user;

        $this->user = $this->seedUser();
        $foreign    = $this->seedAccount();

        $this->client->loginUser($mine);

        $this->client->request('GET', sprintf('/mail/account/%d/all', $foreign->id));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/mail/account/invalid/all');
        self::assertResponseStatusCodeSame(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function allMailLabel(): ?Label
    {
        return $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => LabelRole::AllMail]);
    }

    /** An account's folder rows, as the sidebar fetches them on expanding it. */
    private function folderRows(): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('/mail/account/%d/folders', $this->account->id));

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function token(string $url): string
    {
        return (string) $this->client->request('GET', $url)->filter('meta[name="csrf-token"]')->attr('content');
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, string $token, array $body): void
    {
        $this->client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
