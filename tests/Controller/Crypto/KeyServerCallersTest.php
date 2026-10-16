<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Command\Test\SeedKeyServerCommand;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Mail\SendsAjaxCsrf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every place that looks a key up today finds a key that only a key server
 * has, and says where it came from: the lookup form under Contacts' keys, the
 * reading banner's "Look up the key", and the composer's recipient check.
 * (JMAP ContactKey/lookup is in KeysCapabilityTest.) And the person's switch
 * stops all of them from asking.
 *
 * Through the real container, whose key servers in the test environment are
 * KeyServerStub (config/services.yaml, `when@test`) — the real request rules
 * and API readers, answering from files planted here, never the internet.
 * The addresses are on `.test`, which is reserved and resolves nowhere, so
 * the WKD lookup that comes first stops at DNS and finds nothing. Each test
 * makes its own address: lookups are cached in cache.app, which outlives a
 * test, and an address another run found would be found from there.
 */
final class KeyServerCallersTest extends WebTestCase
{
    use SendsAjaxCsrf;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Account $account;
    private string $address;

    /** @var list<string> */
    private array $planted = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user    = MailFixtures::user($this->em, 'keyserver-callers');
        $this->account = MailFixtures::account($this->em, $this->user);
        $this->address = sprintf('kim-%s@keyserver-only.test', bin2hex(random_bytes(6)));
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            @unlink($path);
        }

        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheLookupFormFindsAKeyOnlyAKeyServerHasAndSaysWhere(): void
    {
        $key = $this->publish('keys.openpgp.org');

        $this->client->request('POST', '/contact-keys/lookup', ['_token' => $this->token('contact-keys'), 'email' => $this->address]);

        self::assertResponseRedirects('/contact-keys');
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Found a key on the key server keys.openpgp.org', $crawler->filter('[data-testid="contact-keys-notice"]')->text());
        self::assertSame('From the key server keys.openpgp.org', $crawler->filter('[data-testid="contact-key-origin"]')->text());

        $row = $this->repository()->findCurrent($this->user, $this->address);
        self::assertSame($key->fingerprint, $row?->fingerprint);
        self::assertSame(ContactKeyOrigin::KeyServer, $row->origin);
        self::assertSame('keys.openpgp.org', $row->originHost);
        self::assertFalse($row->pinned);
    }

    public function testTheListNamesTheKeyServersAndTheSwitchIsOnByDefault(): void
    {
        $crawler = $this->client->request('GET', '/contact-keys');
        $switch  = $crawler->filter('[data-testid="key-servers-switch"]');

        self::assertCount(1, $switch);
        self::assertStringContainsString('keys.openpgp.org, keys.mailvelope.com', $switch->text());
        self::assertSame('1', $switch->filter('input[name="key_servers"][checked]')->attr('value'));
    }

    public function testSwitchedOffTheLookupDoesNotAskTheKeyServers(): void
    {
        $this->publish('keys.openpgp.org');

        $this->client->request('POST', '/contact-keys/key-servers', ['_token' => $this->token('contact-keys'), 'key_servers' => '0']);
        self::assertResponseRedirects('/contact-keys');
        self::assertFalse($this->reloadedUser()->lookUpKeyServers);

        $this->client->request('POST', '/contact-keys/lookup', ['_token' => $this->token('contact-keys'), 'email' => $this->address]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('The domain publishes no usable key', $crawler->filter('[data-testid="contact-keys-notice"]')->text());
        self::assertNull($this->repository()->findCurrent($this->user, $this->address));

        // And back on: the same lookup finds it.
        $this->client->request('POST', '/contact-keys/key-servers', ['_token' => $this->token('contact-keys'), 'key_servers' => '1']);
        self::assertTrue($this->reloadedUser()->lookUpKeyServers);
    }

    public function testTheSwitchNeedsItsToken(): void
    {
        $this->client->request('POST', '/contact-keys/key-servers', ['_token' => 'forged', 'key_servers' => '0']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reloadedUser()->lookUpKeyServers);
    }

    public function testNothingAnywhereSaysTheKeyServersWereAskedToo(): void
    {
        $this->client->request('POST', '/contact-keys/lookup', ['_token' => $this->token('contact-keys'), 'email' => $this->address]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Neither the domain nor the key servers', $crawler->filter('[data-testid="contact-keys-notice"]')->text());
    }

    public function testTheComposersRecipientCheckFindsAKeyServerKeyAndSaysWhere(): void
    {
        $key = $this->publish('keys.mailvelope.com');

        $this->client->request('POST', '/contact-keys/recipients', server: $this->ajaxCsrf($this->client) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'emails' => [$this->address],
            'lookup' => true,
        ]));

        self::assertResponseIsSuccessful();
        $recipient = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['recipients'][0];

        self::assertSame($key->fingerprint, $recipient['key']['fingerprint']);
        self::assertSame('keyserver', $recipient['key']['origin']);
        self::assertSame('keys.mailvelope.com', $recipient['key']['originHost']);
    }

    public function testTheBannersLookUpFindsTheSendersKeyOnAKeyServerAndSaysWhich(): void
    {
        $key     = $this->publish('keys.mailvelope.com');
        $message = $this->signedMessage($key);

        $this->client->request('POST', sprintf('/contact-keys/message/%d/lookup', $message->id), ['_token' => $this->token('message-key-' . $message->id)], server: [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Found a key on the key server keys.mailvelope.com', (string) $this->client->getResponse()->getContent());
        self::assertSame('keys.mailvelope.com', $this->repository()->findCurrent($this->user, $this->address)?->originHost);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** A key for the test's address, published on the stand-in for $host. */
    private function publish(string $host): Gpg
    {
        $key  = new Gpg($this->address);
        $path = SeedKeyServerCommand::path(static::getContainer()->getParameter('kernel.project_dir') . '/var/test-key-servers', $host, $this->address);

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $key->armored);
        $this->planted[] = $path;

        return $key;
    }

    private function signedMessage(Gpg $key): Message
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = $this->address;
        $message->crypto      = MessageCrypto::PgpSigned;
        static::getContainer()->get(RawMessageResolver::class)->store($message, $key->signedMessage('Hello.'));
        $this->em->flush();

        return $message;
    }

    private function token(string $id): string
    {
        // As ContactKeyControllerTest mints them: in the browser's session,
        // pushed back onto the request stack, since between requests there is
        // no current request to read the session off.
        $this->client->request('GET', '/contact-keys');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function reloadedUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(User::class, $this->user->id) ?? throw new \LogicException('The test user is gone.');
    }

    private function repository(): ContactKeyRepository
    {
        return static::getContainer()->get(ContactKeyRepository::class);
    }
}
