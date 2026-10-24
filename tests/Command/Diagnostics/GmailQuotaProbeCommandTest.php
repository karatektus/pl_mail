<?php

declare(strict_types=1);

namespace App\Tests\Command\Diagnostics;

use App\Command\Diagnostics\GmailQuotaProbeCommand;
use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The probe is only worth its minutes if each reading stands alone: a known
 * number of fetches, made one way, inside one clock minute, with the import
 * held meanwhile. Those are what is pinned here. What Google charges for them
 * is the thing it exists to find out, and no test can say.
 */
final class GmailQuotaProbeCommandTest extends TestCase
{
    public function testEachModeFetchesTheSameMessagesInAMinuteOfItsOwn(): void
    {
        $clock = new MockClock('2026-10-13 08:00:40 UTC');
        $pacer = new GmailQuotaPacer($clock);

        /** @var list<array{at: string, method: string, url: string, format: mixed}> $calls */
        $calls = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $clock): MockResponse {
            $calls[] = [
                'at'     => $clock->now()->format('H:i'),
                'method' => $method,
                'url'    => $url,
                'format' => $options['query']['format'] ?? null,
            ];

            if ('POST' === $method) {
                $parts = substr_count((string) $options['body'], 'Content-Id:');

                return new MockResponse(str_repeat("--b\r\nContent-Type: application/http\r\n\r\nHTTP/1.1 200 OK\r\n\r\n{}\r\n", $parts) . '--b--');
            }

            return new MockResponse('{}');
        });

        $tester = $this->tester($this->account(), $this->ids(12), $http, $pacer, $clock);

        self::assertSame(Command::SUCCESS, $tester->execute(['account' => '7', '--count' => '12']));

        $posts   = array_values(array_filter($calls, static fn (array $call): bool => 'POST' === $call['method']));
        $singles = array_values(array_filter($calls, static fn (array $call): bool => 'GET' === $call['method']));

        self::assertCount(2, $posts, 'twelve messages are two batch requests of ten and two');
        self::assertCount(48, $singles, 'and twelve single requests in each of the four formats');

        // A minute each, with an empty one between: 08:02, 08:04, …
        self::assertSame(['08:02'], array_values(array_unique(array_column($posts, 'at'))));
        self::assertSame(
            ['08:04' => 'full', '08:06' => 'metadata', '08:08' => 'minimal', '08:10' => 'raw'],
            array_column($singles, 'format', 'at'),
        );

        self::assertStringContainsString('batch-full', $tester->getDisplay());
        self::assertSame(0, $pacer->waitSeconds($this->account()), 'the import is let go when the probe is over');
    }

    public function testTheAccountsImportIsHeldWhileTheProbeRuns(): void
    {
        $clock   = new MockClock('2026-10-13 08:00:40 UTC');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account();
        $held    = [];

        $http = new MockHttpClient(function () use (&$held, $pacer, $account): MockResponse {
            $held[] = $pacer->waitSeconds($account) > 0;

            return new MockResponse('{}');
        });

        $this->tester($account, $this->ids(3), $http, $pacer, $clock)
            ->execute(['account' => '7', '--mode' => 'single-minimal', '--count' => '3']);

        self::assertSame([true, true, true], $held);
        self::assertSame(212, $pacer->messagesPerMinute($account), 'held, not refused: its pace is what it was');
    }

    public function testARefusedFetchIsCountedAsOneAndSaysWhy(): void
    {
        $refusal = json_encode(['error' => ['code' => 403, 'message' => 'Quota exceeded for quota metric']], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient([
            new MockResponse('{}'),
            new MockResponse($refusal, ['http_code' => 403]),
        ]);

        $tester = $this->tester($this->account(), $this->ids(2), $http, new GmailQuotaPacer(new MockClock()), new MockClock('2026-10-13 08:00:40 UTC'));
        $tester->execute(['account' => '7', '--mode' => 'single-full', '--count' => '2']);

        self::assertStringContainsString('Quota exceeded', $tester->getDisplay());
    }

    public function testAnAccountThatIsNotGmailIsRefused(): void
    {
        $account = new Account();

        $tester = $this->tester($account, [], new MockHttpClient(), new GmailQuotaPacer(new MockClock()), new MockClock());

        self::assertSame(Command::FAILURE, $tester->execute(['account' => '7']));
    }

    /**
     * Nobody knows an account's id, and the web interface does not show one.
     * Without an argument the command says which accounts there are.
     */
    public function testWithoutAnAccountItListsTheGmailAccountsThereAre(): void
    {
        $calls = 0;
        $http  = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}');
        });

        $tester = $this->tester($this->account(), $this->ids(3), $http, new GmailQuotaPacer(new MockClock()), new MockClock());

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('someone@gmail.com', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b7\b/', $tester->getDisplay());
        self::assertSame(0, $calls, 'and fetches nothing until it is told which');
    }

    public function testAnAccountCanBeNamedByItsAddress(): void
    {
        $tester = $this->tester($this->account(), $this->ids(1), new MockHttpClient(new MockResponse('{}')), new GmailQuotaPacer(new MockClock()), new MockClock('2026-10-13 08:00:40 UTC'));

        self::assertSame(Command::SUCCESS, $tester->execute(['account' => 'Someone@Gmail.com', '--mode' => 'single-minimal', '--count' => '1']));
    }

    /**
     * @param list<string> $ids
     */
    private function tester(Account $account, array $ids, MockHttpClient $http, GmailQuotaPacer $pacer, MockClock $clock): CommandTester
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findBy')->willReturn(true === $account->isGmail() ? [$account] : []);

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findSomeGmailIdsForAccount')->willReturn($ids);

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        return new CommandTester(new GmailQuotaProbeCommand($accounts, $messages, $tokens, $http, $pacer, $clock));
    }

    private function account(): Account
    {
        $account                = new Account();
        $account->email         = 'someone@gmail.com';
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = 'google';

        (new ReflectionProperty(Account::class, 'id'))->setValue($account, 7);

        return $account;
    }

    /** @return list<string> */
    private function ids(int $count): array
    {
        return array_map(static fn (int $n): string => 'm' . $n, range(1, $count));
    }
}
