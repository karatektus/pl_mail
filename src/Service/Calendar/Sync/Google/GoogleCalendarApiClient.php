<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Google;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Entity\Mail\Account;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Every HTTP request made against Google Calendar — by the sync driver and by
 * push registration alike — and the one place a Google failure becomes a
 * CalendarSyncException.
 *
 * Split from GoogleCalendarSyncDriver for the same reason GmailApiClient is
 * split from GmailApiSyncer: the driver is about what a pull, a push and a
 * delete *mean*, and reading it should not mean reading four hundred lines of
 * bearer tokens and status codes. The split also gives the classification one
 * home — the decision "is this 403 a quota or a missing scope?" is the most
 * consequential thing this driver does, and it is made in exactly one method
 * that every call goes through.
 *
 * Classification, and why each one:
 *
 *   **401, and 403 with a scope reason → permanent, phrased as an instruction.**
 *   Google's consent screen lets a user untick an individual scope, so a token
 *   that works perfectly for mail can be refused by every calendar endpoint
 *   forever. Retrying that buries the one line that says what to do under three
 *   identical ones, so the message names the fix — reconnect the account and
 *   allow calendar access — because it is rendered in the calendar settings
 *   list where a person will read it.
 *
 *   **403 with a quota reason, and 429 → throttled.** Google answers 403 rather
 *   than 429 for most quota rejections, exactly as Gmail does, so the status
 *   alone cannot tell a rate limit from a permissions failure and the reason in
 *   the body is the only thing that can. Retry-After is honoured when sent,
 *   which on a 403 it usually is not.
 *
 *   **410 → resync required.** The stored sync token has aged out. Thrown
 *   rather than returned as a flag because this classifier cannot see which
 *   caller it is serving, and pull(), push() and delete() can all meet it; the
 *   engine normalises the throw back into the flag in one place.
 *
 *   **412 → resync required.** The If-Match on an update did not match, so
 *   something else edited the event since it was last read. Not a failure of
 *   the write so much as proof that the local copy is stale, and re-reading is
 *   the only honest recovery.
 *
 *   **404 → permanent.** A calendar or an event that is not there is not there
 *   on the next attempt either. On a pull this is a calendar deleted at Google
 *   and the user is told; on a push it retires one event rather than failing
 *   the run, because CalendarPusher abandons a permanently-refused event and
 *   lets the other nineteen go out.
 *
 *   **Everything else — 5xx, an unrecognised reason, a transport failure —
 *   stays the unclassified base.** Guessing "permanent" is a decision never to
 *   try again, and a Google outage classified that way dead-letters every
 *   calendar in the install.
 *
 * Messages carry the operation, the status and Google's own wording, and never
 * the URL: they land in Calendar::$lastSyncError, which is shown on the
 * settings page, and a URL there would put a calendar id in front of the user
 * for no gain.
 *
 * Docs: https://developers.google.com/calendar/api/v3/reference
 */
final readonly class GoogleCalendarApiClient
{
    private const string BASE = 'https://www.googleapis.com/calendar/v3';

    /**
     * The 403 reasons that clear on their own.
     *
     * quotaExceeded is in here although the calendar API rarely sends it,
     * because GmailApiClient already treats it as transient and one answer to
     * "is this quota rejection worth retrying?" is worth more than two that can
     * drift apart.
     *
     * @var list<string>
     */
    private const array TRANSIENT_REASONS = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'quotaExceeded',
    ];

    /**
     * The reasons that mean the grant does not carry calendar access. Both
     * spellings are in use — `insufficientPermissions` in the classic
     * errors[].reason envelope, ACCESS_TOKEN_SCOPE_INSUFFICIENT in the newer
     * error.status one — and a token that came back without the calendar scope
     * is answered by whichever the endpoint feels like sending.
     *
     * @var list<string>
     */
    private const array SCOPE_REASONS = [
        'insufficientPermissions',
        'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
    ];

    /**
     * The 403s that are about this calendar rather than about the grant: a
     * calendar shared read-only, a domain policy. Listed rather than assumed,
     * so an unrecognised 403 stays unclassified.
     *
     * @var list<string>
     */
    private const array PERMANENT_REASONS = [
        'forbidden',
        'requiredAccessLevel',
        'accountSuspended',
        'dailyLimitExceeded',
        'PERMISSION_DENIED',
    ];

    /**
     * What a delete of something already gone answers.
     *
     * @var list<int>
     */
    private const array ALREADY_GONE = [404, 410];

    public function __construct(
        private HttpClientInterface $httpClient,
        private OAuthTokenManager   $tokenManager,
    ) {
    }

    /**
     * @param array<string,string|int> $query
     *
     * @return array<string,mixed>
     *
     * @throws CalendarSyncException
     */
    public function get(Account $account, string $path, array $query, string $operation): array
    {
        return $this->send($account, 'GET', $path, ['query' => $query], $operation);
    }

    /**
     * A create or an update, with the etag the caller believes it is editing.
     *
     * $ifMatch is the whole reason this takes an etag at all: without the
     * header a concurrent change at Google is overwritten silently, and the
     * user who made it never learns it is gone. With it, the same situation is
     * a 412, which this turns into a resync.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     *
     * @throws CalendarSyncException
     */
    public function write(
        Account $account,
        string  $method,
        string  $path,
        array   $payload,
        ?string $ifMatch,
        string  $operation,
    ): array {
        $options = ['json' => $payload];

        if (null !== $ifMatch && '' !== $ifMatch) {
            $options['headers'] = ['If-Match' => $ifMatch];
        }

        return $this->send($account, $method, $path, $options, $operation);
    }

    /**
     * Remove a resource, treating one that is already gone as done.
     *
     * The tolerance lives here rather than in the driver because this is the
     * only place the status is visible — by the time the driver has an answer,
     * a 410 has already become a resync. Which is the point: 410 means two
     * different things depending on what was asked, and the caller that asked
     * for a deletion is the one that gets to say so.
     *
     * @throws CalendarSyncException
     */
    public function delete(Account $account, string $path, string $operation): void
    {
        $response = $this->request($account, 'DELETE', $path, [], $operation);

        if (true === in_array($this->statusOf($response, $operation), self::ALREADY_GONE, true)) {
            return;
        }

        $this->assertSuccess($response, $operation);
    }

    /**
     * Open a notification channel on a calendar's events, and answer with the
     * channel Google actually created.
     *
     * Here rather than in GoogleCalendarPushManager so that this class stays
     * what its docblock claims — every HTTP request made against Google
     * Calendar, and one home for deciding what a failure means. The push
     * manager cares about exactly one distinction (did it register, yes or no)
     * and would otherwise re-derive it from a status code, which is how a
     * missing calendar scope and a Google outage end up treated the same way.
     *
     * The interesting part of the answer is `resourceId` and `expiration`:
     * neither is knowable in advance, and both are stored — see
     * Calendar::$pushResourceId and Calendar::$pushExpiresAt.
     *
     * @param array<string,mixed> $channel the channel resource to create
     *
     * @return array<string,mixed>
     *
     * @throws CalendarSyncException
     */
    public function watchChannel(Account $account, string $calendarRemoteId, array $channel): array
    {
        return $this->send(
            $account,
            'POST',
            sprintf('/calendars/%s/events/watch', rawurlencode($calendarRemoteId)),
            ['json' => $channel],
            'events.watch',
        );
    }

    /**
     * Close a notification channel.
     *
     * Not routed through send(): Google answers a successful stop with 204 and
     * an empty body, and toArray() on that is a decoding error rather than an
     * empty array — the same trap GraphCalendarSyncDriver documents on its own
     * DELETEs.
     *
     * A channel that is already gone is a success, for the reason delete()
     * gives: the caller's job is to make it not exist. That covers both the
     * ordinary re-registration race and a channel that simply lapsed.
     *
     * @throws CalendarSyncException
     */
    public function stopChannel(Account $account, string $channelId, string $resourceId): void
    {
        $response = $this->request(
            $account,
            'POST',
            '/channels/stop',
            ['json' => ['id' => $channelId, 'resourceId' => $resourceId]],
            'channels.stop',
        );

        if (true === in_array($this->statusOf($response, 'channels.stop'), self::ALREADY_GONE, true)) {
            return;
        }

        $this->assertSuccess($response, 'channels.stop');
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     *
     * @throws CalendarSyncException
     */
    private function send(Account $account, string $method, string $path, array $options, string $operation): array
    {
        $response = $this->request($account, $method, $path, $options, $operation);

        $this->assertSuccess($response, $operation);

        // throw=false so this class stays the only thing that decides what a
        // response means: left at true, toArray() raises Symfony's own HTTP
        // exception, whose message is the status and the URL and never the body
        // — which is where Google says whether a 403 was a quota or a scope.
        try {
            $body = $response->toArray(false);
        } catch (HttpException | \JsonException $e) {
            throw new CalendarSyncException(
                sprintf('Google Calendar %s returned something that is not JSON.', $operation),
                0,
                $e,
            );
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $options
     *
     * @throws CalendarSyncException
     */
    private function request(
        Account $account,
        string  $method,
        string  $path,
        array   $options,
        string  $operation,
    ): ResponseInterface {
        $options['auth_bearer'] = $this->token($account);

        try {
            return $this->httpClient->request($method, self::BASE . $path, $options);
        } catch (HttpException $e) {
            // A request that never left, or a connection that died mid-flight.
            // Unclassified on purpose: a DNS blip and a dead network answer the
            // same way here, and neither is a reason to stop trying.
            throw new CalendarSyncException(
                sprintf('Google Calendar %s could not be reached.', $operation),
                0,
                $e,
            );
        }
    }

    /**
     * A bearer token, or a failure the sync engine understands.
     *
     * OAuthTokenManager raises whatever the OAuth library raised — an
     * IdentityProviderException for a revoked grant, a Guzzle exception for a
     * network fault — and letting either out would break the contract that
     * every failure crossing the driver boundary is a CalendarSyncException.
     * Classified from the token manager's own answer. A refused refresh and a
     * timeout on the token endpoint used to arrive here as the same kind of
     * object, so everything was left retryable and a revoked grant produced
     * five identical CRITICAL lines per calendar. OAuthTokenManager now
     * distinguishes them at the only place that can — the provider's OAuth
     * error code — and a dead grant arrives as OAuthGrantRevokedException.
     *
     * Writing off an account because Google's token endpoint was briefly slow
     * is still worse than one more attempt, and that case is still retried.
     *
     * @throws CalendarSyncException
     */
    private function token(Account $account): string
    {
        try {
            return $this->tokenManager->getValidAccessToken($account);
        } catch (OAuthGrantRevokedException $e) {
            throw new CalendarSyncPermanentException(
                'Google would not renew the sign-in for this account. Reconnect it in the account settings.',
                0,
                $e,
            );
        } catch (\Throwable $e) {
            throw new CalendarSyncException(
                'Google would not renew the sign-in for this account.',
                0,
                $e,
            );
        }
    }

    /**
     * Turn a non-2xx into the exception whose class says what to do about it.
     *
     * @throws CalendarSyncException
     */
    private function assertSuccess(ResponseInterface $response, string $operation): void
    {
        $status = $this->statusOf($response, $operation);

        if (200 <= $status && 300 > $status) {
            return;
        }

        $failure = $this->describeFailure($response);
        $reason  = $failure['reason'];
        $detail  = sprintf(
            '(%s, %d%s%s)',
            $operation,
            $status,
            '' !== $reason ? ' ' . $reason : '',
            '' !== $failure['message'] ? ': ' . $failure['message'] : '',
        );

        if (410 === $status) {
            throw new CalendarResyncRequiredException(sprintf(
                'The Google Calendar sync position has expired, so the calendar is being read again from scratch. %s',
                $detail,
            ));
        }

        if (412 === $status) {
            throw new CalendarResyncRequiredException(sprintf(
                'This event changed at Google since it was last read, so the update was not applied. %s',
                $detail,
            ), 412);
        }

        if (429 === $status || (403 === $status && true === in_array($reason, self::TRANSIENT_REASONS, true))) {
            throw new CalendarSyncThrottledException(
                sprintf('Google is limiting how often this account may ask about its calendars. %s', $detail),
                $status,
                $this->retryAfterSeconds($response),
            );
        }

        // 401 with no usable reason is the shape a token stripped of the
        // calendar scope arrives in, so it takes the same sentence as an
        // explicit scope refusal — both are fixed by reconnecting, and a user
        // cannot act on "401".
        if (401 === $status || (403 === $status && true === in_array($reason, self::SCOPE_REASONS, true))) {
            throw new CalendarSyncPermanentException(sprintf(
                'Google is not allowing plMail to see this account\'s calendars. Reconnect the account and allow calendar access when Google asks. %s',
                $detail,
            ), $status);
        }

        if (404 === $status) {
            throw new CalendarSyncPermanentException(
                sprintf('This calendar or event no longer exists at Google. %s', $detail),
                $status,
            );
        }

        if (403 === $status && true === in_array($reason, self::PERMANENT_REASONS, true)) {
            throw new CalendarSyncPermanentException(
                sprintf('Google refused this calendar request and will refuse it again. %s', $detail),
                $status,
            );
        }

        throw new CalendarSyncException(
            sprintf('Google Calendar refused a request. %s', $detail),
            $status,
        );
    }

    /**
     * Pull Google's reason and human message out of an error body.
     *
     * Written to survive a body that is empty, truncated, or an HTML page from
     * a proxy in front of the API — none of which are hypothetical, and all of
     * which would otherwise turn a clean 403 into a JsonException that says
     * nothing about what went wrong.
     *
     * @return array{reason: string, message: string}
     */
    private function describeFailure(ResponseInterface $response): array
    {
        try {
            $raw = $response->getContent(false);
        } catch (HttpException) {
            return ['reason' => '', 'message' => ''];
        }

        $decoded = json_decode($raw, true);
        $error   = true === is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (false === is_array($error)) {
            // Not a Google error envelope. A short excerpt beats discarding it:
            // a proxy's page at least names the proxy.
            return ['reason' => '', 'message' => trim(substr($raw, 0, 200))];
        }

        $errors = $error['errors'] ?? null;
        $first  = true === is_array($errors) ? ($errors[0] ?? null) : null;
        $reason = true === is_array($first) ? ($first['reason'] ?? null) : null;

        // errors[].reason is the classic form; error.status
        // ("PERMISSION_DENIED", "RESOURCE_EXHAUSTED") is what newer Google
        // endpoints send instead.
        $reason ??= $error['status'] ?? null;
        $detail   = $error['message'] ?? null;

        return [
            'reason'  => true === is_scalar($reason) ? (string) $reason : '',
            'message' => true === is_scalar($detail) ? (string) $detail : '',
        ];
    }

    /**
     * Retry-After in seconds, when Google bothered to send one.
     *
     * The header also has an HTTP-date form; that is ignored rather than
     * parsed, because Google sends the delta form and a misparsed date would
     * produce a nonsense delay that the throttled exception's own fallback
     * covers better.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        try {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (HttpException) {
            return null;
        }

        if (null === $header || false === ctype_digit(trim($header))) {
            return null;
        }

        return (int) trim($header);
    }

    /**
     * Reading the status is itself a network operation — it waits for the
     * headers — so it can fail, and a failure here is a failure of the request
     * rather than of the calendar.
     *
     * @throws CalendarSyncException
     */
    private function statusOf(ResponseInterface $response, string $operation): int
    {
        try {
            return $response->getStatusCode();
        } catch (HttpException $e) {
            throw new CalendarSyncException(
                sprintf('Google Calendar %s never answered.', $operation),
                0,
                $e,
            );
        }
    }
}
