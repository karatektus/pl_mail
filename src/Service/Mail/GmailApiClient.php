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
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenManager  $tokenManager,
    ) {}

    // ── messages ─────────────────────────────────────────────────────────────

    /**
     * List message IDs (and optional thread IDs) matching a query.
     *
     * Returns the raw `messages` array from the API response, which contains
     * objects like `{"id": "…", "threadId": "…"}`.
     *
     * Handles pagination automatically and returns all pages concatenated.
     *
     * @param array<string,string|int> $params  e.g. ['labelIds' => 'INBOX', 'maxResults' => 500]
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
     * Fetch a single message in full RFC-2822 format (raw) so we can parse it
     * exactly like an IMAP message.
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
     * Returns the raw `history` array. Each record may contain
     * `messagesAdded`, `messagesDeleted`, `labelsAdded`, `labelsRemoved`.
     *
     * Also returns the new `historyId` that should be stored for the next call.
     *
     * @param array<string,string|int> $params  e.g. ['labelId' => 'INBOX']
     * @return array{history: list<array<string,mixed>>, historyId: string}
     */
    public function listHistory(Account $account, string $startHistoryId, array $params = []): array
    {
        $token   = $this->tokenManager->getValidAccessToken($account);
        $history = [];
        $page    = null;
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
     * Register a push notification watch on the inbox.
     *
     * @return array{historyId: string, expiration: string, resourceName: string}
     */
    public function watch(Account $account, string $topicName, string $labelId = 'INBOX'): array
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('POST', self::BASE . '/watch', [
            'auth_bearer' => $token,
            'json'        => [
                'topicName'  => $topicName,
                'labelIds'   => [$labelId],
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
     * Returns `{"emailAddress": "…", "messagesTotal": …, "historyId": "…"}`.
     *
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
}
