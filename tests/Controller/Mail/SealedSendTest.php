<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Helper\SealedMessageStorage;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SendMessageHandler;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailSenderRegistry;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Mail\SendsAjaxCsrf;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * A draft sealed in the browser, and the message the browser built, from the
 * composer's POST to the bytes a sender is handed.
 *
 * The browser half is encryption-sending.spec.ts. What is claimed here is the
 * server's: it keeps no body of a sealed draft whatever it is sent, it puts
 * the built message on the wire byte for byte with the outer headers around
 * it, and it refuses to send one that no longer fits — recipients changed
 * after it was built, a key it was not encrypted to, a draft it cannot read
 * with nothing built for it.
 *
 * The person's own address is the recipient throughout: mail to oneself is
 * encrypted to one's own key, which keeps the keys real (Gpg) and the fixture
 * one person. Contacts' keys are SealGuardTest's.
 */
final class SealedSendTest extends WebTestCase
{
    use SendsAjaxCsrf;

    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string ADDRESS     = 'sealed-self@example.test';
    private const array  DOCK_FRAME  = ['HTTP_TURBO_FRAME' => 'compose_dock'];

    private const string DRAFT = "-----BEGIN PGP MESSAGE-----\n\nwV4DsealedDraft\n-----END PGP MESSAGE-----\n";

    private EntityManagerInterface $em;
    private Connection $connection;
    private Gpg $key;

    /** @var list<Email> */
    private array $sent = [];

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASealedDraftKeepsNoBodyAndComesBackToTheBrowserOnly(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/draft', ['draft' => self::DRAFT], body: '');

        $message = $this->reload($id);

        self::assertTrue($message->sealedDraft);
        self::assertSame(MessageCrypto::PgpEncrypted, $message->crypto, 'the list says "Encrypted message"');
        self::assertNull($message->bodyText);
        self::assertNull($message->bodyHtml);

        // An autosave that carries a body anyway — anything but the composer —
        // does not turn the draft back into one the server can read.
        $this->post($client, $account, '/compose/draft/' . $id, [], body: '<p>LEAKED</p>');

        self::assertTrue($this->reload($id)->sealedDraft);
        self::assertNull($this->reload($id)->bodyHtml, 'a sealed draft keeps no body, whatever is posted');

        $client->request('GET', '/compose/sealed/' . $id);

        self::assertResponseIsSuccessful();
        self::assertSame(trim(self::DRAFT), $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheBuiltMessageGoesOnTheWireByteForByteWithAnAutocryptHeader(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);
        $entity  = $this->encryptedEntity();

        $id = $this->post($client, $account, '/compose/send', [
            'draft'        => self::DRAFT,
            'entity'       => $entity,
            'kind'         => 'encrypted',
            'fingerprints' => $this->key->fingerprint,
            'signer'       => $this->key->fingerprint,
        ], body: '');

        self::assertSame('encrypted', $this->reload($id)->outgoingSeal['kind'] ?? null);

        $this->runHandler($id);

        self::assertCount(1, $this->sent);

        $wire = $this->sent[0]->toString();

        self::assertStringContainsString($entity, $wire, 'the built message is on the wire unchanged');
        self::assertStringContainsString('Subject: Sealed subject', $wire);
        self::assertMatchesRegularExpression('/^Autocrypt: addr=' . preg_quote(self::ADDRESS, '/') . ';\s+keydata=/m', $wire);

        $message = $this->reload($id);

        self::assertNotNull($message->sentAt);
        self::assertSame(MessageCrypto::PgpEncrypted, $message->crypto);
        self::assertFalse($message->sealedDraft);
        self::assertNull($message->outgoingSeal);
        self::assertNotNull($message->rawPath, 'what went out is the raw source the reading pane decrypts');

        $storage = static::getContainer()->get(SealedMessageStorage::class);

        self::assertNull($storage->read($id, SealedMessageStorage::DRAFT));
        self::assertNull($storage->read($id, SealedMessageStorage::OUTGOING));
    }

    /**
     * Header protection (RFC 9788): the subject is inside the encryption, so
     * the outside says "..." — and the row keeps the one the person wrote.
     */
    public function testAProtectedSubjectStaysInsideTheEncryption(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/send', [
            'draft'            => self::DRAFT,
            'entity'           => $this->encryptedEntity(),
            'kind'             => 'encrypted',
            'fingerprints'     => $this->key->fingerprint,
            'protectedSubject' => '1',
        ], body: '');

        $this->runHandler($id);

        self::assertMatchesRegularExpression('/^Subject: \.\.\.\r$/m', $this->sent[0]->toString());
        self::assertStringNotContainsString('Sealed subject', $this->sent[0]->toString());
        self::assertSame('Sealed subject', $this->reload($id)->subject);
    }

    public function testASignedMessageCannotHideItsSubject(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/send', [
            'entity'           => "Content-Type: text/plain; charset=utf-8\r\n\r\nSigned, and readable.",
            'kind'             => 'plain',
            'protectedSubject' => '1',
        ], body: '<p>Signed, and readable.</p>');

        $this->runHandler($id);

        self::assertStringContainsString('Subject: Sealed subject', $this->sent[0]->toString());
    }

    public function testASealedDraftWithNothingBuiltForItIsNotSent(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/draft', ['draft' => self::DRAFT], body: '');
        $this->post($client, $account, '/compose/send/' . $id, [], body: '');

        self::assertStringContainsString('can only be sent from a browser', (string) $client->getResponse()->getContent());

        $this->runHandler($id);

        self::assertSame([], $this->sent);
        self::assertNull($this->reload($id)->sentAt);
    }

    public function testAMessageEncryptedToAnotherKeyIsRefused(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/send', [
            'draft'        => self::DRAFT,
            'entity'       => $this->encryptedEntity(),
            'kind'         => 'encrypted',
            'fingerprints' => str_repeat('ab', 20),
        ], body: '');

        self::assertStringContainsString('key changed while you were writing', (string) $client->getResponse()->getContent());
        self::assertNull($this->reload($id)->outgoingSeal);
    }

    public function testPlainMailPassedOffAsEncryptedIsRefused(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/send', [
            'draft'        => self::DRAFT,
            'entity'       => "Content-Type: text/plain; charset=utf-8\r\n\r\nReadable by anybody.",
            'kind'         => 'encrypted',
            'fingerprints' => $this->key->fingerprint,
        ], body: '');

        self::assertStringContainsString('did not arrive intact', (string) $client->getResponse()->getContent());
        self::assertNull($this->reload($id)->outgoingSeal);
    }

    public function testRecipientsChangedAfterTheMessageWasBuiltStopTheSend(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $id = $this->post($client, $account, '/compose/send', [
            'draft'        => self::DRAFT,
            'entity'       => $this->encryptedEntity(),
            'kind'         => 'encrypted',
            'fingerprints' => $this->key->fingerprint,
        ], body: '');

        // Somebody other than the composer — a JMAP client, say — adds a
        // recipient the message was never encrypted to.
        $message = $this->reload($id);
        $message->ccAddresses = [['name' => '', 'address' => 'eavesdropper@example.test']];
        $this->em->flush();

        $this->runHandler($id);

        self::assertSame([], $this->sent);
        self::assertNull($this->reload($id)->sentAt);
    }

    public function testTheComposerIsToldWhichKeysItsRecipientsHave(): void
    {
        $client  = static::createClient();
        $account = $this->boot($client);

        $client->request('POST', '/contact-keys/recipients', server: $this->ajaxCsrf($client) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'emails' => [self::ADDRESS, 'Nobody@Example.test', 'not an address'],
            'from'   => $account->id . '|' . self::ADDRESS,
        ]));

        self::assertResponseIsSuccessful();

        $answer  = json_decode((string) $client->getResponse()->getContent(), true);
        $byEmail = array_column($answer['recipients'], null, 'email');

        self::assertSame([self::ADDRESS, 'nobody@example.test'], array_keys($byEmail), 'lower-cased, and what is not an address is left out');
        self::assertTrue($byEmail[self::ADDRESS]['own']);
        self::assertSame($this->key->fingerprint, $byEmail[self::ADDRESS]['key']['fingerprint']);
        self::assertNull($byEmail['nobody@example.test']['key']);
        self::assertSame($this->key->fingerprint, $answer['sender']['fingerprint']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * POST the composer's form, with what the browser sealed in `sealed[…]`.
     * Returns the id of the draft it wrote.
     *
     * @param array<string, string> $sealed
     */
    private function post(KernelBrowser $client, Account $account, string $url, array $sealed, string $body): int
    {
        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', $url, [
            'compose' => [
                '_token'      => $token,
                'account'     => $account->id . '|' . self::ADDRESS,
                'toAddresses' => [self::ADDRESS],
                'subject'     => 'Sealed subject',
                'bodyHtml'    => $body,
            ],
            'sealed' => $sealed,
        ]);

        self::assertLessThan(500, $client->getResponse()->getStatusCode());

        $this->em->clear();

        $message = $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account ORDER BY m.id DESC',
        )->setParameter('account', $account->id)->setMaxResults(1)->getOneOrNullResult();

        self::assertInstanceOf(Message::class, $message);

        return (int) $message->id;
    }

    private function encryptedEntity(): string
    {
        return "Content-Type: multipart/encrypted; protocol=\"application/pgp-encrypted\"; boundary=\"b\"\r\n"
            . "\r\n"
            . "--b\r\nContent-Type: application/pgp-encrypted\r\n\r\nVersion: 1\r\n"
            . "--b\r\nContent-Type: application/octet-stream; name=\"encrypted.asc\"\r\n\r\n"
            . "-----BEGIN PGP MESSAGE-----\r\n\r\nwV4DciphertextOnly\r\n-----END PGP MESSAGE-----\r\n"
            . "--b--\r\n";
    }

    private function runHandler(int $id): void
    {
        $this->em->clear();

        $handler = static::getContainer()->get(SendMessageHandler::class);
        $handler(new SendMessageMessage($id));

        $this->em->clear();
    }

    private function reload(int $id): Message
    {
        $this->em->clear();

        $message = $this->em->find(Message::class, $id);

        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    /** The admin, one account for ADDRESS, and an active key of theirs bound to it. */
    private function boot(KernelBrowser $client): Account
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->key        = new Gpg(self::ADDRESS);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $container->set(MailSenderRegistry::class, new MailSenderRegistry([$this->recordingSender()]));

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        $user = $this->em->find(User::class, $user->id);

        $account = new Account();
        $account->usr       = $user;
        $account->name      = 'Sealed Self';
        $account->username  = self::ADDRESS;
        $account->email     = self::ADDRESS;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';
        $this->em->persist($account);

        $userKey = new UserKey($user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);

        $addressKey = new AddressKey($user, $userKey, $this->key->fingerprint, $this->key->armored, "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nx\n", 'v1.x.y', AddressKeyOrigin::Imported, null, null);
        $this->em->persist($addressKey);
        $this->em->persist($addressKey->bind(self::ADDRESS, true));

        $this->em->flush();

        return $account;
    }

    private function recordingSender(): MailSenderInterface
    {
        $test = $this;

        return new class ($test) implements MailSenderInterface {
            public function __construct(private readonly SealedSendTest $test)
            {
            }

            public function supports(Account $account): bool
            {
                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }

            public function send(Email $email, Account $account): bool
            {
                return $this->test->recordSend($email);
            }
        };
    }

    /** @internal called by the recording sender above */
    public function recordSend(Email $email): bool
    {
        $this->sent[] = $email;

        return true;
    }
}
