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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * An account that only sends, or only receives, can be added and kept that way
 * (#628).
 *
 * The request came from somebody sending through SMTP2go, which has no mailbox:
 * the form refused an account without an incoming server, so they gave it a
 * made-up one and got a sync error for it every few minutes. What is pinned
 * here is the form and the switches in front of the model — that receiving
 * and sending are an explicit choice on the form, each server required only
 * while its half is on and one half always on; that the edit form switches
 * through AccountServiceSwitch, so the primary follows; and that a half the
 * account has no server for cannot be switched on from Settings, where it
 * would only fail.
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

    public function testARelayIsAddedWithReceivingSwitchedOffAndNoIncomingServer(): void
    {
        $this->submitNew([
            'account[receiving]' => false,
            'account[imapHost]'  => '',
            'account[imapPort]'  => '',
        ]);

        $account = $this->added();

        self::assertNotNull($account, (string) $this->client->getResponse()->getContent());
        self::assertFalse($account->mailEnabled, 'nothing to sync, so nothing to fail');
        self::assertTrue($account->sendEnabled);
        self::assertContains($account->imapHost, [null, ''], 'no server was made up for it');
        self::assertTrue($account->isPrimary, 'the only account that sends is the one new mail goes from');
    }

    public function testAMailboxIsAddedWithSendingSwitchedOffAndNoSmtpServer(): void
    {
        $this->submitNew([
            'account[sending]'  => false,
            'account[smtpHost]' => '',
        ]);

        $account = $this->added();

        self::assertNotNull($account, (string) $this->client->getResponse()->getContent());
        self::assertTrue($account->mailEnabled);
        self::assertFalse($account->sendEnabled, 'not offered in From');
    }

    /**
     * The switch is the choice, not the fields behind it: folded away, the
     * incoming fields may still hold what was typed before it was switched
     * off, and that does not switch receiving back on.
     */
    public function testASwitchedOffHalfStaysOffWhateverItsFoldedFieldsHold(): void
    {
        $this->submitNew(['account[receiving]' => false]);

        $account = $this->added();

        self::assertNotNull($account, (string) $this->client->getResponse()->getContent());
        self::assertFalse($account->mailEnabled);
    }

    public function testSendingSwitchedOnWithoutAnSmtpServerIsRefused(): void
    {
        $this->submitNew(['account[smtpHost]' => '']);

        self::assertNull($this->added(), 'a half that is on needs its server');
        self::assertStringContainsString('Enter the SMTP server', (string) $this->client->getResponse()->getContent());
    }

    public function testReceivingSwitchedOnWithoutAnIncomingServerIsRefused(): void
    {
        $this->submitNew(['account[imapHost]' => '']);

        self::assertNull($this->added());
        self::assertStringContainsString('Enter the incoming server', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAccountWithBothHalvesOffIsRefused(): void
    {
        $this->submitNew([
            'account[receiving]' => false,
            'account[sending]'   => false,
        ]);

        self::assertNull($this->added());
        self::assertStringContainsString('Switch on receiving, sending, or both', (string) $this->client->getResponse()->getContent());
    }

    public function testTheEditFormShowsBothSwitchesWhereTheAccountHasThem(): void
    {
        $account = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);

        $crawler = $this->client->request('GET', sprintf('/account/%d/edit', $account->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="account[receiving]"][role="switch"]:not([checked])'));
        self::assertCount(1, $crawler->filter('input[name="account[sending]"][role="switch"][checked]'));
    }

    public function testSwitchingReceivingOnInTheEditFormGoesThroughTheSwitch(): void
    {
        $relay           = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);
        $relay->imapHost = null;
        $this->em->flush();

        // The password typed again: a new server is a new place for it to go,
        // and the stored one only goes to the servers it was saved for
        // (StoredSecretTarget).
        $this->submitEdit($relay, [
            'account[receiving]' => true,
            'account[imapHost]'  => '127.0.0.1',
            'account[imapPort]'  => '1',
            'account[password]'  => 'secret',
        ]);

        $relay = $this->reload($relay);

        self::assertTrue($relay->mailEnabled, 'what "Set up…" sends somebody to the form to do');
        self::assertTrue($relay->sendEnabled);
    }

    /** A new server with the password left blank is refused, and nothing is saved. */
    public function testANewServerWithTheStoredPasswordIsRefused(): void
    {
        $relay           = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);
        $relay->imapHost = null;
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/account/%d/edit', $relay->id));
        $form    = $crawler->filter('form[name="account"]')->form();

        $this->fill($form, [
            'account[receiving]' => true,
            'account[imapHost]'  => 'attacker.example.net',
            'account[imapPort]'  => '993',
        ]);

        $this->client->submit($form);

        self::assertStringContainsString('Type the password again', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->reload($relay)->imapHost);
    }

    public function testSwitchingThePrimarysSendingOffInTheEditFormMovesThePrimary(): void
    {
        $primary = MailFixtures::account($this->em, $this->user);
        $other   = MailFixtures::account($this->em, $this->user);

        $primary->isPrimary = true;
        $primary->sortOrder = 0;
        $other->sortOrder   = 1;
        $this->em->flush();

        $this->submitEdit($primary, ['account[sending]' => false]);

        $primary = $this->reload($primary);

        self::assertFalse($primary->sendEnabled);
        self::assertFalse($primary->isPrimary, 'through AccountServiceSwitch, not past it');
    }

    public function testSavingTheEditFormUnchangedKeepsBothSwitches(): void
    {
        $account = MailFixtures::account($this->em, $this->user, mailEnabled: false, sendEnabled: true);

        $this->submitEdit($account, []);

        $account = $this->reload($account);

        self::assertFalse($account->mailEnabled);
        self::assertTrue($account->sendEnabled);
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
     * apply the overrides; a bool ticks or unticks a switch. Port 1 on
     * loopback, so the probe that runs on save is refused at once rather
     * than waiting on a timeout.
     *
     * @param array<string, string|bool> $overrides
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

        $this->fill($form, $values);

        $this->client->submit($form);
    }

    /** @param array<string, string|bool> $overrides */
    private function submitEdit(Account $account, array $overrides): void
    {
        $crawler = $this->client->request('GET', sprintf('/account/%d/edit', $account->id));
        $form    = $crawler->filter('form[name="account"]')->form();

        $this->fill($form, $overrides);

        $this->client->submit($form);

        self::assertResponseIsSuccessful();
    }

    /** @param array<string, string|bool> $values */
    private function fill(Form $form, array $values): void
    {
        foreach ($values as $name => $value) {
            if (true === is_bool($value)) {
                $box = $form[$name];

                self::assertInstanceOf(ChoiceFormField::class, $box);

                true === $value ? $box->tick() : $box->untick();

                continue;
            }

            $form[$name] = $value;
        }
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
