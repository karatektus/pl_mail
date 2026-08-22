<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around the Gmail REST API endpoints needed for sync.
 *
 * Intentionally slim: every method returns the decoded JSON array directly
 * so callers can deal with the data without knowing about HTTP.
 *
 * Docs: https://developers.google.com/gmail/api/reference/rest
 */
final class GmailApiClient
{
    private const BASE  = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const BATCH = 'https://www.googleapis.com/batch/gmail/v1';

    /**
     * Gmail reports quota rejections as 403, not 429 — the status alone cannot
     * tell these apart from a permissions failure, so the reason in the body is
     * the only thing that can.
     */
    private const array TRANSIENT_REASONS = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'quotaExceeded',
    ];

    /**
     * The 403s that answer identically forever. Anything not listed here or in
     * TRANSIENT_REASONS stays unclassified on purpose: guessing wrong in the
     * permanent direction hides a real outage behind a dead-lettered job.
     */
    private const array PERMANENT_REASONS = [
        'insufficientPermissions',
        'dailyLimitExceeded',
        'accountSuspended',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenManager  $tokenManager,
    ) {}

    // ── messages ─────────────────────────────────────────────────────────────

    /**
     * List message IDs (and optional thread IDs) matching a query.
     *
     * Returns the raw `messages` array from the API response.
     * Handles pagination automatically and returns all pages concatenated.
     *
     * Every page is paid for: there is no early stop, because nothing asks for
     * a slice of a mailbox any more. Gmail lists newest-first, so a caller that
     * dies partway through has still seen the newest mail first.
     *
     * @param array<string,string|int> $params  e.g. ['maxResults' => 500]
     * @return list<array{id: string, threadId: string}>
     */
    public function listMessages(Account $account, array $params = []): array
    {
        $token    = $this->tokenManager->getValidAccessToken($account);
        $messages = [];
        $page     = null;

        do {
            $query = $params;

            if (null !== $page) {
                $query['pageToken'] = $page;
            }

            $response = $this->httpClient->request('GET', self::BASE . '/messages', [
                'auth_bearer' => $token,
                'query'       => $query,
            ]);

            $body = $this->decode($response, 'messages.list');
            $page = $body['nextPageToken'] ?? null;

            foreach ($body['messages'] ?? [] as $m) {
                $messages[] = $m;
            }
        } while (null !== $page);

        return $messages;
    }

    /**
     * Fetch multiple messages (format=full) using the Gmail Batch API.
     *
     * Packs up to 100 individual messages.get sub-requests into a single
     * multipart/mixed HTTP POST. This avoids hammering the per-user per-second
     * quota with individual concurrent requests and dramatically reduces
     * round-trips for large initial syncs.
     *
     * Every requested id is accounted for in the result:
     *   - payloads:  id → decoded message resource (200 parts)
     *   - retryable: ids whose part failed transiently (429/403/5xx) or was
     *                missing from the response — the caller must re-queue them
     *   - gone:      ids that are permanently unfetchable (404/410/other 4xx)
     *
     * A whole-batch failure THROWS instead of returning empty — returning
     * empty would let the Messenger message ack and silently drop every id
     * in the batch.
     *
     * @param list<string> $messageIds  Maximum 100 per call (enforced by caller via BATCH_SIZE)
     * @return array{payloads: array<string,array<string,mixed>>, retryable: list<string>, gone: list<string>}
     */
    public function getMessages(Account $account, array $messageIds): array
    {
        if (count($messageIds) === 0) {
            return [
                'payloads'  => [],
                'retryable' => [],
                'gone'      => [],
            ];
        }

        $token    = $this->tokenManager->getValidAccessToken($account);
        $boundary = 'plmail_batch_' . bin2hex(random_bytes(8));
        $body     = $this->buildBatchBody($messageIds, $boundary);

        $response = $this->httpClient->request('POST', self::BATCH, [
            'auth_bearer' => $token,
            'headers'     => [
                'Content-Type' => 'multipart/mixed; boundary="' . $boundary . '"',
            ],
            'body' => $body,
        ]);

        try {
            $rawBody = $response->getContent();
        } catch (HttpException $e) {
            throw new \RuntimeException(
                'Gmail batch request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $parsed    = $this->parseBatchResponse($rawBody);
        $payloads  = $parsed['payloads'];
        $retryable = [];
        $gone      = [];

        foreach ($messageIds as $id) {
            if (true === isset($payloads[$id])) {
                continue;
            }

            $status = $parsed['statuses'][$id] ?? null;

            if (null === $status) {
                // Part missing or unparseable — assume transient.
                $retryable[] = $id;
                continue;
            }

            if (true === in_array($status, [429, 403, 500, 502, 503, 504], true)) {
                $retryable[] = $id;
                continue;
            }

            $gone[] = $id;
        }

        return [
            'payloads'  => $payloads,
            'retryable' => $retryable,
            'gone'      => $gone,
        ];
    }


    /**
     * Fetch a single message in full format.
     *
     * @return array<string,mixed>
     */
    public function getMessage(Account $account, string $messageId): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request(
            'GET',
            self::BASE . '/messages/' . urlencode($messageId),
            [
                'auth_bearer' => $token,
                'query'       => ['format' => 'full'],
            ],
        );

        return $this->decode($response, 'messages.get');
    }

    /**
     * Fetch the original RFC822 bytes of a message.
     *
     * A separate call from getMessage(): format=raw returns the whole message
     * base64url-encoded and omits the parsed payload the sync path needs, so
     * the two cannot be combined. Only called on demand, never during sync.
     */
    public function getRawMessage(Account $account, string $messageId): string
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request(
            'GET',
            self::BASE . '/messages/' . urlencode($messageId),
            [
                'auth_bearer' => $token,
                'query'       => ['format' => 'raw'],
            ],
        );

        $body = $this->decode($response, 'messages.get(raw)');
        $raw  = (string) ($body['raw'] ?? '');

        if ('' === $raw) {
            return '';
        }

        // Gmail uses base64url, which strtr() converts to standard base64.
        return (string) base64_decode(strtr($raw, '-_', '+/'), true);
    }

    // ── history ───────────────────────────────────────────────────────────────

    /**
     * Fetch history records since a given historyId.
     *
     * @param array<string,string|int> $params
     * @return array{history: list<array<string,mixed>>, historyId: string}
     */
    public function listHistory(Account $account, string $startHistoryId, array $params = []): array
    {
        $token           = $this->tokenManager->getValidAccessToken($account);
        $history         = [];
        $page            = null;
        $latestHistoryId = $startHistoryId;

        do {
            $query = array_merge($params, ['startHistoryId' => $startHistoryId]);

            if (null !== $page) {
                $query['pageToken'] = $page;
            }

            $response = $this->httpClient->request('GET', self::BASE . '/history', [
                'auth_bearer' => $token,
                'query'       => $query,
            ]);

            $body = $this->decode($response, 'history.list');
            $page = $body['nextPageToken'] ?? null;

            if (true === isset($body['historyId'])) {
                $latestHistoryId = (string) $body['historyId'];
            }

            foreach ($body['history'] ?? [] as $record) {
                $history[] = $record;
            }
        } while (null !== $page);

        return [
            'history'   => $history,
            'historyId' => $latestHistoryId,
        ];
    }

    // ── watch / push ──────────────────────────────────────────────────────────

    /**
     * @return array{historyId: string, expiration: string, resourceName: string}
     */
    public function watch(Account $account, string $topicName, string $labelId = 'INBOX'): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('POST', self::BASE . '/watch', [
            'auth_bearer' => $token,
            'json'        => [
                'topicName'           => $topicName,
                'labelIds'            => [$labelId],
                'labelFilterBehavior' => 'INCLUDE',
            ],
        ]);

        return $this->decode($response, 'watch');
    }

    /**
     * Stop an active watch registration.
     */
    public function stopWatch(Account $account): void
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $this->httpClient->request('POST', self::BASE . '/stop', [
            'auth_bearer' => $token,
        ]);
    }

    // ── profile ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function getProfile(Account $account): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('GET', self::BASE . '/profile', [
            'auth_bearer' => $token,
        ]);

        return $this->decode($response, 'getProfile');
    }

    /**
     * All send-as addresses for a Gmail account — the primary plus any verified
     * custom send-as aliases — from the settings API. This is Gmail's analogue
     * of the Graph profile emails. Covered by the https://mail.google.com/ scope
     * we already hold; an empty/failed result is treated as "nothing to seed".
     *
     * @return list<array{address: string, displayName: ?string, isDefault: bool}>
     */
    public function listSendAs(Account $account): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        try {
            $response = $this->httpClient->request('GET', self::BASE . '/settings/sendAs', [
                'auth_bearer' => $token,
            ]);

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $entries = $response->toArray(false)['sendAs'] ?? [];
        } catch (HttpException) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            $address = strtolower(trim((string) ($entry['sendAsEmail'] ?? '')));

            if ('' === $address) {
                continue;
            }

            $displayName = trim((string) ($entry['displayName'] ?? ''));

            $result[] = [
                'address'     => $address,
                'displayName' => '' !== $displayName ? $displayName : null,
                'isDefault'   => true === ($entry['isDefault'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Download a single attachment's bytes.
     */
    public function getAttachment(Account $account, string $messageId, string $attachmentId): string
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request(
            'GET',
            self::BASE . '/messages/' . urlencode($messageId) . '/attachments/' . urlencode($attachmentId),
            ['auth_bearer' => $token],
        );

        $body = $this->decode($response, 'attachments.get');
        $data = (string) ($body['data'] ?? '');

        return base64_decode(strtr($data, '-_', '+/'));
    }


    // ── Batch helpers ─────────────────────────────────────────────────────────

    /**
     * Build the multipart/mixed body for a Gmail Batch API request.
     *
     * Each sub-request is a self-contained HTTP/1.1 GET for messages.get
     * (format=full). The boundary wraps every part.
     *
     * @param list<string> $messageIds
     */
    private function buildBatchBody(array $messageIds, string $boundary): string
    {
        $parts = [];

        foreach ($messageIds as $id) {
            $path = '/gmail/v1/users/me/messages/' . urlencode($id) . '?format=full';

            $parts[] = implode("\r\n", [
                '--' . $boundary,
                'Content-Type: application/http',
                'Content-Id: <' . $id . '>',
                '',
                'GET ' . $path . ' HTTP/1.1',
                'Host: gmail.googleapis.com',
                '',
                '',
            ]);
        }

        return implode('', $parts) . '--' . $boundary . '--';
    }

    /**
     * Parse a multipart/mixed batch response body.
     *
     * Each part carries a Content-ID echoing the sub-request id (Google
     * prefixes it with "response-") and an inner HTTP/1.1 envelope followed
     * by a JSON body. 200 parts are decoded into payloads; every part's
     * status is recorded so the caller can classify failures per id.
     *
     * @return array{payloads: array<string,array<string,mixed>>, statuses: array<string,int>}
     */
    private function parseBatchResponse(string $rawBody): array
    {
        // The batch response boundary appears on the first non-empty line,
        // preceded by "--". Leading \r\n before the first boundary is normal.
        // Match anywhere in the first 512 bytes to be safe.
        $head = substr($rawBody, 0, 512);

        if (1 !== preg_match('/--([a-zA-Z0-9_\-]+)/', $head, $m)) {
            return [
                'payloads' => [],
                'statuses' => [],
            ];
        }

        $boundary = $m[1];
        $payloads = [];
        $statuses = [];

        // Split on the boundary lines, drop the preamble and epilogue.
        $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?(?:\r?\n|$)/', $rawBody);

        if (false === $parts) {
            return [
                'payloads' => [],
                'statuses' => [],
            ];
        }

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");

            if ('' === $part) {
                continue;
            }

            // Which sub-request is this? Google echoes our Content-Id with a
            // "response-" prefix.
            $contentId = null;

            if (1 === preg_match('/^Content-ID:\s*<?(?:response-)?([^>\r\n]+)>?/mi', $part, $cm)) {
                $contentId = trim($cm[1]);
            }

            // Inner HTTP status line.
            $status = null;

            if (1 === preg_match('/HTTP\/[\d.]+\s+(\d{3})/', $part, $sm)) {
                $status = (int) $sm[1];
            }

            // JSON body — everything from the first brace on.
            $jsonStart = strpos($part, '{');
            $json      = false !== $jsonStart ? substr($part, $jsonStart) : '';

            if (200 === $status && '' !== $json) {
                $decoded = json_decode($json, true);

                if (true === is_array($decoded) && true === isset($decoded['id'])) {
                    $gmailId            = (string) $decoded['id'];
                    $payloads[$gmailId] = $decoded;
                    $statuses[$gmailId] = 200;
                    continue;
                }
            }

            if (null !== $contentId && null !== $status) {
                $statuses[$contentId] = $status;
            }
        }

        return [
            'payloads' => $payloads,
            'statuses' => $statuses,
        ];
    }
    // ── labels ────────────────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>  raw `labels` array from labels.list
     */
    public function listLabels(Account $account): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('GET', self::BASE . '/labels', [
            'auth_bearer' => $token,
        ]);

        $body = $this->decode($response, 'labels.list');

        return $body['labels'] ?? [];
    }

    /**
     * Create a label, optionally with a colour.
     *
     * Safe to send a colour here because a label being created has none at
     * Gmail to lose — the drift a 89-to-9 map can cause needs a round trip,
     * and creation never makes one. The pair comes from
     * GmailLabelColorMapper: Gmail rejects any hex outside its own palette,
     * and takes background and text together or not at all.
     *
     * @param array{backgroundColor: string, textColor: string}|null $color
     *
     * @return array<string,mixed>  the created label resource (id, name, …)
     */
    public function createLabel(Account $account, string $name, ?array $color = null): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $payload = [
            'name'                  => $name,
            'labelListVisibility'   => 'labelShow',
            'messageListVisibility' => 'show',
        ];

        if (null !== $color) {
            $payload['color'] = $color;
        }

        $response = $this->httpClient->request('POST', self::BASE . '/labels', [
            'auth_bearer' => $token,
            'json'        => $payload,
        ]);

        return $this->decode($response, 'labels.create');
    }

    /**
     * Rename an existing label. Gmail carries hierarchy in the name itself
     * ("Work/Invoices"), so a move to a different parent is also a rename.
     *
     * @return array<string,mixed>  the updated label resource
     */
    public function patchLabel(Account $account, string $labelId, string $name): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('PATCH', self::BASE . '/labels/' . rawurlencode($labelId), [
            'auth_bearer' => $token,
            'json'        => ['name' => $name],
        ]);

        return $this->decode($response, 'labels.patch');
    }

    /**
     * Delete a label. Gmail removes it from every message it was on; the
     * messages themselves survive.
     *
     * The response is 204 with no body, so it is asserted rather than decoded.
     * A label that is already gone counts as done: the caller's job is to make
     * the label not exist, and failing here would only requeue a delete that
     * can never succeed.
     */
    public function deleteLabel(Account $account, string $labelId): void
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('DELETE', self::BASE . '/labels/' . rawurlencode($labelId), [
            'auth_bearer' => $token,
        ]);

        if (true === in_array($response->getStatusCode(), [404, 410], true)) {
            return;
        }

        $this->assertSuccess($response, 'labels.delete');
    }

    /**
     * Mutate labels on up to 1000 messages in one call.
     *
     * Gmail answers 204 with no body, so nothing here needs decoding — but the
     * response still has to be asserted. Left unread, the request object is
     * simply discarded and the call cannot fail: archiving a thread while Gmail
     * was rate-limiting us dropped the push on the floor, with no exception for
     * the handler to catch and nothing in the log to say the change never
     * reached Google.
     *
     * @param list<string> $gmailMessageIds
     * @param list<string> $addLabelIds
     * @param list<string> $removeLabelIds
     */
    public function batchModify(Account $account, array $gmailMessageIds, array $addLabelIds, array $removeLabelIds): void
    {
        if (count($gmailMessageIds) === 0) {
            return;
        }

        $token = $this->tokenManager->getValidAccessToken($account);

        $payload = ['ids' => $gmailMessageIds];

        if (count($addLabelIds) > 0) {
            $payload['addLabelIds'] = $addLabelIds;
        }

        if (count($removeLabelIds) > 0) {
            $payload['removeLabelIds'] = $removeLabelIds;
        }

        $response = $this->httpClient->request('POST', self::BASE . '/messages/batchModify', [
            'auth_bearer' => $token,
            'json'        => $payload,
        ]);

        $this->assertSuccess($response, 'messages.batchModify');
    }

    /**
     * Destroy these messages at Google, for good.
     *
     * `messages.batchDelete` is not `messages.trash`: there is no undo, the mail
     * does not appear in Bin, and Gmail's own interface warns before offering
     * it. plMail only reaches this from an explicit "delete forever", never
     * from the ordinary Delete — see MessagePurger.
     *
     * It needs the full https://mail.google.com/ scope. An account connected
     * before that scope was requested, or one where the user declined it, gets
     * a 403 with reason `insufficientPermissions`, which assertSuccess()
     * classifies as unrecoverable — so it fails loudly into the log rather than
     * retrying forever, and the caller decides what to tell the user. Quietly
     * falling back to trash() would be worse than either: the message would
     * still be at Google while plMail had already destroyed its own copy and
     * told the user it was gone.
     *
     * @param list<string> $gmailMessageIds
     */
    public function batchDelete(Account $account, array $gmailMessageIds): void
    {
        if (count($gmailMessageIds) === 0) {
            return;
        }

        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('POST', self::BASE . '/messages/batchDelete', [
            'auth_bearer' => $token,
            'json'        => ['ids' => $gmailMessageIds],
        ]);

        $this->assertSuccess($response, 'messages.batchDelete');
    }

    // ── Failure handling ──────────────────────────────────────────────────────

    /**
     * Classify the response, then decode it.
     *
     * Every single-shot call goes through here rather than calling toArray()
     * directly. toArray() raises Symfony's own HTTP exception, whose message is
     * just `HTTP/2 403 returned for "…"` — the body, and with it Google's
     * reason, is thrown away. That is how a Gmail rate limit spent production
     * looking like a scope problem.
     *
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response, string $operation): array
    {
        $this->assertSuccess($response, $operation);

        // throw=false so this stays the only place that decides what a failure
        // means. Left at true, a status assertSuccess() deliberately let past
        // would still raise the bare HTTP exception this exists to replace.
        return $response->toArray(false);
    }

    /**
     * Turn a non-2xx Gmail response into an exception Messenger can act on.
     *
     * The split is between quota rejections, which clear on their own, and
     * refusals that will not: retrying insufficientPermissions buries the log
     * line that explains the failure, and retrying dailyLimitExceeded spends
     * quota the account has already run out of.
     *
     * Everything else — 5xx, 404, an unrecognised reason — is left
     * unclassified, so the transport's default strategy applies exactly as it
     * did before. The status stays in the message text as well as on the
     * exception because it is the only part of a failure that always survives
     * into a log line.
     */
    private function assertSuccess(ResponseInterface $response, string $operation): void
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $failure = $this->describeFailure($response);
        $reason  = $failure['reason'];

        $message = sprintf(
            'Gmail %s failed with %d%s: %s',
            $operation,
            $status,
            '' !== $reason ? ' (' . $reason . ')' : '',
            '' !== $failure['message'] ? $failure['message'] : '(no message in body)',
        );

        // 429 is in here for completeness only — Gmail answers 403 for quota,
        // which is the entire reason the reason has to be read.
        if (429 === $status || (403 === $status && true === in_array($reason, self::TRANSIENT_REASONS, true))) {
            throw new GmailThrottledException(
                $message,
                $status,
                $reason,
                $this->retryAfterSeconds($response),
            );
        }

        if (403 === $status && true === in_array($reason, self::PERMANENT_REASONS, true)) {
            throw new GmailPermanentException($message, $status, $reason);
        }

        throw new GmailApiException($message, $status, $reason);
    }

    /**
     * Pull Google's `reason` and human message out of an error body.
     *
     * Written to survive a body that is empty, truncated or an HTML error page
     * from a proxy in front of the API — none of which are hypothetical, and
     * all of which would otherwise turn a clean 403 into a JsonException that
     * says nothing about what went wrong.
     *
     * @return array{reason: string, message: string}
     */
    private function describeFailure(ResponseInterface $response): array
    {
        try {
            $raw = $response->getContent(false);
        } catch (HttpException) {
            // The body never arrived — a truncated or reset stream. The status
            // is still worth reporting on its own.
            return ['reason' => '', 'message' => ''];
        }

        $decoded = json_decode($raw, true);
        $error   = true === is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (false === is_array($error)) {
            // Not a Google error envelope — an empty body, a truncated one, or
            // an HTML page from a proxy in front of the API. A short excerpt
            // beats discarding it: the proxy page at least names the proxy.
            return ['reason' => '', 'message' => trim(substr($raw, 0, 200))];
        }

        $errors = $error['errors'] ?? null;
        $first  = true === is_array($errors) ? ($errors[0] ?? null) : null;
        $reason = true === is_array($first) ? ($first['reason'] ?? null) : null;

        // errors[].reason is the classic form; error.status ("RESOURCE_EXHAUSTED",
        // "PERMISSION_DENIED") is what newer Google endpoints send instead.
        $reason ??= $error['status'] ?? null;
        $detail   = $error['message'] ?? null;

        return [
            'reason'  => true === is_scalar($reason) ? (string) $reason : '',
            'message' => true === is_scalar($detail) ? (string) $detail : '',
        ];
    }

    /**
     * Retry-After in seconds, when Gmail bothered to send one.
     *
     * It usually does not on a 403 quota rejection, which is why
     * GmailThrottledException carries its own fallback. The header also has an
     * HTTP-date form; that is ignored rather than parsed, because Google sends
     * the delta form and a misparsed date would produce a nonsense delay.
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
}
