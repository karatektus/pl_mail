<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\WkdLookup;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\SeedsWkdPeople;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * plMail's Web Key Directory, asked the way a mail program asks it: by the
 * hash of a local part, under either of the draft's two URL shapes, with no
 * session.
 *
 * What it must get right is narrow and each part fails silently. The key has
 * to be the binary key of exactly that address, with no other user ID on it;
 * it has to be the person's current key, and a replaced or revoked one must
 * stop being served at once; and every way of there being no key — a domain
 * the installation does not serve, an unknown hash, an address switched off,
 * the directory off altogether — must be the same 404, so the answer says no
 * more than it has to.
 *
 * The round trip at the end asks the route through WkdLookup, plMail's own
 * client, with an HTTP client that hands the request to this kernel: what the
 * directory serves is then proven to be what a lookup accepts, rather than
 * two halves each agreeing with a test.
 *
 * Real keys from gpg, because the reduction and the "user ID for this
 * address" verdict are gpg's.
 */
final class WkdControllerTest extends WebTestCase
{
    use SeedsWkdPeople;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        static::getContainer()->get('limiter.wkd_serve')->create('127.0.0.1')->reset();

        parent::tearDown();
    }

    public function testTheAdvancedMethodServesTheBinaryKeyUnderTheHashOfTheLocalPart(): void
    {
        [$user, $gpg] = $this->published();
        $local        = $this->local($user);

        $this->client->request('GET', sprintf('/.well-known/openpgpkey/plmail.test/hu/%s?l=%s', WkdLookup::hash($local), $local));
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();

        // Binary, not armored: the first byte is an OpenPGP packet header for
        // a public key (old format 0x98–0x9B or new format 0xC6).
        self::assertStringNotContainsString('BEGIN PGP', $body);
        self::assertTrue(0x98 === (ord($body[0]) & 0xFC) || 0xC6 === ord($body[0]), 'not a binary public key packet');
        self::assertSame([$gpg->fingerprint], array_map(static fn ($key): string => $key->fingerprint, new PublicKeyReader()->read($body)));
    }

    public function testTheDirectMethodServesTheSameKeyWithOrWithoutTheLocalPart(): void
    {
        [$user] = $this->published();
        $hash   = WkdLookup::hash($this->local($user));

        $advanced = $this->get(sprintf('/.well-known/openpgpkey/plmail.test/hu/%s', $hash));
        $direct   = $this->get(sprintf('/.well-known/openpgpkey/hu/%s?l=%s', $hash, $this->local($user)), 'plmail.test');
        $bare     = $this->get(sprintf('/.well-known/openpgpkey/hu/%s', $hash), 'plmail.test');

        self::assertSame(200, $direct->getStatusCode());
        self::assertSame($advanced->getContent(), $direct->getContent());
        self::assertSame($advanced->getContent(), $bare->getContent());
    }

    /**
     * The local part in `?l=` is a hint, and only the hash decides: a hint
     * naming somebody else must not fetch that person's key.
     */
    public function testTheHashDecidesAndTheLocalPartHintDoesNot(): void
    {
        [$anna] = $this->published();
        [$bob]  = $this->published();

        $response = $this->get(sprintf('/.well-known/openpgpkey/plmail.test/hu/%s?l=%s', WkdLookup::hash($this->local($anna)), $this->local($bob)));

        self::assertSame($this->fingerprintOf($anna), $this->servedFingerprint($response));
    }

    public function testTheServedKeyCarriesOnlyTheUserIdOfTheAddressAskedFor(): void
    {
        [$user, $gpg] = $this->published(extraUserIds: ['private@elsewhere.example', 'another@plmail.test']);

        self::assertCount(3, new PublicKeyReader()->read($gpg->armored)[0]->addresses, 'the fixture should carry three user IDs');

        $served = new PublicKeyReader()->read((string) $this->get($this->advancedPath($user))->getContent());

        self::assertCount(1, $served);
        self::assertSame([strtolower((string) $user->email)], $served[0]->addresses);
        self::assertTrue($served[0]->canEncrypt, 'the encryption subkey must survive the reduction');
    }

    public function testTheAnswerIsPublicCacheableAndReadableFromAnyOrigin(): void
    {
        [$user] = $this->published();

        $response = $this->get($this->advancedPath($user));

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('600', $response->headers->getCacheControlDirective('max-age'));
        self::assertNotNull($response->getEtag());
        // No session was started for it: a cookie here would make the answer
        // private and personal, which it is not.
        self::assertSame([], $response->headers->getCookies());

        $this->client->request('GET', $this->advancedPath($user), server: ['HTTP_IF_NONE_MATCH' => $response->getEtag()]);
        self::assertSame(304, $this->client->getResponse()->getStatusCode());
    }

    public function testThePolicyFilesExistForAServedDomainOnly(): void
    {
        $advanced = $this->get('/.well-known/openpgpkey/plmail.test/policy');
        $direct   = $this->get('/.well-known/openpgpkey/policy', 'plmail.test');

        foreach ([$advanced, $direct] as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        }

        self::assertSame(404, $this->get('/.well-known/openpgpkey/gmail.com/policy')->getStatusCode());
    }

    /**
     * Every way of there being no key gives the same answer, byte for byte and
     * header for header, so none of them can be told from another — "no such
     * person", "switched off" and "not this installation's domain" alike.
     */
    public function testEveryMissIsTheSame404(): void
    {
        [$published] = $this->published();
        [$switchedOff] = $this->published();
        $switchedOff->wkdUnpublished = [(string) $switchedOff->email];

        [$replaced, , $replacedKey] = $this->published();
        $replacedKey->markObsolete();

        [$expired] = $this->published(expiresAt: new DateTimeImmutable('-1 day'));

        // A person with a key bound to an address that is theirs, but not the
        // address they sign in with: plMail never vouches for that one.
        [$aliasOwner, $aliasUserKey] = $this->seedWkdPerson(extraAddress: 'alias-' . bin2hex(random_bytes(4)) . '@plmail.test');
        $alias = $this->em->getRepository(Account::class)->findBy(['usr' => $aliasOwner], ['id' => 'DESC'])[0]->username;
        $this->seedWkdKey($aliasOwner, $aliasUserKey, new Gpg((string) $alias), (string) $alias);

        // Somebody here whose sign-in address is on a domain the installation
        // does not serve, with a key: the very case the domain list exists for.
        [$foreigner, $foreignUserKey] = $this->seedWkdPerson(domain: 'elsewhere.example');
        $this->seedWkdKey($foreigner, $foreignUserKey, new Gpg((string) $foreigner->email), (string) $foreigner->email);

        $this->em->flush();

        $misses = [
            'unknown hash'        => $this->get('/.well-known/openpgpkey/plmail.test/hu/' . WkdLookup::hash('nobody-' . bin2hex(random_bytes(4)))),
            'foreign domain'      => $this->get('/.well-known/openpgpkey/elsewhere.example/hu/' . WkdLookup::hash($this->local($foreigner))),
            'foreign domain, direct' => $this->get('/.well-known/openpgpkey/hu/' . WkdLookup::hash($this->local($foreigner)), 'elsewhere.example'),
            'switched off'        => $this->get($this->advancedPath($switchedOff)),
            'replaced key'        => $this->get($this->advancedPath($replaced)),
            'expired key'         => $this->get($this->advancedPath($expired)),
            'not sign-in address' => $this->get('/.well-known/openpgpkey/plmail.test/hu/' . WkdLookup::hash((string) strstr((string) $alias, '@', true))),
            'direct, unknown hash' => $this->get('/.well-known/openpgpkey/hu/' . WkdLookup::hash('nobody-' . bin2hex(random_bytes(4))), 'plmail.test'),
            'direct, switched off' => $this->get('/.well-known/openpgpkey/hu/' . WkdLookup::hash($this->local($switchedOff)), 'plmail.test'),
        ];

        $reference = $misses['unknown hash'];

        self::assertSame(404, $reference->getStatusCode());
        self::assertSame(200, $this->get($this->advancedPath($published))->getStatusCode(), 'the control case must be served');

        foreach ($misses as $case => $response) {
            self::assertSame(404, $response->getStatusCode(), $case);
            self::assertSame($reference->getContent(), $response->getContent(), $case);
            self::assertSame($this->comparableHeaders($reference), $this->comparableHeaders($response), $case);
        }
    }

    public function testARenewedKeyIsServedAtOnceAndTheOldOneNoMore(): void
    {
        [$user, $old, $oldKey, $userKey] = $this->published();

        self::assertSame($old->fingerprint, $this->servedFingerprint($this->get($this->advancedPath($user))));

        $oldKey->markObsolete();
        $renewed = new Gpg((string) $user->email);
        $this->seedWkdKey($user, $userKey, $renewed, (string) $user->email);

        self::assertSame($renewed->fingerprint, $this->servedFingerprint($this->get($this->advancedPath($user))));
    }

    /**
     * A key handed out may be what contacts encrypt to from now on, so it can
     * no longer be deleted as unseen when another replaces it.
     */
    public function testServingAKeyMarksItAsHandedOut(): void
    {
        [$user, , $key] = $this->published();

        self::assertNull($key->firstUsedAt);

        $this->get($this->advancedPath($user));

        $this->em->clear();
        self::assertNotNull($this->em->find(AddressKey::class, $key->id)?->firstUsedAt);
    }

    /**
     * A forward that rewrites the host to plMail's own name arrives under a
     * host that is no mail domain; with one domain configured there is no
     * doubt which one it meant.
     */
    public function testTheDirectMethodUnderAnotherHostAnswersForTheOnlyDomain(): void
    {
        [$user] = $this->published();

        $response = $this->get('/.well-known/openpgpkey/hu/' . WkdLookup::hash($this->local($user)), 'mail.elsewhere.example');

        self::assertSame($this->fingerprintOf($user), $this->servedFingerprint($response));
    }

    public function testNothingIsServedWhenTheInstallationNamesNoDomain(): void
    {
        [$user] = $this->published();
        $path   = $this->advancedPath($user);

        self::assertSame(200, $this->get($path)->getStatusCode());

        // A kernel of its own, booted with the variable empty: the directory
        // reads it when it is made, and this one was made with it set.
        $this->em->getConnection()->rollBack();
        self::ensureKernelShutdown();

        $_SERVER['APP_WKD_DOMAINS'] = $_ENV['APP_WKD_DOMAINS'] = '';

        try {
            $client = static::createClient();
            $em     = static::getContainer()->get(EntityManagerInterface::class);
            $em->getConnection()->beginTransaction();
            $this->em = $em;

            [$user] = $this->published();

            $client->request('GET', $this->advancedPath($user));
            self::assertSame(404, $client->getResponse()->getStatusCode());

            $client->request('GET', '/.well-known/openpgpkey/plmail.test/policy');
            self::assertSame(404, $client->getResponse()->getStatusCode());

            $client->request('GET', '/.well-known/openpgpkey/policy', server: ['HTTP_HOST' => 'plmail.test']);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        } finally {
            $_SERVER['APP_WKD_DOMAINS'] = $_ENV['APP_WKD_DOMAINS'] = 'plmail.test';
        }
    }

    public function testAFloodFromOneAddressIsSlowedDown(): void
    {
        [$user] = $this->published();

        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = static::getContainer()->get('limiter.wkd_serve');
        $limiter->create('127.0.0.1')->consume(300);

        $response = $this->get($this->advancedPath($user));

        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
    }

    /**
     * WkdLookup — plMail's own client, with every rule it holds a domain to —
     * asks this kernel, by both URL shapes in turn, and accepts the key.
     */
    public function testWhatIsServedIsWhatALookupAccepts(): void
    {
        [$user, $gpg] = $this->published(extraUserIds: ['private@elsewhere.example']);

        $asked  = [];
        $lookup = new WkdLookup(new KeyFetcher(
            new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
                $asked[] = $url;
                $parts   = parse_url($url);

                $this->client->request($method, $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : ''), server: ['HTTP_HOST' => $parts['host'], 'HTTPS' => 'on']);
                $response = $this->client->getResponse();

                return new MockResponse((string) $response->getContent(), ['http_code' => $response->getStatusCode()]);
            }),
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'wkd', 'policy' => 'fixed_window', 'limit' => 30, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            static fn (string $host): array => ['93.184.215.14'],
        ), new PublicKeyReader());

        $keys = $lookup->find($user, (string) $user->email);

        self::assertCount(1, $keys);
        self::assertSame($gpg->fingerprint, $keys[0]->fingerprint);
        self::assertSame([strtolower((string) $user->email)], $keys[0]->addresses);
        self::assertStringStartsWith('https://openpgpkey.plmail.test/.well-known/openpgpkey/plmail.test/hu/', $asked[0]);

        // And the direct method on its own, as when there is no openpgpkey
        // subdomain: the advanced URL is refused, the direct one answers.
        $direct = new WkdLookup(new KeyFetcher(
            new MockHttpClient(function (string $method, string $url): MockResponse {
                $parts = parse_url($url);

                if (true === str_starts_with((string) $parts['host'], 'openpgpkey.')) {
                    return new MockResponse('', ['http_code' => 404]);
                }

                $this->client->request($method, $parts['path'] . '?' . ($parts['query'] ?? ''), server: ['HTTP_HOST' => $parts['host'], 'HTTPS' => 'on']);

                return new MockResponse((string) $this->client->getResponse()->getContent(), ['http_code' => $this->client->getResponse()->getStatusCode()]);
            }),
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'wkd-direct', 'policy' => 'fixed_window', 'limit' => 30, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            static fn (string $host): array => ['93.184.215.14'],
        ), new PublicKeyReader());

        self::assertSame([$gpg->fingerprint], array_map(static fn ($key): string => $key->fingerprint, $direct->find($user, (string) $user->email)));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * A person whose key is published.
     *
     * @param list<string> $extraUserIds further user IDs on the key
     *
     * @return array{0: User, 1: Gpg, 2: AddressKey, 3: UserKey}
     */
    private function published(array $extraUserIds = [], ?DateTimeImmutable $expiresAt = null): array
    {
        [$user, $userKey] = $this->seedWkdPerson();
        $gpg              = new Gpg((string) $user->email, $extraUserIds);
        $key              = $this->seedWkdKey($user, $userKey, $gpg, (string) $user->email, $expiresAt);

        return [$user, $gpg, $key, $userKey];
    }

    private function local(User $user): string
    {
        return (string) strstr((string) $user->email, '@', true);
    }

    private function advancedPath(User $user): string
    {
        return '/.well-known/openpgpkey/plmail.test/hu/' . WkdLookup::hash($this->local($user));
    }

    private function get(string $path, ?string $host = null): Response
    {
        $this->client->request('GET', $path, server: null === $host ? [] : ['HTTP_HOST' => $host]);

        return $this->client->getResponse();
    }

    private function servedFingerprint(Response $response): ?string
    {
        self::assertSame(200, $response->getStatusCode());

        return new PublicKeyReader()->read((string) $response->getContent())[0]->fingerprint ?? null;
    }

    private function fingerprintOf(User $user): ?string
    {
        foreach ($this->em->getRepository(AddressKey::class)->findBy(['usr' => $user]) as $key) {
            return $key->fingerprint;
        }

        return null;
    }

    /**
     * Every header but the ones that differ per request anyway.
     *
     * @return array<string, list<string|null>>
     */
    private function comparableHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date'], $headers['x-request-id'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $headers;
    }
}
