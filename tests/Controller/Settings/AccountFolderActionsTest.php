<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        self::assertSelectorExists('form[data-ui--modal-keep-open] input[name=name]');
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

    // ── Fixtures ──────────────────────────────────────────────────────────

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
