<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\User\UserRepository;
use App\Tests\Service\Imap\FakeFolderServer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The folder actions in the folders modal (#221): the parts a browser sees
 * that need no mail server — the button, the form and its tree, the refusals
 * plMail makes on its own side, and the ownership checks. What goes to the
 * server is ImapFolderManagerTest's.
 */
final class AccountFolderActionsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var list<FakeFolderServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($user);

        $this->connection->beginTransaction();
        $this->account = $this->imapAccount($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheListOffersNewFolderAndTheFormOffersTheRealTree(): void
    {
        $this->mailbox('INBOX', 'INBOX', MailboxSpecialUse::INBOX);
        $clients = $this->mailbox('Clients', 'INBOX/Clients');
        $this->mailbox('Acme', 'INBOX/Clients/Acme');

        $this->client->request('GET', $this->url());
        self::assertSelectorExists('a[data-folder-new][href$="/folders/new"]');

        $crawler = $this->client->request('GET', $this->url('/new?parent=' . $clients->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name=name]');
        self::assertSelectorNotExists('form[data-ui--modal-keep-open] input[name=name]', 'the modal closes when the folder is made');
        self::assertSame('', $crawler->filter('select[name=parent] option')->first()->attr('value'), 'the top level comes first');
        self::assertSame((string) $clients->id, $crawler->filter('select[name=parent] option[selected]')->attr('value'));
        self::assertSame(['Top level', 'INBOX', 'Clients', 'Acme'], array_map(
            'trim',
            $crawler->filter('select[name=parent] option')->each(static fn ($o): string => str_replace("\u{a0}", '', $o->text())),
        ));
    }

    /**
     * The separator is refused before any server is asked — there is none in
     * this test, so reaching one would fail loudly — and the form says which
     * character, keeping what was typed.
     */
    public function testANameWithTheSeparatorIsRefusedWithTheReason(): void
    {
        $projects = $this->mailbox('Projects', 'Projects');

        $this->submit(['name' => 'v1/2', 'parent' => (string) $projects->id]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-folder-error]', '“/”');
        self::assertInputValueSame('name', 'v1/2');
    }

    public function testAParentOfAnotherAccountCannotBeChosen(): void
    {
        $other   = $this->imapAccount($this->account->usr);
        $foreign = new Mailbox();
        $foreign->account       = $other;
        $foreign->name          = 'Elsewhere';
        $foreign->fullPath      = 'Elsewhere';
        $foreign->delimiter     = '/';
        $foreign->isSyncEnabled = true;
        $this->em->persist($foreign);
        $this->em->flush();

        $this->submit(['name' => 'Kine', 'parent' => (string) $foreign->id]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPostWithoutAValidTokenIsRefused(): void
    {
        $this->client->request('POST', $this->url('/new'), ['name' => 'Kine', 'parent' => '', '_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Rename and delete are offered on the person's own folders and on no
     * special-use folder — and a delete posted for one anyway is refused with
     * the reason, the list left as it was.
     */
    public function testSpecialUseFoldersOfferNoRenameOrDeleteAndRefuseOne(): void
    {
        $sent    = $this->mailbox('Sent Items', 'Sent Items', MailboxSpecialUse::SENT);
        $clients = $this->mailbox('Clients', 'Clients');

        $crawler = $this->client->request('GET', $this->url());

        self::assertCount(0, $crawler->filter('turbo-frame#account-folder-sync-' . $sent->id . ' [data-folder-rename], turbo-frame#account-folder-sync-' . $sent->id . ' [data-folder-delete]'));
        self::assertCount(1, $crawler->filter('turbo-frame#account-folder-sync-' . $clients->id . ' [data-folder-rename]'));
        self::assertCount(1, $crawler->filter('turbo-frame#account-folder-sync-' . $clients->id . ' form[data-folder-delete][data-turbo-confirm]'));

        $token = (string) $crawler->filter('turbo-frame#account-folder-sync-' . $clients->id . ' form[data-folder-delete] input[name=_token]')->attr('value');
        self::assertNotSame('', $token);

        // The token is per folder, so Sent's is minted the same way the row
        // would have, had it offered the button.
        $this->client->request('POST', $this->url('/' . $sent->id . '/delete'), ['_token' => $this->tokenFor('account_folder_delete_' . $sent->id)]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-folder-error]', 'never renamed or deleted');
        self::assertNotNull($this->em->find(Mailbox::class, $sent->id));
    }

    public function testTheRenameFormAsksOnlyForTheName(): void
    {
        $clients = $this->mailbox('Clients', 'Clients');

        $this->client->request('GET', $this->url('/' . $clients->id . '/rename'));

        self::assertResponseIsSuccessful();
        self::assertInputValueSame('name', 'Clients');
        self::assertSelectorNotExists('select[name=parent]');
    }

    /**
     * A folder made from the form answers with a turbo-stream, not with the
     * list: the app's toast says it, both sidebars are drawn again with the
     * folder in them, and the modal closes on it like on any form. Against
     * the in-memory server of ImapFolderManagerTest.
     */
    public function testANewFolderAnswersWithTheToastAndTheRedrawnSidebar(): void
    {
        $server = $this->server('', '/');
        $this->serve($server);
        $this->mailbox('INBOX', 'INBOX', MailboxSpecialUse::INBOX);

        $this->submit(['name' => 'Kine', 'parent' => '']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('CREATE Kine', $server->commands[0] ?? null);

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('target="toast-region"', $body);
        self::assertStringContainsString('Folder “Kine” created', $body);
        self::assertStringContainsString('<turbo-stream action="replace" target="sidebar">', $body);
        self::assertStringContainsString('<turbo-stream action="replace" target="sidebar-drawer-inner">', $body);
        self::assertStringNotContainsString('target="modal"', $body, 'the modal closes rather than showing the list');
    }

    /** A delete, pressed inside the open list, gets the list back without the folder. */
    public function testADeleteKeepsTheListOpenWithoutTheFolder(): void
    {
        $server = $this->server('', '/');
        $this->serve($server);
        $empty = $this->mailbox('Empty', 'Empty');
        $this->mailbox('Other', 'Other');

        $crawler = $this->client->request('GET', $this->url());
        $token   = (string) $crawler->filter('turbo-frame#account-folder-sync-' . $empty->id . ' form[data-folder-delete] input[name=_token]')->attr('value');

        $crawler = $this->client->request('POST', $this->url('/' . $empty->id . '/delete'), ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertContains('DELETE Empty', $server->commands);

        $body = (string) $this->client->getResponse()->getContent();

        // The list again, in the modal's frame, without the folder…
        self::assertSelectorExists('turbo-frame#modal ul');
        self::assertCount(0, $crawler->filter('turbo-frame#account-folder-sync-' . $empty->id));
        self::assertStringContainsString('Other', $crawler->filter('turbo-frame#modal ul')->text());

        // …and the toast and the redrawn sidebars riding inside it.
        self::assertStringContainsString('Folder “Empty” deleted', $body);
        self::assertCount(1, $crawler->filter('turbo-frame#modal turbo-stream[target="toast-region"]'));
        self::assertCount(1, $crawler->filter('turbo-frame#modal turbo-stream[action="replace"][target="sidebar"]'));
    }

    /**
     * Labels as keywords: off by default, switched on from the list, and on
     * the way on every fetched folder is asked whether it keeps keywords, so
     * the list can say so at once (#221 step 2).
     */
    public function testLabelsAsKeywordsIsOffAndSwitchingItOnAsksTheFolders(): void
    {
        $server = $this->server('', '/');
        $server->keywordFolders['INBOX'] = true;
        $this->serve($server);
        $inbox = $this->mailbox('INBOX', 'INBOX', MailboxSpecialUse::INBOX);
        $this->mailbox('Clients', 'Clients');

        $crawler = $this->client->request('GET', $this->url());

        self::assertSame('false', $crawler->filter('form[data-folder-keywords] button[role=switch]')->attr('aria-checked'));
        self::assertFalse($this->account->labelsAsKeywords, 'off by default');

        $token = (string) $crawler->filter('form[data-folder-keywords] input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', $this->url('/keywords'), ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $account = $this->em->find(Account::class, $this->account->id);
        $inbox   = $this->em->find(Mailbox::class, $inbox->id);
        self::assertTrue($account?->labelsAsKeywords);
        self::assertEqualsCanonicalizing(['SELECT INBOX', 'SELECT Clients'], $server->commands);
        self::assertTrue($inbox?->keywordsAllowed);
        self::assertSame('true', $crawler->filter('form[data-folder-keywords] button[role=switch]')->attr('aria-checked'));
        self::assertStringContainsString('This server keeps keywords.', $crawler->filter('[data-folder-keywords-state]')->text());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** Every IMAP connection the actions open goes to this in-memory server. */
    private function serve(FakeFolderServer $server): void
    {
        $server->pointAt($this->account);
        $this->em->flush();
    }

    /** On the loop the connector runs on — one container for the test, see setUp(). */
    private function server(string $namespacePrefix, string $namespaceSeparator): FakeFolderServer
    {
        $server          = new FakeFolderServer(static::getContainer()->get(ImapLoop::class), $namespacePrefix, $namespaceSeparator);
        $this->servers[] = $server;

        return $server;
    }

    private function url(string $suffix = ''): string
    {
        return '/settings/accounts/' . $this->account->id . '/folders' . $suffix;
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields): void
    {
        $crawler = $this->client->request('GET', $this->url('/new'));
        $token   = (string) $crawler->filter('form input[name=_token]')->attr('value');

        $this->client->request('POST', $this->url('/new'), [...$fields, '_token' => $token]);
    }

    private function tokenFor(string $id): string
    {
        $this->client->request('GET', $this->url());

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }

    private function imapAccount(User $owner): Account
    {
        $account = new Account();
        $account->usr            = $owner;
        $account->email          = 'folder-actions-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function mailbox(string $name, string $fullPath, ?MailboxSpecialUse $specialUse = null): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $fullPath;
        $mailbox->delimiter     = '/';
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
