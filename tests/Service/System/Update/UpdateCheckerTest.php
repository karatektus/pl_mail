<?php

declare(strict_types=1);

namespace App\Tests\Service\System\Update;

use App\Domain\DTO\System\UpdateStatus;
use App\Domain\Enum\PushTransport;
use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Enum\System\UpdateVerdict;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\System\UpdateCheck;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\System\UpdateCheckRepository;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use App\Service\System\AppVersion;
use App\Service\System\Update\ImageRegistry;
use App\Service\System\Update\SourceHistory;
use App\Service\System\Update\UpdateChecker;
use App\Service\System\Update\UpdateNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Is there a newer build, and are the administrators told about it once?
 *
 * The registry and GitHub are answered with the shapes they really return,
 * captured from ghcr.io and api.github.com: the 401 whose challenge names the
 * token endpoint, the one-architecture index a `main` build gets, the image
 * config whose labels carry the commit, and the compare that says which way
 * two commits differ.
 */
final class UpdateCheckerTest extends TestCase
{
    private const string RUNNING = 'c732dcfcfaa60364e2359219d85add493196a605';
    private const string NEWEST  = '9e3c890b29891ab1c74220da3bd11142b93aed25';

    /** @var list<string> */
    private array $requests = [];

    /**
     * What the administrator's one device was sent.
     *
     * @var \ArrayObject<int, array<string, mixed>>
     */
    private \ArrayObject $pushed;

    private bool $registryDown = false;

    protected function setUp(): void
    {
        $this->pushed = new \ArrayObject();
    }

    public function testANewerReleaseIsFoundAndAnnouncedOnce(): void
    {
        $check   = new UpdateCheck();
        $checker = $this->checker($check, 'v0.2.43', ['status' => 'ahead', 'ahead_by' => 2, 'commits' => [
            ['sha' => 'aaaaaaa1', 'commit' => ['message' => "fix: the older one\n\nBody."]],
            ['sha' => self::NEWEST, 'commit' => ['message' => 'Release v0.2.44']],
        ]]);

        $checker->check();

        $status = UpdateStatus::fromArray($check->status);

        self::assertNotNull($status);
        self::assertSame(UpdateVerdict::Available, $status->verdict);
        self::assertSame('v0.2.44', $status->latest->version);
        self::assertSame(2, $status->aheadBy);
        self::assertSame('Release v0.2.44', $status->changes[0]['subject'], 'newest first, subject line only');
        self::assertCount(1, $this->pushed, 'the administrators are told');
        self::assertSame(UpdateNotifier::PAYLOAD_TYPE, $this->pushed[0]['@type']);

        $checker->check();

        self::assertCount(1, $this->pushed, 'and told once: the hourly check finds the same build every hour');
    }

    /**
     * A main build following releases is AHEAD of the latest release. The two
     * commits differ, and "update available" would be telling it to go back.
     */
    public function testABuildAheadOfTheChannelIsNotToldToGoBack(): void
    {
        $check          = new UpdateCheck();
        $check->channel = UpdateChannel::Releases;

        $this->checker($check, 'main', ['status' => 'behind', 'ahead_by' => 0, 'commits' => []])->check();

        self::assertSame(UpdateVerdict::Current, UpdateStatus::fromArray($check->status)?->verdict);
        self::assertCount(0, $this->pushed);
    }

    /**
     * No answer is a fact about the network, not a reason to forget what the
     * last answer said.
     */
    public function testAnUnreachableRegistryKeepsTheLastAnswer(): void
    {
        $check   = new UpdateCheck();
        $checker = $this->checker($check, 'v0.2.43', ['status' => 'ahead', 'ahead_by' => 1, 'commits' => []]);

        $checker->check();
        $answered = $check->status;

        $this->registryDown = true;
        $checker->check();

        self::assertSame($answered, $check->status);
        self::assertNotNull($check->error);
        self::assertStringContainsString('ghcr.io', $check->error);
    }

    /**
     * Until somebody chooses, a build follows the tag it came from, and a
     * checkout nobody built asks nothing of anyone.
     */
    public function testTheChannelFollowsTheBuildUntilChosen(): void
    {
        self::assertSame(UpdateChannel::Releases, $this->checker(new UpdateCheck(), 'v0.2.44', [])->channel(new UpdateCheck()));
        self::assertSame(UpdateChannel::Main, $this->checker(new UpdateCheck(), 'main', [])->channel(new UpdateCheck()));

        $check = new UpdateCheck();
        $this->checker($check, 'v0.2.44-3-gabc1234-dirty', [], null)->check();

        self::assertSame([], $this->requests, 'an unbuilt checkout sends nothing');
        self::assertNull($check->status);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $compare what GitHub's compare answers
     * @param string|null          $commit  the running build's, null for a checkout nobody stamped
     */
    private function checker(UpdateCheck $check, string $version, array $compare, ?string $commit = self::RUNNING): UpdateChecker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($compare): ResponseInterface {
            $this->requests[] = $url;
            $authorized       = in_array('Authorization: Bearer pull-token', $options['headers'] ?? [], true);

            return match (true) {
                $this->registryDown                      => new MockResponse('', ['http_code' => 503]),
                str_starts_with($url, 'https://ghcr.io/token')    => $this->json(['token' => 'pull-token']),
                false === $authorized && str_contains($url, '/v2/') => new MockResponse('', [
                    'http_code'        => 401,
                    'response_headers' => ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:karatektus/pl_mail:pull"'],
                ]),
                str_ends_with($url, '/manifests/latest') => $this->json(['mediaType' => 'application/vnd.oci.image.index.v1+json', 'manifests' => [
                    ['digest' => 'sha256:attestation', 'platform' => ['os' => 'unknown', 'architecture' => 'unknown']],
                    ['digest' => 'sha256:amd64', 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
                ]]),
                str_ends_with($url, '/manifests/sha256:amd64') => $this->json(['config' => ['digest' => 'sha256:config']]),
                str_ends_with($url, '/blobs/sha256:config')    => $this->json(['config' => ['Labels' => [
                    'org.opencontainers.image.revision' => self::NEWEST,
                    'org.opencontainers.image.version'  => 'v0.2.44',
                    'org.opencontainers.image.created'  => '2026-09-24T11:31:25.389Z',
                    'org.opencontainers.image.source'   => 'https://github.com/karatektus/pl_mail',
                ]]]),
                str_starts_with($url, 'https://api.github.com/repos/karatektus/pl_mail/compare/') => $this->json($compare + ['html_url' => 'https://github.com/karatektus/pl_mail/compare/x...y']),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        $checks = $this->createStub(UpdateCheckRepository::class);
        $checks->method('current')->willReturn($check);
        $checks->method('currentOrNew')->willReturn($check);

        $appVersion = new AppVersion($version, $commit);

        return new UpdateChecker(
            $checks,
            new ImageRegistry($http, 'ghcr.io/karatektus/pl_mail'),
            new SourceHistory($http),
            $appVersion,
            $this->notifier($appVersion),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    /** A notifier whose one administrator has one browser, which records what it is sent. */
    private function notifier(AppVersion $appVersion): UpdateNotifier
    {
        $admin = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($admin, 42);

        $users = $this->createStub(UserRepository::class);
        $users->method('findAdmins')->willReturn([$admin]);

        $subscriptions = $this->createStub(PushSubscriptionRepository::class);
        $subscriptions->method('findDeliverableForUser')->willReturn([PushSubscription::webPush($admin, 'laptop', 'https://push.example/laptop')]);

        $sender = new class ($this->pushed) implements PushSenderInterface {
            /** @param \ArrayObject<int, array<string, mixed>> $pushed */
            public function __construct(private readonly \ArrayObject $pushed)
            {
            }

            public function transport(): PushTransport
            {
                return PushTransport::WebPush;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(PushSubscription $subscription, array $payload): bool
            {
                $this->pushed->append($payload);

                return true;
            }
        };

        return new UpdateNotifier(
            $users,
            $subscriptions,
            new PushSenderRegistry([$sender]),
            new IdentityTranslator(),
            $appVersion,
            new NullLogger(),
        );
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
