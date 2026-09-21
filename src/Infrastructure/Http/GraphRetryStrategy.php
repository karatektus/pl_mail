<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

/**
 * Which Microsoft Graph failures are worth one more immediate attempt.
 *
 * The policy behind the `graph.client` scoped client in framework.yaml, which
 * is what GraphApiClient, GraphCalendarSyncDriver, GraphCalendarPushManager and
 * GraphDiagnoseCommand ask for by name. Nothing that talks to any other host
 * gets it.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Graph answers 502 with an empty message and `"code": "UnknownError"` often
 * enough that it is weather rather than news, and nothing in the read path had
 * an answer for it. GraphApiClient::assertSuccess() sorts 410 into a resync and
 * 429/503 into a throttle; everything else becomes a plain GraphApiException,
 * which GraphApiSyncer catches per folder, logs at ERROR with the whole trace,
 * and skips. The skip is safe — `everyFolderAnswered` goes false so nothing is
 * erased on partial coverage, and the delta link is not advanced, so the next
 * run resumes from the same token — but the folder has still lost a cycle, and
 * MaintenanceSchedule runs app:mail:sync every fifteen minutes.
 *
 * So a blink that one immediate retry would have cleared cost fifteen minutes
 * of that folder's mail and an ERROR an administrator has to read and dismiss.
 * A retry here is invisible when it works: the response never reaches the
 * syncer and nothing is logged at all.
 *
 * ── Why not Symfony's defaults ───────────────────────────────────────────────
 * GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES retries 502, 503 and 429 for
 * EVERY method, and restricts only 500/504/507/510 to idempotent ones. Both
 * halves of that are wrong here.
 *
 * 502 FOR EVERY METHOD is the dangerous half. A 502 means the gateway gave up
 * on the answer, not that Exchange never acted: `/me/sendMail`, `/$batch`
 * carrying writes, `createMasterCategory` and the `/move` calls are all POSTs
 * that may well have landed. Retrying those blind is how one send becomes two.
 * GET only, therefore — the delta reads, the page walks and the message fetches
 * in the log line above, all of which can be repeated as often as necessary.
 *
 * 429 AND 503 are the other half, and they are excluded because throttling is
 * already handled deliberately and at a better altitude. assertSuccess() turns
 * them into GraphThrottledException, which carries Graph's own Retry-After and
 * is Recoverable so Messenger honours it; the Graph handlers re-slice
 * per-sub-request throttles and requeue by hand. Graph mail throttling is
 * per-mailbox and allows only about four concurrent requests, so a retry here
 * would spend a scarce one of them arguing with a limit that has just said how
 * long to wait — and it would do so inside the sync, where the wait has to stay
 * in seconds, rather than on the Messenger ladder, where a throttle measured in
 * minutes can actually be waited out.
 *
 * (RetryableHttpClient does read Retry-After off the response, ahead of
 * whatever this strategy's getDelay() returns. That is not what makes the
 * exclusion right — it is the ownership above.)
 *
 * Status 0 is the client's own "no response": a reset connection or a DNS blip
 * on the way out. It sits with the 5xx for the same reason it does in
 * ApplyGraphChangesHandler::rethrowIfTransient() — it says nothing about
 * whether the request was valid — and under the same GET-only restriction,
 * because "no response" is exactly the case where a POST may still have landed.
 */
final class GraphRetryStrategy extends GenericRetryStrategy
{
    /**
     * GET, spelled out rather than IDEMPOTENT_METHODS, and narrower than it.
     *
     * The four callers between them issue GET, POST, PATCH and DELETE. Only the
     * GET is repeated here, and DELETE is the one worth saying out loud, since
     * IDEMPOTENT_METHODS would have included it: deleting a Graph subscription
     * that the first attempt had in fact deleted answers 404, so a retry can
     * turn a success into an error the caller then has to be taught to ignore.
     *
     * The general rule underneath that: every write-shaped method already has a
     * caller that decides what a 5xx means for it — rethrowIfTransient() on the
     * mail side, the CalendarSync exceptions on the other — and those callers
     * know things this layer cannot, such as whether the change is still
     * sitting locally waiting to be re-applied. Repeating a write here would be
     * making that judgement twice, in the place with less information.
     */
    private const array RETRYABLE = [
        0   => ['GET'],
        500 => ['GET'],
        502 => ['GET'],
        504 => ['GET'],
    ];

    /**
     * A second and a third, and then it is not a blink.
     *
     * The ladder has to stay short: it runs INSIDE the sync, one folder at a
     * time, so every second here is a second the worker is not reading the next
     * folder. Two retries at 1s and 3s adds at most four seconds to a folder
     * that is failing anyway, against fifteen minutes of missed mail when it
     * works. Anything longer belongs to the Messenger ladder in
     * messenger.yaml, which is measured in minutes and already covers the case
     * where Graph is properly down.
     */
    public function __construct()
    {
        parent::__construct(
            statusCodes: self::RETRYABLE,
            delayMs: 1_000,
            multiplier: 3.0,
            maxDelayMs: 5_000,
            jitter: 0.2,
        );
    }
}
