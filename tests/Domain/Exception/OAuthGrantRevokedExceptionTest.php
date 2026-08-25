<?php

declare(strict_types=1);

namespace App\Tests\Domain\Exception;

use App\Domain\Exception\OAuthGrantRevokedException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Which failed refresh is the end of the grant, and which is a bad moment.
 *
 * The distinction is the whole value of the class: a permanent classification
 * is a decision never to try again, so calling a timeout permanent loses mail
 * that would have synced on the next attempt — and calling a revoked grant
 * temporary is what produced five identical CRITICAL log lines per calendar
 * and taught everyone to scroll past them.
 */
final class OAuthGrantRevokedExceptionTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function responses(): iterable
    {
        yield 'a revoked or expired refresh token' => ['{"error":"invalid_grant"}', true];
        yield 'Google, with its human sentence beside the code' => [
            '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
            true,
        ];
        yield 'the client registration itself is finished' => ['{"error":"invalid_client"}', true];
        yield 'the client may not use this grant type' => ['{"error":"unauthorized_client"}', true];

        // The cases that must stay retryable. Each is a 400 or a 5xx from the
        // same endpoint, so nothing but the error code tells them apart.
        yield 'a malformed request, which is our bug and may be fixed' => ['{"error":"invalid_request"}', false];
        yield 'the provider is briefly unwell' => ['{"error":"temporarily_unavailable"}', false];
        yield 'a rate limit' => ['{"error":"slow_down"}', false];
        yield 'an empty body' => ['', false];
    }

    #[DataProvider('responses')]
    public function testTheErrorCodeDecidesWhetherTheGrantIsFinished(string $body, bool $terminal): void
    {
        $decoded = '' === $body ? [] : (array) json_decode($body, true);

        $error = new IdentityProviderException(
            (string) ($decoded['error'] ?? 'unknown'),
            400,
            $body,
        );

        self::assertSame($terminal, OAuthGrantRevokedException::isTerminal($error));
    }

    /**
     * A network fault is not an OAuth answer at all — there is no error code,
     * because there was no response. This is the case the Graph driver used to
     * write an account off for.
     */
    public function testATransportFailureIsNeverTerminal(): void
    {
        $connect = new ConnectException('Connection timed out', new Request('POST', 'https://oauth2.example/token'));

        self::assertFalse(OAuthGrantRevokedException::isTerminal($connect));
        self::assertFalse(OAuthGrantRevokedException::isTerminal(new RuntimeException('invalid_grant')));
    }

    /**
     * The one property Messenger actually reads. Without it the exception is a
     * documentation comment and the retries carry on exactly as before.
     */
    public function testItTellsMessengerNotToRetry(): void
    {
        self::assertInstanceOf(
            UnrecoverableExceptionInterface::class,
            new OAuthGrantRevokedException('gone'),
        );
    }
}
