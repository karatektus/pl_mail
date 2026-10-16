<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Crypto\WkdCheckResult;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\Keyring;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\SendingKey;
use App\Service\Crypto\WkdDirectory;
use App\Service\Crypto\WkdKeyExport;
use App\Service\Crypto\WkdLookup;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\SeedsWkdPeople;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Settings → Encryption → Web Key Directory: a person sees their address, its
 * directory URL and whether it is published, switches publishing off and on,
 * and checks from outside whether the world sees their current key.
 *
 * The switch is pinned by its effect on the public route, not on the settings
 * bag: "off" means a mail program gets the 404, and that is the claim. Both
 * actions take an address from the form, so both are also pinned as refusing
 * every address that is not the person's own on a served domain — a request
 * made by hand must not withdraw, or look up, somebody else's.
 *
 * The check's three verdicts are asked of WkdDirectory with a WkdLookup whose
 * HTTP client hands the request to this kernel, so "current" means the route
 * served the key the person has now.
 */
final class WkdSettingsTest extends WebTestCase
{
    use SeedsWkdPeople;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private UserKey $userKey;
    private Gpg $gpg;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        [$this->user, $this->userKey] = $this->seedWkdPerson(extraAddress: 'other-' . bin2hex(random_bytes(4)) . '@elsewhere.example');
        $this->gpg = new Gpg((string) $this->user->email);
        $this->seedWkdKey($this->user, $this->userKey, $this->gpg, (string) $this->user->email);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCardShowsTheAddressItsStateAndItsDirectoryUrl(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('[data-testid="wkd-address"][data-email="%s"]', $this->user->email));

        self::assertCount(1, $row);
        self::assertStringContainsString('Published', $row->filter('[data-testid="wkd-state"]')->text());
        self::assertSame(WkdLookup::urls((string) $this->user->email)['advanced'] ?? null, $row->filter('[data-testid="wkd-url"]')->text());
        // The other address is on a domain this installation does not serve,
        // so it is not the directory's to list at all.
        self::assertCount(1, $crawler->filter('[data-testid="wkd-address"]'));
    }

    public function testSwitchingOffStopsServingAndSwitchingOnServesAgain(): void
    {
        self::assertSame(200, $this->publicStatus());

        $this->publish(false);
        self::assertResponseRedirects();
        self::assertSame(404, $this->publicStatus());
        self::assertStringContainsString('you switched it off', $this->client->request('GET', '/settings?section=encryption')->filter('[data-testid="wkd-state"]')->text());

        $this->publish(true);
        self::assertSame(200, $this->publicStatus());
    }

    public function testASwitchWithoutTheTokenChangesNothing(): void
    {
        $this->client->request('POST', '/settings/encryption/wkd/publish', ['email' => $this->user->email, 'publish' => '0', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(200, $this->publicStatus());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatAreNotTheirs(): iterable
    {
        yield 'somebody else on a served domain' => ['someone-else@plmail.test'];
        yield 'their own, on a domain not served' => ['own-on-elsewhere'];
        yield 'not an address at all'            => ['not an address'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesThatAreNotTheirs')]
    public function testNeitherActionTakesAnAddressThatIsNotTheirsOnAServedDomain(string $address): void
    {
        if ('own-on-elsewhere' === $address) {
            $address = (string) $this->em->getRepository(Account::class)->findBy(['usr' => $this->user], ['id' => 'DESC'])[0]->username;
        }

        $token = $this->token();

        foreach (['/settings/encryption/wkd/publish', '/settings/encryption/wkd/check'] as $path) {
            $this->client->request('POST', $path, ['email' => $address, 'publish' => '0', '_token' => $token]);
            self::assertResponseStatusCodeSame(404, $path);
        }

        self::assertSame([], $this->reloadedUser()->wkdUnpublished);
    }

    public function testTheCheckNeedsTheToken(): void
    {
        $this->client->request('POST', '/settings/encryption/wkd/check', ['email' => $this->user->email, '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The button's round trip with the installation's own WkdLookup. `.test`
     * is reserved and resolves nowhere, so the lookup stops at DNS without a
     * request going out, nothing is found, and the card says so rather than
     * claiming success. (The container's lookup cannot be swapped here: it is
     * already made by the time a test runs.)
     */
    public function testTheCheckButtonReportsWhatItFound(): void
    {
        $this->client->request('POST', '/settings/encryption/wkd/check', ['email' => $this->user->email, '_token' => $this->token()]);
        self::assertResponseRedirects();

        $notice = $this->client->followRedirect()->filter('[data-testid="wkd-notice"]');

        self::assertCount(1, $notice);
        self::assertStringContainsString('No key found for ' . $this->user->email, $notice->text());
    }

    public function testTheCheckSaysCurrentWhenTheDirectoryServesTheCurrentKey(): void
    {
        self::assertSame(WkdCheckResult::Current, $this->directoryAskingThisKernel()->check($this->user, (string) $this->user->email));
    }

    /**
     * Another key under the address — another server answering for the
     * domain, or a cache with yesterday's key — is not "current", however
     * valid it is.
     */
    public function testTheCheckSaysOutdatedWhenAnotherKeyAnswers(): void
    {
        $stale     = new Gpg((string) $this->user->email);
        $directory = $this->directory($this->lookup(static fn (): MockResponse => new MockResponse($stale->binary)));

        self::assertSame(WkdCheckResult::Outdated, $directory->check($this->user, (string) $this->user->email));
    }

    public function testTheCheckSaysMissingOnceSwitchedOff(): void
    {
        $this->publish(false);
        $user = $this->reloadedUser();

        self::assertSame(WkdCheckResult::Missing, $this->directoryAskingThisKernel()->check($user, (string) $user->email));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /** As the database has it now, after requests that ran in their own unit of work. */
    private function reloadedUser(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id) ?? self::fail('the person is gone');
    }

    private function publish(bool $on): void
    {
        $this->client->request('POST', '/settings/encryption/wkd/publish', ['email' => $this->user->email, 'publish' => $on ? '1' : '0', '_token' => $this->token()]);
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');

        return (string) $crawler->filter('form[action$="/settings/encryption/wkd/publish"] input[name="_token"]')->attr('value');
    }

    private function publicStatus(): int
    {
        $local = (string) strstr((string) $this->user->email, '@', true);
        $this->client->request('GET', '/.well-known/openpgpkey/plmail.test/hu/' . WkdLookup::hash($local));

        return $this->client->getResponse()->getStatusCode();
    }

    private function directoryAskingThisKernel(): WkdDirectory
    {
        return $this->directory($this->lookup(function (string $method, string $url): MockResponse {
            $parts = parse_url($url);
            $this->client->request($method, $parts['path'] . '?' . ($parts['query'] ?? ''), server: ['HTTP_HOST' => $parts['host'], 'HTTPS' => 'on']);

            return new MockResponse((string) $this->client->getResponse()->getContent(), ['http_code' => $this->client->getResponse()->getStatusCode()]);
        }));
    }

    /** The container's directory, but asking through $lookup. */
    private function directory(WkdLookup $lookup): WkdDirectory
    {
        $container = static::getContainer();

        return new WkdDirectory(
            $container->get(UserRepository::class),
            $container->get(SendingKey::class),
            $container->get(Keyring::class),
            $container->get(WkdKeyExport::class),
            $lookup,
            $this->em,
            $container->get(ClockInterface::class),
            'plmail.test',
        );
    }

    /**
     * @param callable(string, string): MockResponse $answer
     */
    private function lookup(callable $answer): WkdLookup
    {
        return new WkdLookup(new KeyFetcher(
            new MockHttpClient($answer),
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'wkd-check', 'policy' => 'fixed_window', 'limit' => 30, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            static fn (string $host): array => ['93.184.215.14'],
        ), new PublicKeyReader());
    }
}
