<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\Account\AccountRepository;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An account that only sends, or only receives, can be added and kept that way
 * (#628).
 *
 * The request came from somebody sending through SMTP2go, which has no mailbox:
 * the form refused an account without an incoming server, so they gave it a
 * made-up one and got a sync error for it every few minutes. What is pinned
 * here is the form and the switches in front of the model — that a blank
 * server switches its half off and one of the two has to be given, that the
 * edit form switches a half on when it gains a server and off when it loses
 * one and otherwise leaves the switch alone, and that a half the account has
 * no server for cannot be switched on from Settings, where it would only fail.
 *
 * Through the real controllers, in a transaction that is never committed: the
 * client keeps its kernel, so the requests write through the connection the
 * test rolls back.
 */
final class AccountSendOrReceiveOnlyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = MailFixtures::user($this->em, 'sendonly');
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARelayIsAddedWithNoIncomingServerAndReceivingOff(): void
    {
        $this->submitNew([
            'account[imapHost]' => '',
            'account[imapPort]' => '',
        ]);

        $account = $this->added();

        self::assertNotNull($account, (string) $this->client->getResponse()->getContent());
        self::assertFalse($account->mailEnabled, 'nothing to sync, so nothing to fail');
        self::assertTrue($account->sendEnabled);
        self::assertContains($account->imapHost, [null, ''], 'no server was made up for it');
        self::assertTrue($account->isPrimary, 'the only account that sends is the one new mail goes from');
    }

    public function testAMailboxIsAddedWithNoSmtpServerAndSendingOff(): void
    {
        $this->submitNew(['account[smtpHost]' => '']);

        $account = $this->added();

        self::assertNotNull($account, (string) $this->client->getResponse()->getContent());
        self::assertTrue($account->mailEnabled);
        self::assertFalse($account->sendEnabled, 'not offered in From, where every send would have failed');
    }

    public function testAnAccountWithNeitherServerIsRefused(): void
    {
        $this->submitNew([
            'account[imapHost]' => '',
            'account[smtpHost]' => '',
        ]);

        self::assertNull($this->added());
        self::assertStringContainsString('Enter an incoming server, an SMTP server, or both', (string) $this->client->getResponse()->getContent());
    }

    public function testAnIncomingServerWithoutAPortIsRefused(): void
    {
        $this->submitNew(['account[imapPort]' => '']);

        self::assertNull($this->added());
        self::assertStringContainsString('Enter the port', (string) $this->client->getResponse()->getContent());
    }

    public function testGivingARelayAnIncomingServerSwitchesReceivingOn(): void
    {
        $relay           = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);
        $relay->imapHost = null;
        $this->em->flush();

        $this->submitEdit($relay, [
            'account[imapHost]' => '127.0.0.1',
            'account[imapPort]' => '1',
        ]);

        $relay = $this->reload($relay);

        self::assertTrue($relay->mailEnabled, 'what "Set up…" sends somebody to the form to do');
        self::assertTrue($relay->sendEnabled);
    }

    public function testClearingTheSmtpServerSwitchesSendingOff(): void
    {
        $account = MailFixtures::account($this->em, $this->user);

        $this->submitEdit($account, ['account[smtpHost]' => '']);

        $account = $this->reload($account);

        self::assertFalse($account->sendEnabled, 'an account with nothing to send through is not a sender');
        self::assertTrue($account->mailEnabled);
    }

    public function testSavingAnUnchangedServerKeepsTheSwitchWhereThePersonPutIt(): void
    {
        $account = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);

        $this->submitEdit($account, []);

        $account = $this->reload($account);

        self::assertFalse($account->mailEnabled, 'switched off in Settings, and a save of the form is not switching it on');
    }

    public function testReceivingCannotBeSwitchedOnForARelayWithNoIncomingServer(): void
    {
        $relay           = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);
        $relay->imapHost = null;
        $this->em->flush();

        $modal = $this->client->request('GET', sprintf('/account/%d/services', $relay->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $modal->filter(sprintf('a[href="/account/%d/edit"]', $relay->id)), 'the switch is a way to the form instead');

        $token = $modal->filter(sprintf('form[action="/account/%d/service/send"] input[name="_token"]', $relay->id))->attr('value');

        $this->client->request('POST', sprintf('/account/%d/service/mail', $relay->id), [
            '_token'  => $token,
            'enabled' => '1',
        ]);

        self::assertResponseStatusCodeSame(400);

        $relay = $this->reload($relay);

        self::assertFalse($relay->mailEnabled);
    }

    public function testTheConnectionTestOfARelayProbesSendingAlone(): void
    {
        $form = $this->client->request('GET', '/account/new');
        $csrf = $form->filter('[data-settings--connection-test-csrf-value]')->attr('data-settings--connection-test-csrf-value');

        $this->client->request('POST', '/account/test-connection', server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => (string) $csrf,
        ], content: (string) json_encode([
            'username' => 'relay@example.test',
            'password' => 'relay-password',
            'imapHost' => '',
            'smtpHost' => '127.0.0.1',
            'smtpPort' => '1',
        ]));

        self::assertResponseIsSuccessful();

        /** @var array{imap: array{ok: bool|null, message: string}, smtp: array{ok: bool|null, message: string}} $result */
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNull($result['imap']['ok'], 'no incoming server is not a failure: ' . $result['imap']['message']);
        self::assertFalse($result['smtp']['ok'], 'and the SMTP half was really tried');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Fill the add form as a plain IMAP account with both servers, then
     * apply the overrides. Port 1 on loopback, so the probe that runs on save
     * is refused at once rather than waiting on a timeout.
     *
     * @param array<string, string> $overrides
     */
    private function submitNew(array $overrides): void
    {
        $crawler = $this->client->request('GET', '/account/new');
        $form    = $crawler->filter('form[name="account"]')->form();

        $values = $overrides + [
            'account[email]'    => 'Relay',
            'account[username]' => sprintf('only-%s@example.test', uniqid('', true)),
            'account[password]' => 'secret',
            'account[imapHost]' => '127.0.0.1',
            'account[imapPort]' => '1',
            'account[smtpHost]' => '127.0.0.1',
            'account[smtpPort]' => '1',
        ];

        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }

        $this->client->submit($form);
    }

    /** @param array<string, string> $overrides */
    private function submitEdit(Account $account, array $overrides): void
    {
        $crawler = $this->client->request('GET', sprintf('/account/%d/edit', $account->id));
        $form    = $crawler->filter('form[name="account"]')->form();

        foreach ($overrides as $name => $value) {
            $form[$name] = $value;
        }

        $this->client->submit($form);

        self::assertResponseIsSuccessful();
    }

    /** The account as the request left it in the database. */
    private function reload(Account $account): Account
    {
        $id = (int) $account->id;

        $this->em->clear();

        $reloaded = $this->em->find(Account::class, $id);

        self::assertInstanceOf(Account::class, $reloaded);

        return $reloaded;
    }

    private function added(): ?Account
    {
        $this->em->clear();

        $accounts = static::getContainer()->get(AccountRepository::class)->findBy(['usr' => $this->user->id]);

        return $accounts[0] ?? null;
    }
}
