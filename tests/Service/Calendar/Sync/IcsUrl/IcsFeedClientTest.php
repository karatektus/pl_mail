<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\IcsUrl;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Service\Calendar\Sync\IcsUrl\IcsFeedClient;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fetching a file from an address a user chose.
 *
 * The claim: **an address a stranger controls cannot cost more than one refused
 * sync.** Everything here is about a bound, because every input is somebody
 * else's — the address, the size of what is at it, where it redirects to, and
 * how often.
 *
 * The size cap is the one worth stating plainly. `getContent()` on a response
 * with no Content-Length buffers whatever the far end sends until the process
 * dies, and this runs in a Messenger worker that then *retries*. Streaming with
 * a ceiling turns a hostile or broken feed into one error line.
 *
 * Classification is the other half, and one arm of it deliberately differs from
 * CalDavClient: a 403 is not permanent here. There is no credential to have been
 * rejected, and a CDN in front of a feed answers 403 for a rate limit, a geo
 * rule and a bot filter — all of which pass. Writing the subscription off for
 * one would need a person to add it again by hand.
 */
final class IcsFeedClientTest extends TestCase
{
    private const string URL = 'https://feeds.example.com/holidays.ics';

    public function testAFeedLargerThanTheCapIsRefusedRatherThanBuffered(): void
    {
        // Nine mebibytes against an eight-mebibyte ceiling, delivered as chunks
        // with no Content-Length — which is exactly the shape that defeats a
        // check made on the header instead of on what arrives.
        $client = $this->client(new MockResponse(
            $this->chunks(9),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/calendar']],
        ));

        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessageMatches('/larger than/');

        $client->fetch(self::URL);
    }

    public function testAFeedUnderTheCapIsReadWhole(): void
    {
        $body   = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        $client = $this->client(new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['etag' => '"v9"', 'last-modified' => 'Wed, 05 Aug 2026 10:00:00 GMT'],
        ]));

        $response = $client->fetch(self::URL);

        self::assertFalse($response->isUnchanged);
        self::assertSame($body, $response->body);
        self::assertSame('"v9"', $response->etag, 'the validator is stored exactly as the server wrote it');
        self::assertSame('Wed, 05 Aug 2026 10:00:00 GMT', $response->lastModified);
    }

    public function testA304IsAnAnswerRatherThanAFailure(): void
    {
        $response = $this->client(new MockResponse('', ['http_code' => 304]))->fetch(self::URL, '"v1"');

        self::assertTrue($response->isUnchanged);
        self::assertSame('', $response->body);
    }

    /**
     * A redirect loop is a bound on work, not on risk — every hop is revalidated
     * — but without it a server that points at itself is an infinite loop inside
     * a Messenger handler.
     */
    public function testARedirectLoopStopsRatherThanRunningForever(): void
    {
        $hop = static fn (): MockResponse => new MockResponse('', [
            'http_code'        => 302,
            'response_headers' => ['location' => self::URL],
        ]);

        $client = $this->client($hop(), $hop(), $hop(), $hop(), $hop(), $hop());

        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessageMatches('/keeps redirecting/');

        $client->fetch(self::URL);
    }

    /** A relative Location is legal and is what the http-to-https hop usually sends. */
    public function testARelativeRedirectIsResolvedAgainstTheAddressThatWasAsked(): void
    {
        $target = new MockResponse('BEGIN:VCALENDAR', ['http_code' => 200]);

        $client = $this->client(
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/feeds/moved.ics']]),
            $target,
        );

        $client->fetch(self::URL);

        self::assertSame('https://feeds.example.com/feeds/moved.ics', $target->getRequestUrl());
    }

    public function testAMissingFeedIsPermanentSoNothingKeepsAskingForIt(): void
    {
        $this->expectException(CalendarSyncPermanentException::class);

        $this->client(new MockResponse('', ['http_code' => 404]))->fetch(self::URL);
    }

    public function testARateLimitIsThrottledRatherThanPermanent(): void
    {
        $this->expectException(CalendarSyncThrottledException::class);

        $this->client(new MockResponse('', [
            'http_code'        => 429,
            'response_headers' => ['retry-after' => '120'],
        ]))->fetch(self::URL);
    }

    /**
     * Unlike CalDAV, where a 403 means a credential was refused. Here there is
     * no credential, so a 403 is a CDN having an opinion — and an opinion that
     * passes.
     */
    public function testAForbiddenFeedIsNotWrittenOffPermanently(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 403]));

        try {
            $client->fetch(self::URL);

            self::fail('a 403 must still be a failure');
        } catch (CalendarSyncException $e) {
            self::assertNotInstanceOf(CalendarSyncPermanentException::class, $e);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function client(MockResponse ...$responses): IcsFeedClient
    {
        return new IcsFeedClient(
            new MockHttpClient(array_values($responses)),
            // The real validator, because the refusals this class translates are
            // its refusals and a doubled one would assert them into existence.
            new IcsUrlNormaliser(new IntegrationUrlValidator()),
        );
    }

    /**
     * A body delivered in pieces, so the ceiling is tested against what arrives
     * rather than against a header the server need not send.
     *
     * @return iterable<string>
     */
    private function chunks(int $mebibytes): iterable
    {
        for ($written = 0; $written < $mebibytes; ++$written) {
            yield str_repeat('x', 1048576);
        }
    }
}
