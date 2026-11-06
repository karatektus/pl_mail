<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration\BrowserLogin;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Service\Integration\BrowserLogin\NextcloudLoginFlow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Nextcloud's Login Flow v2 (#235), as the protocol documents it: start, then
 * poll until a 404 becomes one 200 with the app password.
 */
final class NextcloudLoginFlowTest extends TestCase
{
    private const string BASE = 'https://cloud.example.com/nc';

    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    private array $requests = [];

    public function testItServesNextcloudOnly(): void
    {
        $flow = $this->flow([]);

        self::assertTrue($flow->supports(Provider::Nextcloud));
        self::assertFalse($flow->supports(Provider::OpenCloud));
    }

    public function testStartReturnsTheLoginAddressAndKeepsThePollTokenAsState(): void
    {
        $flow = $this->flow([$this->started()]);

        $started = $flow->start(self::BASE);

        self::assertSame('https://cloud.example.com/nc/login/v2/flow/abc', $started->loginUrl);
        self::assertSame(['token' => 'poll-token', 'endpoint' => 'https://cloud.example.com/nc/login/v2/poll'], $started->state);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://cloud.example.com/nc/index.php/login/v2', $this->requests[0]['url']);
        // What Nextcloud names the app password after under Devices & sessions.
        self::assertContains('User-Agent: plMail', $this->requests[0]['options']['headers']);
    }

    public function testAServerTooOldForTheFlowSaysSo(): void
    {
        $flow = $this->flow([new MockResponse('', ['http_code' => 404])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nextcloud 16 or later');

        $flow->start(self::BASE);
    }

    /**
     * A server, or a proxy in front of it, naming another host must not be
     * able to send the person, or the poll token, there.
     */
    #[DataProvider('foreignAddresses')]
    public function testStartRefusesAddressesOnAnotherOrigin(string $login, string $endpoint): void
    {
        $flow = $this->flow([new JsonMockResponse(['poll' => ['token' => 't', 'endpoint' => $endpoint], 'login' => $login])]);

        $this->expectExceptionMessage('different address');

        $flow->start(self::BASE);
    }

    /** @return iterable<string,array{string,string}> */
    public static function foreignAddresses(): iterable
    {
        $ok = 'https://cloud.example.com/nc/login/v2/poll';

        yield 'login on another host'   => ['https://evil.example/login', $ok];
        yield 'poll on another host'    => ['https://cloud.example.com/nc/login/v2/flow/x', 'https://evil.example/poll'];
        yield 'poll over plain http'    => ['https://cloud.example.com/nc/login/v2/flow/x', 'http://cloud.example.com/nc/login/v2/poll'];
        yield 'poll on another port'    => ['https://cloud.example.com/nc/login/v2/flow/x', 'https://cloud.example.com:8443/poll'];
    }

    public function testPollIsNullWhileNotYetApproved(): void
    {
        $flow = $this->flow([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($flow->poll(self::BASE, $this->state()));
        self::assertSame('https://cloud.example.com/nc/login/v2/poll', $this->requests[0]['url']);
        self::assertSame('token=poll-token', $this->requests[0]['options']['body']);
    }

    public function testPollHandsBackTheLoginNameAndAppPassword(): void
    {
        $flow = $this->flow([new JsonMockResponse([
            'server'      => 'https://somewhere-else.example',
            'loginName'   => 'alice@example.com',
            'appPassword' => 'abc-app-password',
        ])]);

        $credential = $flow->poll(self::BASE, $this->state());

        self::assertSame('alice@example.com', $credential?->username);
        self::assertSame('abc-app-password', $credential->secret);
    }

    /** The state came back out of a cache; it is checked again before use. */
    public function testPollRefusesAStateWhoseEndpointMovedHost(): void
    {
        $flow = $this->flow([]);

        $this->expectExceptionMessage('can no longer be completed');

        $flow->poll(self::BASE, ['token' => 'poll-token', 'endpoint' => 'https://evil.example/poll']);
        self::assertSame([], $this->requests);
    }

    public function testPollFailsOnAnAnswerWithoutAPassword(): void
    {
        $flow = $this->flow([new JsonMockResponse(['loginName' => 'alice'])]);

        $this->expectExceptionMessage('could not read');

        $flow->poll(self::BASE, $this->state());
    }

    private function started(): JsonMockResponse
    {
        return new JsonMockResponse([
            'poll'  => ['token' => 'poll-token', 'endpoint' => 'https://cloud.example.com/nc/login/v2/poll'],
            'login' => 'https://cloud.example.com/nc/login/v2/flow/abc',
        ]);
    }

    /** @return array<string,string> */
    private function state(): array
    {
        return ['token' => 'poll-token', 'endpoint' => 'https://cloud.example.com/nc/login/v2/poll'];
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function flow(array $responses): NextcloudLoginFlow
    {
        $this->requests = [];

        return new NextcloudLoginFlow(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        }));
    }
}
