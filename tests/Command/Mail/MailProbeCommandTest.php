<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail;

use App\Command\Mail\MailProbeCommand;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\Mail\AccountRepository;
use App\Service\Demo\DemoMode;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\InboxProbe;
use App\Service\Mail\InboxProbeInterval;
use App\Service\Monitoring\WorkerRestartSignal;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Command\MailFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * `app:mail:probe`, the scheduler's every-30-seconds stand-in for push.
 *
 * Like app:mail:sync it dispatches and never syncs, and every assertion is
 * about *what was queued*. What it adds is the choice of who to look at, which
 * is where a mistake costs something: an IMAP account has nothing to probe, an
 * account whose grant is dead would ask an identity endpoint for a new token
 * every 30 seconds for ever, and an install with many accounts must not let one
 * slow provider hold the scheduler's single worker.
 *
 * The test database may hold other OAuth accounts, so every assertion is about
 * the accounts this test made — told apart by the bearer token the HTTP double
 * sees, which is the only thing that names an account at that layer.
 */
final class MailProbeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    /** @var list<SyncAccountMessage> */
    private array $dispatched = [];

    /** @var list<string> the bearer token of every provider request, in order */
    private array $requests = [];

    /** What Gmail answers: the newest inbox arrival's record id, or null for none. */
    private ?string $gmailArrival = '705';

    /** What Graph answers: when the newest inbox message was received. */
    private string $graphNewest = '2026-10-10T12:00:05Z';

    /** Seconds each provider request appears to take, on the test's clock. */
    private int $requestSeconds = 0;

    private MockClock $clock;
    private InboxProbe $probe;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->dispatched     = [];
        $this->requests       = [];
        $this->gmailArrival   = '705';
        $this->graphNewest    = '2026-10-10T12:00:05Z';
        $this->requestSeconds = 0;
        $this->clock          = new MockClock('2026-10-10 12:00:00');
        $this->probe          = $this->probe();

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testItQueuesASyncForAGmailAccountWithNewMail(): void
    {
        $account = $this->gmail();

        $exit = $this->tester()->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains($account->id, $this->dispatchedAccountIds());

        foreach ($this->dispatched as $message) {
            // The mark Admin → Performance reads to tell this from the
            // quarter-hourly poll and from a real push.
            self::assertSame(SyncTrigger::Probe, $message->trigger);
        }
    }

    public function testItQueuesNothingWhenThereIsNothingNew(): void
    {
        $account = $this->gmail();

        $this->gmailArrival = null;

        $this->tester()->execute([]);

        self::assertContains('token-' . $account->id, $this->requests, 'It did look.');
        self::assertNotContains($account->id, $this->dispatchedAccountIds());
    }

    public function testTheSameArrivalIsQueuedOnceAcrossRuns(): void
    {
        // Each minute is a separate run of the command, and the sync the first
        // one queued has most likely not started. What stops run two queueing
        // another is the probe's memory, which has to outlive a run.
        $account = $this->gmail();

        $this->tester()->execute([]);
        $this->tester()->execute([]);
        $this->tester()->execute([]);

        self::assertSame(1, $this->queuedFor($account->id));
    }

    public function testAMicrosoftAccountIsQueuedWhenItsNewestMessageChanges(): void
    {
        $account = $this->graph();

        $this->tester()->execute([]);
        self::assertNotContains($account->id, $this->dispatchedAccountIds(), 'the first look only remembers');

        $this->graphNewest = '2026-10-10T12:00:06Z';
        $this->tester()->execute([]);

        self::assertSame(1, $this->queuedFor($account->id));
        self::assertSame(SyncTrigger::Probe, $this->dispatched[array_key_last($this->dispatched)]->trigger);
    }

    public function testItLeavesImapInactiveAndRevokedAccountsAlone(): void
    {
        $eligible = $this->gmail();
        $imap     = MailFixtures::account($this->em, MailFixtures::user($this->em, 'probe-imap'));
        $inactive = $this->gmail(isActive: false);
        $revoked  = $this->gmail(revoked: true);

        $this->tester()->execute([]);

        // The presence companion: the same run did probe an account that
        // qualifies, so the absences below are the command's choice and not an
        // empty database or a command that did nothing.
        self::assertContains('token-' . $eligible->id, $this->requests);
        self::assertContains($eligible->id, $this->dispatchedAccountIds());

        foreach ([$imap, $inactive, $revoked] as $skipped) {
            self::assertNotContains('token-' . $skipped->id, $this->requests);
            self::assertNotContains($skipped->id, $this->dispatchedAccountIds());
        }
    }

    public function testItDoesNothingOnADemoInstall(): void
    {
        $this->gmail();

        $exit = $this->tester(demo: true)->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([], $this->requests, 'Demo accounts point at documentation domains.');
        self::assertSame([], $this->dispatched);
    }

    public function testItStopsStartingProbesOnceItsTimeIsUp(): void
    {
        // The scheduler has one worker and the probe runs on it every 30 s,
        // so a run that outlasts its interval delays itself and everything
        // scheduled beside it. Each request takes 15 s here and the budget is
        // 20 (the 30 s interval less one 10 s request timeout): a probe is
        // started while less than 20 s have gone, so one starts at 0 s and
        // one at 15 s, and the third would start at 30 s, which is past it.
        $this->gmail();
        $this->gmail();
        $this->gmail();

        $this->requestSeconds = 15;

        $this->tester()->execute([]);

        self::assertCount(2, $this->requests);
    }

    public function testTheBudgetFollowsTheConfiguredInterval(): void
    {
        // A 60 s interval gives a budget of 50 (60 less the 10 s request
        // timeout), so with 15 s requests a probe is started at 0, 15, 30 and
        // 45 s, and the fifth would start at 60 s, which is past it. The
        // default's two out of three above is what shows this is the interval
        // moving the budget and not the budget having been loosened for all.
        for ($i = 0; $i < 5; $i++) {
            $this->gmail();
        }

        $this->requestSeconds = 15;

        $this->tester(interval: 60)->execute([]);

        self::assertCount(4, $this->requests);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return list<int>
     */
    private function dispatchedAccountIds(): array
    {
        return array_map(
            static fn (SyncAccountMessage $message): int => $message->accountId,
            $this->dispatched,
        );
    }

    private function queuedFor(int $accountId): int
    {
        return count(array_filter($this->dispatchedAccountIds(), static fn (int $id): bool => $id === $accountId));
    }

    private function gmail(bool $isActive = true, bool $revoked = false): Account
    {
        $account = $this->oauth($isActive, MailProvider::Google);

        $account->gmailHistoryId = '700';

        if (true === $revoked) {
            $account->oauthLastRefreshError = 'invalid_grant';
        }

        $this->em->flush();

        return $account;
    }

    private function graph(): Account
    {
        $account = $this->oauth(true, MailProvider::Microsoft);

        $account->lastSyncedAt = new DateTimeImmutable('2026-10-10 11:45:00');

        $this->em->flush();

        return $account;
    }

    private function oauth(bool $isActive, MailProvider $provider): Account
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'probe'), isActive: $isActive);

        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = $provider->value;

        return $account;
    }

    private function probe(): InboxProbe
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            // MockHttpClient has already folded auth_bearer into the headers.
            $this->requests[] = str_replace('Authorization: Bearer ', '', (string) ($options['normalized_headers']['authorization'][0] ?? ''));

            $this->clock->sleep($this->requestSeconds);

            $json = 'gmail.googleapis.com' === parse_url($url, PHP_URL_HOST)
                ? (null === $this->gmailArrival
                    ? ['historyId' => '999']
                    : ['historyId' => '999', 'history' => [['id' => $this->gmailArrival]]])
                : ['value' => [['id' => 'm', 'receivedDateTime' => $this->graphNewest]]];

            return new MockResponse(json_encode($json, JSON_THROW_ON_ERROR), [
                'http_code'        => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(
            static fn (Account $account): string => 'token-' . $account->id,
        );

        return new InboxProbe(
            new GmailApiClient($http, $tokenManager, new GmailQuotaPacer($this->clock)),
            new GraphApiClient($http, $tokenManager),
            new ArrayAdapter(),
            $this->clock,
            new NullLogger(),
        );
    }

    private function tester(bool $demo = false, int $interval = 30): CommandTester
    {
        return new CommandTester(new MailProbeCommand(
            self::getContainer()->get(AccountRepository::class),
            $this->probe,
            $this->bus(),
            new DemoMode($demo, 'PT2H'),
            $this->clock,
            $this->intervalOf($interval),
        ));
    }

    /** A setting that reads `$seconds` from a file of its own, whatever the test environment holds. */
    private function intervalOf(int $seconds): InboxProbeInterval
    {
        unset($_SERVER['MAIL_PROBE_INTERVAL_SECONDS'], $_ENV['MAIL_PROBE_INTERVAL_SECONDS']);

        $file = sys_get_temp_dir().'/plmail-probecmd-'.bin2hex(random_bytes(6)).'.env';
        file_put_contents($file, "MAIL_PROBE_INTERVAL_SECONDS={$seconds}\n");
        $this->tempFiles[] = $file;

        return new InboxProbeInterval(
            new GeneratedSecretsFile($file),
            new WorkerRestartSignal(new ArrayAdapter()),
            new NullLogger(),
        );
    }

    private function bus(): MessageBusInterface
    {
        $recorder = function (object $message): void {
            self::assertInstanceOf(SyncAccountMessage::class, $message);

            $this->dispatched[] = $message;
        };

        return new class ($recorder) implements MessageBusInterface {
            /** @param \Closure(object): void $recorder */
            public function __construct(private readonly \Closure $recorder) {}

            /** @param array<StampInterface> $stamps */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ($this->recorder)($message);

                return new Envelope($message);
            }
        };
    }
}
