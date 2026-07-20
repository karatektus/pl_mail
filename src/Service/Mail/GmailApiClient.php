<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Account;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

            $body = $response->toArray();
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

        return $response->toArray();
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

            $body = $response->toArray();
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

        return $response->toArray();
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

        return $response->toArray();
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

        $body = $response->toArray();
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

        $body = $response->toArray();

        return $body['labels'] ?? [];
    }

    /**
     * @return array<string,mixed>  the created label resource (id, name, …)
     */
    public function createLabel(Account $account, string $name): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('POST', self::BASE . '/labels', [
            'auth_bearer' => $token,
            'json'        => [
                'name'                  => $name,
                'labelListVisibility'   => 'labelShow',
                'messageListVisibility' => 'show',
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Mutate labels on up to 1000 messages in one call.
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

        $this->httpClient->request('POST', self::BASE . '/messages/batchModify', [
            'auth_bearer' => $token,
            'json'        => $payload,
        ]);
    }
}
