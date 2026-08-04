<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Which of the three things a sync worker can usefully do — stop, wait, start
 * over — a Google refusal means.
 *
 * The status alone cannot answer it, and that is the whole subject here. Google
 * answers 403 both for a quota it will forgive in a minute and for a scope it
 * will never grant, so the reason in the body is the only thing that can tell
 * them apart. Getting it wrong is expensive in both directions: a rate limit
 * classified permanent dead-letters a calendar that was fine, and a missing
 * scope classified transient retries five times every fifteen minutes forever
 * while the one log line that says "reconnect the account" is buried under
 * identical ones.
 *
 * The missing scope is not a theoretical path. Google's consent screen lets a
 * user untick an individual permission, so a mail account that works perfectly
 * can hold a token every calendar endpoint refuses — and the only thing that
 * makes that recoverable is a message phrased as an instruction, because it is
 * rendered in the calendar settings list and a person has to act on it.
 *
 * The 410 case is the one with a third answer. An expired sync token is not a
 * failure at all: tokens age out after about a week of silence, and the
 * recovery is one cheap full read, which is what CalendarResyncRequiredException
 * asks the engine for.
 */
final class GoogleCalendarFailureTest extends TestCase
{
    public function testAnExpiredSyncTokenAsksForAFullReadRatherThanForARetry(): void
    {
        // 410 is how Google says the position no longer exists. Retrying the
        // same request with the same dead token answers 410 again, forever.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(410, 'fullSyncRequired', 'Sync token is no longer valid'));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-from-last-week');
            self::fail('an expired sync token must not look like a successful poll');
        } catch (CalendarResyncRequiredException $e) {
            // The class is the whole assertion: it carries neither Messenger
            // marker, so the engine catches it by type, clears the token and
            // re-runs the pull. Marked unrecoverable it would dead-letter a
            // calendar one cheap full read away from correct; marked
            // recoverable it would retry the identical request with the
            // identical dead token.
            self::assertSame(410, $e->getStatus());
        }
    }

    public function testAnExpiredTokenIsStillNoticedOnTheSecondPage(): void
    {
        // A window can be several pages, and the token can die between them.
        // Discovered that deep there is no changeset to answer with, which is
        // why the driver throws rather than returning the flag.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['items' => [], 'nextPageToken' => 'page-2']),
            GoogleDriverFixture::error(410, 'fullSyncRequired'),
        );

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-1');
    }

    public function testAQuotaRejectionIsAWaitRatherThanAPermissionsFailure(): void
    {
        // Google answers 403 for quota, exactly as Gmail does. Read as a
        // permissions failure it dead-letters a calendar that would have been
        // fine a minute later.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(403, 'userRateLimitExceeded', 'Rate Limit Exceeded'));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a rate limit must not be swallowed');
        } catch (CalendarSyncThrottledException $e) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $e, 'Messenger must retry this');
            self::assertStringContainsString('userRateLimitExceeded', $e->getMessage());
            self::assertSame(60000, $e->getRetryDelay(), 'a calendar quota is per minute, not per second');
            self::assertFalse($e->forceRetry(), 'retrying past max_retries against a rate limit is worse than failing');
        }
    }

    public function testA429IsAWaitWhateverTheBodySays(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([], 429));

        $this->expectException(CalendarSyncThrottledException::class);

        $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));
    }

    public function testRetryAfterIsHonouredWhenGoogleSendsOne(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(429, 'rateLimitExceeded', 'Too many', ['retry-after' => '120']));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a rate limit must not be swallowed');
        } catch (CalendarSyncThrottledException $e) {
            self::assertSame(120, $e->getRetryAfterSeconds());
            self::assertSame(120000, $e->getRetryDelay());
        }
    }

    /**
     * @param array<string,mixed> $body a refusal in one of the two shapes Google sends
     */
    #[DataProvider('scopeRefusals')]
    public function testAGrantWithoutCalendarAccessSaysWhatToDoAboutIt(int $status, array $body): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json($body, $status));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a refused scope must not look like an empty calendar');
        } catch (CalendarSyncPermanentException $e) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $e, 'retrying buries the one line that helps');

            // Rendered in the calendar settings list. "403" is not something a
            // person can act on; ticking the box Google asked about is.
            self::assertStringContainsString('Reconnect the account', $e->getMessage());
            self::assertStringContainsString('allow calendar access', $e->getMessage());
        }
    }

    /**
     * @return iterable<string,array{int,array<string,mixed>}>
     */
    public static function scopeRefusals(): iterable
    {
        yield 'the classic errors[].reason envelope' => [403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'insufficientPermissions', 'message' => 'Insufficient Permission']],
                'message' => 'Insufficient Permission',
            ],
        ]];

        // What the newer endpoints send instead: no errors[] at all, and the
        // reason under error.status.
        yield 'the newer error.status envelope' => [403, [
            'error' => [
                'code'    => 403,
                'message' => 'Request had insufficient authentication scopes.',
                'status'  => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
            ],
        ]];

        // A token stripped of the calendar scope can also simply be rejected,
        // with nothing in the body worth reading. It takes the same sentence
        // because it has the same fix.
        yield 'a bare 401' => [401, [
            'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
        ]];
    }

    public function testAConcurrentEditCostsAReReadRatherThanAnOverwrite(): void
    {
        // The 412 is the If-Match doing its job: somebody changed the event at
        // Google since it was last read here. Treated as a plain failure it
        // would be retried with the same stale etag and refused again.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(412, 'conditionNotMet', 'Precondition Failed'));

        try {
            $fixture->driver->push(
                GoogleDriverFixture::calendar(),
                GoogleDriverFixture::event(remoteId: 'ev-1', etag: '"8"'),
            );
            self::fail('a 412 must not look like a successful write');
        } catch (CalendarResyncRequiredException $e) {
            self::assertSame(412, $e->getStatus());
            self::assertStringContainsString('changed at Google', $e->getMessage());
        }
    }

    #[DataProvider('alreadyGone')]
    public function testDeletingSomethingAlreadyGoneIsSuccess(int $status): void
    {
        // Every provider answers this to the second delete, the engine retries
        // jobs, and treating it as a failure leaves the local row stuck in
        // PendingDelete — re-attempting the same delete on every sweep, forever.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error($status, 'notFound', 'Not Found'));

        $fixture->driver->delete(
            GoogleDriverFixture::calendar(),
            GoogleDriverFixture::event(remoteId: 'ev-1'),
        );

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string,array{int}>
     */
    public static function alreadyGone(): iterable
    {
        yield '404 never existed here' => [404];
        // 410 means something else everywhere else in this driver, and that is
        // the point: the same status is a dead sync token on a pull and a
        // finished job on a delete.
        yield '410 has been deleted'   => [410];
    }

    public function testACalendarThatIsGoneStopsRatherThanRetrying(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(404, 'notFound', 'Not Found'));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a missing calendar must not look like an empty one');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('no longer exists at Google', $e->getMessage());
        }
    }

    public function testAnUnrecognisedRefusalIsNotWrittenOff(): void
    {
        // A permanent classification is a decision never to try again. Guessing
        // it for a reason nobody has seen before hides a Google outage behind a
        // dead-lettered job on every calendar in the install.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(403, 'somethingNobodyHasSeen'));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a 403 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e);
            self::assertNotInstanceOf(RecoverableExceptionInterface::class, $e);
            self::assertSame(403, $e->getStatus());
        }
    }

    public function testAServerErrorIsLeftToTheTransportsOwnStrategy(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([], 503));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a 503 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e);
            self::assertSame(503, $e->getStatus());
        }
    }

    public function testAnErrorPageFromAProxyIsReportedInsteadOfCrashing(): void
    {
        // Not JSON at all. json_decode has to be allowed to fail without
        // turning the refusal into a JsonException that says nothing about
        // what went wrong — and the excerpt at least names the proxy.
        $fixture = new GoogleDriverFixture(new MockResponse(
            '<html><body>502 Bad Gateway (edge-proxy-7)</body></html>',
            ['http_code' => 502],
        ));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a 502 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertStringContainsString('edge-proxy-7', $e->getMessage());
        }
    }

    public function testNoFailureEverPutsARequestUrlInFrontOfTheUser(): void
    {
        // These messages land in Calendar::$lastSyncError, which the settings
        // page renders. A URL there is a calendar id and a query string shown
        // to somebody who asked why their calendar is empty.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::error(403, 'insufficientPermissions'));

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-1');
            self::fail('a 403 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertStringNotContainsString('googleapis.com', $e->getMessage());
            self::assertStringNotContainsString('token-1', $e->getMessage());
        }
    }

    public function testAPageTokenThatRepeatsItselfStopsTheListing(): void
    {
        // Nothing in the API is supposed to do this, and a loop that follows it
        // asks for the same page forever — a sweep that never returns, holding
        // a worker and a transaction, is far harder to notice than a calendar
        // that failed.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['items' => [], 'nextPageToken' => 'page-2']),
            GoogleDriverFixture::json(['items' => [], 'nextPageToken' => 'page-2']),
        );

        try {
            $fixture->driver->pull(GoogleDriverFixture::calendar(), null);
            self::fail('a listing that never ends must not be followed');
        } catch (CalendarSyncException $e) {
            // The message matters, not just the class: without the guard this
            // still ends in an exception here — the scripted transport runs out
            // of answers — and in production it would not end at all.
            self::assertStringContainsString('keeps answering with the same page', $e->getMessage());
        }
    }

    public function testACalendarWithNoGoogleBehindItIsRefusedRatherThanRequested(): void
    {
        $calendar           = GoogleDriverFixture::calendar();
        $calendar->remoteId = null;

        $fixture = new GoogleDriverFixture();

        $this->expectException(CalendarSyncPermanentException::class);

        $fixture->driver->pull($calendar, null);
    }
}
