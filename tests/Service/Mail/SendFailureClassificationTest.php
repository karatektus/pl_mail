<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendFailureException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GmailApiSender;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\GraphApiSender;
use App\Service\Mail\SmtpDsnFactory;
use App\Service\Mail\SmtpMailSender;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Email;

/**
 * What each sender makes of a failed send (#125): try again, give up, or the
 * mail may already be out.
 *
 * Against canned answers — MockHttpClient for Gmail and Graph, the mailer's
 * own exceptions for SMTP — because the claim is about reading an answer, and
 * the answers are what the providers are documented to give.
 */
final class SendFailureClassificationTest extends TestCase
{
    /**
     * @return array<array-key, array{MockResponse, class-string<SendFailureException>|null, SendFailure|null}>
     */
    public static function gmailAnswers(): array
    {
        $json = static fn (int $status, string $reason = ''): MockResponse => new MockResponse(
            (string) json_encode(['error' => ['code' => $status, 'message' => 'x', 'errors' => [['reason' => $reason]]]]),
            ['http_code' => $status],
        );

        return [
            'sent'                         => [new MockResponse('{"id":"x"}', ['http_code' => 200]), null, null],
            '429'                          => [$json(429, 'rateLimitExceeded'), SendDeferredException::class, SendFailure::RateLimited],
            '403 for quota'                => [$json(403, 'userRateLimitExceeded'), SendDeferredException::class, SendFailure::RateLimited],
            '403 for permissions'          => [$json(403, 'insufficientPermissions'), SendRefusedException::class, SendFailure::Refused],
            '400'                          => [$json(400, 'invalidArgument'), SendRefusedException::class, SendFailure::Refused],
            '401'                          => [$json(401, 'authError'), SendRefusedException::class, SendFailure::Refused],
            '500'                          => [$json(500, 'backendError'), SendDeferredException::class, SendFailure::Unavailable],
            '503'                          => [$json(503, 'backendError'), SendDeferredException::class, SendFailure::Unavailable],
            '502, an answer lost'          => [$json(502), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
            '504, an answer lost'          => [$json(504), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
            'no DNS answer: never left'    => [new MockResponse('', ['error' => 'Could not resolve host: gmail.googleapis.com']), SendDeferredException::class, SendFailure::Unavailable],
            'refused connection'           => [new MockResponse('', ['error' => 'Failed to connect to gmail.googleapis.com port 443: Connection refused']), SendDeferredException::class, SendFailure::Unavailable],
            'timeout waiting: may be out'  => [new MockResponse('', ['error' => 'Idle timeout reached for "https://gmail.googleapis.com/".']), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
        ];
    }

    /**
     * @param class-string<SendFailureException>|null $expected
     */
    #[DataProvider('gmailAnswers')]
    public function testGmail(MockResponse $answer, ?string $expected, ?SendFailure $failure): void
    {
        $sender = new GmailApiSender(
            new GmailApiClient(new MockHttpClient($answer), $this->tokens(), new GmailQuotaPacer(new MockClock())),
            new NullLogger(),
        );

        $this->assertClassified(fn () => $sender->deliver($this->email(), new Account()), $expected, $failure);
    }

    public function testGmailRateLimitCarriesAWait(): void
    {
        $sender = new GmailApiSender(
            new GmailApiClient(
                new MockHttpClient(new MockResponse(
                    (string) json_encode(['error' => ['code' => 429, 'errors' => [['reason' => 'rateLimitExceeded']]]]),
                    ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']],
                )),
                $this->tokens(),
                new GmailQuotaPacer(new MockClock()),
            ),
            new NullLogger(),
        );

        try {
            $sender->deliver($this->email(), new Account());
            self::fail('a rate limit is not a send');
        } catch (SendDeferredException $deferred) {
            self::assertNotNull($deferred->retryAfterSeconds(), 'the wait Gmail asked for, through the pacer');
        }
    }

    public function testGmailLooksInSentByMessageId(): void
    {
        $asked = null;

        $http = new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
            $asked = urldecode($url);

            return new MockResponse('{"messages":[{"id":"x"}]}', ['http_code' => 200]);
        });

        $sender = new GmailApiSender(new GmailApiClient($http, $this->tokens(), new GmailQuotaPacer(new MockClock())), new NullLogger());

        self::assertTrue($sender->isInSent(new Account(), 'abc@example.test'));
        self::assertStringContainsString('in:sent rfc822msgid:abc@example.test', (string) $asked);
    }

    /**
     * @return array<array-key, array{MockResponse, class-string<SendFailureException>|null, SendFailure|null}>
     */
    public static function graphAnswers(): array
    {
        return [
            'sent'                        => [new MockResponse('', ['http_code' => 202]), null, null],
            '429'                         => [new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '7']]), SendDeferredException::class, SendFailure::RateLimited],
            '503'                         => [new MockResponse('{}', ['http_code' => 503]), SendDeferredException::class, SendFailure::RateLimited],
            '500'                         => [new MockResponse('{}', ['http_code' => 500]), SendDeferredException::class, SendFailure::Unavailable],
            '502, an answer lost'         => [new MockResponse('{}', ['http_code' => 502]), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
            '400'                         => [new MockResponse('{}', ['http_code' => 400]), SendRefusedException::class, SendFailure::Refused],
            '403'                         => [new MockResponse('{}', ['http_code' => 403]), SendRefusedException::class, SendFailure::Refused],
            'no DNS answer: never left'   => [new MockResponse('', ['error' => 'Could not resolve host: graph.microsoft.com']), SendDeferredException::class, SendFailure::Unavailable],
            'timeout waiting: may be out' => [new MockResponse('', ['error' => 'Operation timed out after 30001 milliseconds']), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
        ];
    }

    /**
     * @param class-string<SendFailureException>|null $expected
     */
    #[DataProvider('graphAnswers')]
    public function testGraph(MockResponse $answer, ?string $expected, ?SendFailure $failure): void
    {
        $sender = new GraphApiSender(new GraphApiClient(new MockHttpClient($answer), $this->tokens()), new NullLogger());

        $this->assertClassified(fn () => $sender->deliver($this->email(), new Account()), $expected, $failure);
    }

    public function testGraphPassesOnTheRetryAfter(): void
    {
        $sender = new GraphApiSender(
            new GraphApiClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '7']])), $this->tokens()),
            new NullLogger(),
        );

        try {
            $sender->deliver($this->email(), new Account());
            self::fail('a rate limit is not a send');
        } catch (SendDeferredException $deferred) {
            self::assertSame(7, $deferred->retryAfterSeconds());
        }
    }

    public function testGraphRefusesAMessageOverFourMegabytesWithoutAsking(): void
    {
        $sender = new GraphApiSender(new GraphApiClient(new MockHttpClient([]), $this->tokens()), new NullLogger());

        $this->expectException(SendRefusedException::class);

        $sender->deliver($this->email(str_repeat('x', 5 * 1024 * 1024)), new Account());
    }

    public function testGraphLooksInSentItemsByInternetMessageId(): void
    {
        $asked = null;

        $http = new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
            $asked = urldecode($url);

            return new MockResponse('{"value":[]}', ['http_code' => 200]);
        });

        $sender = new GraphApiSender(new GraphApiClient($http, $this->tokens()), new NullLogger());

        self::assertFalse($sender->isInSent(new Account(), "o'neil@example.test"));
        self::assertStringContainsString('/mailFolders/sentitems/messages', (string) $asked);
        self::assertStringContainsString("internetMessageId eq '<o''neil@example.test>'", (string) $asked, 'bracketed, quote doubled');
    }

    /**
     * @return array<string, array{\Symfony\Component\Mailer\Exception\TransportExceptionInterface, class-string<SendFailureException>, SendFailure}>
     */
    public static function smtpFailures(): array
    {
        return [
            '421 busy'                 => [new UnexpectedResponseException('Expected response code "250" but got code "421"', 421), SendDeferredException::class, SendFailure::Unavailable],
            '450 greylisted'           => [new UnexpectedResponseException('Expected response code "250" but got code "450"', 450), SendDeferredException::class, SendFailure::Unavailable],
            '550 no such user'         => [new UnexpectedResponseException('Expected response code "250" but got code "550"', 550), SendRefusedException::class, SendFailure::Refused],
            '535 bad password'         => [new TransportException('Failed to authenticate on SMTP server', 535), SendRefusedException::class, SendFailure::Refused],
            'never connected'          => [new TransportException('Connection could not be established with host "ssl://smtp.example.test:465"'), SendDeferredException::class, SendFailure::Unavailable],
            'connection lost mid-send' => [new TransportException('Connection to "smtp.example.test:587" has been closed unexpectedly.'), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
            'timed out mid-send'       => [new TransportException('Connection to "smtp.example.test:587" timed out.'), SendOutcomeUnknownException::class, SendFailure::MayHaveSent],
        ];
    }

    /**
     * @param class-string<SendFailureException> $expected
     */
    #[DataProvider('smtpFailures')]
    public function testSmtp(\Symfony\Component\Mailer\Exception\TransportExceptionInterface $thrown, string $expected, SendFailure $failure): void
    {
        $sender = new SmtpMailSender(new SmtpDsnFactory());

        $classified = $sender->classify(new Account(), $thrown);

        self::assertInstanceOf($expected, $classified);
        self::assertSame($failure, $classified->failure());
    }

    /**
     * @param class-string<SendFailureException>|null $expected
     */
    private function assertClassified(\Closure $deliver, ?string $expected, ?SendFailure $failure): void
    {
        try {
            $deliver();
        } catch (SendFailureException $thrown) {
            self::assertNotNull($expected, sprintf('sent, but %s was thrown: %s', $thrown::class, $thrown->getMessage()));
            self::assertInstanceOf($expected, $thrown);
            self::assertSame($failure, $thrown->failure());

            return;
        }

        self::assertNull($expected, sprintf('expected %s, but the send went through', (string) $expected));
    }

    private function tokens(): OAuthTokenManager
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('token');

        return $tokens;
    }

    private function email(string $body = 'Body'): Email
    {
        return new Email()
            ->from('me@example.test')
            ->to('you@example.test')
            ->subject('Hi')
            ->text($body);
    }
}
