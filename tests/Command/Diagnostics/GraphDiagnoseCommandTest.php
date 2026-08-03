<?php

declare(strict_types=1);

namespace App\Tests\Command\Diagnostics;

use App\Command\Diagnostics\GraphDiagnoseCommand;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The command an operator reaches for when a Microsoft account will not sync.
 *
 * Its entire value is the *interpretation* at the end: the probe table alone
 * says "403, 403, 403", which is what the operator already knew. What they
 * cannot work out unaided is that identity succeeding while every mail
 * endpoint fails means the mailbox does not exist rather than the credentials
 * being wrong — and that a failing masterCategories probe is not a broken
 * account at all. Getting that verdict backwards sends somebody to delete and
 * reconnect an account that was fine.
 *
 * So these tests stage Graph's answers and assert on which conclusion is
 * drawn. The HTTP client is a MockHttpClient for the same reason
 * GmailApiClientFailureTest uses one: the failure modes worth covering are
 * status codes nobody can produce on demand from a real tenant.
 */
final class GraphDiagnoseCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnUnknownAccountIdIsRefusedBeforeAnyRequestIsMade(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('No account means nothing to probe, so nothing may be requested.');
        });

        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute(['accountId' => '99999999']));
        self::assertStringContainsString('No such account', $tester->getDisplay());
    }

    /**
     * Pointing this at an IMAP or Gmail account is an easy slip, and every
     * probe below would fail in a way that reads like a broken Microsoft
     * tenant.
     */
    public function testAnAccountThatIsNotMicrosoftIsRefused(): void
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'graph'));

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('A non-Microsoft account must not be probed against Graph.');
        });

        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute(['accountId' => (string) $account->id]));
        self::assertStringContainsString('not a Microsoft account', $tester->getDisplay());
    }

    public function testItReportsHealthyGraphAccessWhenEveryProbePasses(): void
    {
        $account = $this->microsoftAccount();
        $tester  = $this->tester($this->respondingWith(200));

        $exit = $tester->execute(['accountId' => (string) $account->id]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('All probes passed', $display);
        self::assertStringNotContainsString('mailbox, not the credentials', $display);
    }

    /**
     * The diagnosis this command exists for. Everything answering 403 except
     * /me is not a credentials problem, and saying so is the difference between
     * a five-minute fix and deleting a working account.
     */
    public function testIdentityWorkingWhileMailFailsIsBlamedOnTheMailbox(): void
    {
        $account = $this->microsoftAccount();

        $tester = $this->tester($this->respondingWith(
            static fn (string $url): int => str_contains($url, '/me/mailFolders')
                || str_contains($url, '/me/messages')
                || str_contains($url, '/me/outlook')
                    ? 403
                    : 200,
        ));

        $tester->execute(['accountId' => (string) $account->id]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('mailbox, not the credentials', $display);
        self::assertStringNotContainsString('All probes passed', $display);

        // The two causes are the actionable half of the message; a warning that
        // only says "mail is broken" is the status code again.
        self::assertStringContainsString('No Outlook mailbox exists', $display);
    }

    public function testATokenThatIsRejectedOutrightIsBlamedOnTheToken(): void
    {
        $account = $this->microsoftAccount();
        $tester  = $this->tester($this->respondingWith(401));

        $tester->execute(['accountId' => (string) $account->id]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('the token itself is not being accepted', $display);
        self::assertStringNotContainsString('mailbox, not the credentials', $display);
    }

    /**
     * Master categories need a scope Mail.ReadWrite does not carry, so this
     * probe fails on plenty of perfectly healthy accounts. Reporting it as a
     * broken account would have operators reconnecting for nothing.
     */
    public function testAMissingCategoryScopeIsReportedAsAGapNotABrokenAccount(): void
    {
        $account = $this->microsoftAccount();

        $tester = $this->tester($this->respondingWith(
            static fn (string $url): int => str_contains($url, 'masterCategories') ? 403 : 200,
        ));

        $exit    = $tester->execute(['accountId' => (string) $account->id]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Mail sync is healthy', $display);
        self::assertStringContainsString('MailboxSettings.ReadWrite', $display);
        self::assertStringNotContainsString('All probes passed', $display);
    }

    /**
     * A transport failure has to be attributable too — a probe row of "0,
     * transport: …" is a different problem from a 403 and must not be
     * swallowed into an exception that loses the other probes with it.
     */
    public function testATransportFailureIsReportedPerProbeRatherThanAborting(): void
    {
        $account = $this->microsoftAccount();

        $tester = $this->tester(new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (true === str_contains($url, '/me/messages')) {
                return new MockResponse('', ['error' => 'connection reset by peer']);
            }

            return new MockResponse('{}', ['http_code' => 200]);
        }));

        $tester->execute(['accountId' => (string) $account->id]);

        self::assertStringContainsString('transport:', $tester->getDisplay());
    }

    public function testATokenThatCannotBeObtainedStopsBeforeProbing(): void
    {
        $account = $this->microsoftAccount();

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('refresh_token is invalid'));

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Without a token there is nothing to probe with.');
        });

        $tester = new CommandTester(new GraphDiagnoseCommand(
            self::getContainer()->get(AccountRepository::class),
            $tokenManager,
            $client,
        ));

        self::assertSame(Command::FAILURE, $tester->execute(['accountId' => (string) $account->id]));
        self::assertStringContainsString('refresh_token is invalid', $tester->getDisplay());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function microsoftAccount(): Account
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'graph'));
        $account->authType = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;
        // The `Ew…` prefix is what the command uses to recognise a personal
        // Microsoft account, whose scopes it can only infer from the probes.
        $account->oauthAccessToken = 'EwAoA61DBAAU' . bin2hex(random_bytes(4));
        $account->oauthTokenExpiry = new \DateTimeImmutable('+1 hour');

        $this->em->flush();

        return $account;
    }

    /**
     * @param int|\Closure(string): int $status a fixed status, or one chosen per URL
     */
    private function respondingWith(int|\Closure $status): MockHttpClient
    {
        $resolve = $status instanceof \Closure ? $status : static fn (): int => $status;

        return new MockHttpClient(static function (string $method, string $url) use ($resolve): MockResponse {
            $code = $resolve($url);

            $body = $code >= 200 && $code < 300
                ? ['mail' => 'someone@example.test', 'value' => []]
                : ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']];

            return new MockResponse(
                json_encode($body, JSON_THROW_ON_ERROR),
                ['http_code' => $code, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    private function tester(MockHttpClient $client): CommandTester
    {
        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('probe-token');

        return new CommandTester(new GraphDiagnoseCommand(
            self::getContainer()->get(AccountRepository::class),
            $tokenManager,
            $client,
        ));
    }
}
