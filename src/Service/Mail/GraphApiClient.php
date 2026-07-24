<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Account;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around the Microsoft Graph endpoints needed for mail sync.
 *
 * Intentionally slim, exactly like GmailApiClient: every method returns the
 * decoded JSON array so callers never deal with HTTP. No Graph SDK — the
 * generated SDK is heavy and buys nothing over what we already do by hand.
 *
 * ── Immutable IDs ─────────────────────────────────────────────────────────
 * By default a Graph message `id` CHANGES when the message moves between
 * folders (archive, delete, inbox rules). That would produce a duplicate row
 * on every archive. `Prefer: IdType="ImmutableId"` fixes it, but the header
 * applies only to the request it is sent with — so it is injected centrally
 * here, including into every $batch sub-request, and no call site can forget
 * it.
 *
 * Known gaps we live with (see probeImmutableIds()):
 *   - not supported on `mailFolder` resources → folder ids are NOT immutable,
 *     which is why GraphFolderSyncer re-resolves system folders by
 *     wellKnownFolderName rather than trusting stored ids;
 *   - silently ignored when combined with $search — we never combine them;
 *   - unreliable on personal outlook.com accounts and shared mailboxes.
 * Because dedup keys on the RFC Message-ID rather than the Graph id, an
 * account without immutable-id support still syncs correctly; it just
 * re-addresses more often.
 *
 * Docs: https://learn.microsoft.com/en-us/graph/api/resources/mail-api-overview
 */
final class GraphApiClient
{
    private const string BASE  = 'https://graph.microsoft.com/v1.0';
    private const string ME    = self::BASE . '/me';
    private const string BATCH = self::BASE . '/$batch';

    /** Graph caps $batch at 20 sub-requests (Gmail allows 100). */
    public const int BATCH_LIMIT = 20;

    /** Properties fetched for a full message import. */
    private const string MESSAGE_SELECT = 'id,internetMessageId,conversationId,conversationIndex,'
    . 'subject,from,sender,toRecipients,ccRecipients,bccRecipients,replyTo,'
    . 'receivedDateTime,sentDateTime,isRead,isDraft,flag,importance,categories,'
    . 'body,bodyPreview,hasAttachments,parentFolderId,internetMessageHeaders';

    /** Light projection used while planning — ids only, no bodies. */
    private const string DELTA_SELECT = 'id,internetMessageId,parentFolderId';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenManager   $tokenManager,
    ) {}

    // ── Folders ──────────────────────────────────────────────────────────────

    /**
     * Every mail folder in the mailbox, flattened, following pagination.
     *
     * @return list<array<string,mixed>>
     */
    public function listFolders(Account $account): array
    {
        return $this->collectPages(
            $account,
            self::ME . '/mailFolders/delta',
            ['$select' => 'id,displayName,parentFolderId,wellKnownName,totalItemCount'],
        )['items'];
    }

    /**
     * Resolve a well-known folder (inbox, sentitems, drafts, deleteditems,
     * junkemail, archive) to its current id. Folder ids are not immutable, so
     * this is called rather than cached across syncs.
     *
     * @return array<string,mixed>
     */
    public function getWellKnownFolder(Account $account, string $wellKnownName): array
    {
        return $this->request($account, 'GET', self::ME . '/mailFolders/' . rawurlencode($wellKnownName), [
            'query' => ['$select' => 'id,displayName,parentFolderId,wellKnownName'],
        ])->toArray();
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /**
     * Run a delta query over one folder.
     *
     * Pass the stored deltaLink to get only changes since last run; pass null
     * to start a fresh enumeration. Returns the light message projection plus
     * the new deltaLink to persist.
     *
     * Removed items arrive as `['id' => …, '@removed' => ['reason' => …]]`.
     *
     * @return array{items: list<array<string,mixed>>, deltaLink: string|null, resyncRequired: bool}
     */
    public function deltaMessages(Account $account, string $folderId, ?string $deltaLink): array
    {
        if (null !== $deltaLink && '' !== $deltaLink) {
            $url   = $deltaLink;
            $query = [];
        } else {
            $url   = self::ME . '/mailFolders/' . rawurlencode($folderId) . '/messages/delta';
            // $select is honoured on the INITIAL call only and then sticks for
            // the whole delta chain — do not re-send it on follow-ups.
            $query = ['$select' => self::DELTA_SELECT];
        }

        try {
            $result = $this->collectPages($account, $url, $query);
        } catch (GraphResyncRequiredException) {
            return [
                'items'          => [],
                'deltaLink'      => null,
                'resyncRequired' => true,
            ];
        }

        return [
            'items'          => $result['items'],
            'deltaLink'      => $result['deltaLink'],
            'resyncRequired' => false,
        ];
    }

    /**
     * Fetch full message resources for up to BATCH_LIMIT ids in one $batch POST.
     *
     * Graph sub-responses fail INDIVIDUALLY — a 200 on the outer batch says
     * nothing about the parts. Successes and throttled ids are therefore
     * returned separately so the caller can requeue only what needs retrying.
     *
     * @param list<string> $ids
     * @return array{messages: list<array<string,mixed>>, throttled: list<string>, failed: array<string,int>}
     */
    public function batchGetMessages(Account $account, array $ids): array
    {
        $ids = array_values($ids);

        if (count($ids) === 0) {
            return ['messages' => [], 'throttled' => [], 'failed' => []];
        }

        if (count($ids) > self::BATCH_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'Graph $batch accepts at most %d sub-requests, %d given.',
                self::BATCH_LIMIT,
                count($ids),
            ));
        }

        $requests = [];

        foreach ($ids as $index => $id) {
            $requests[] = [
                'id'      => (string) $index,
                'method'  => 'GET',
                'url'     => '/me/messages/' . rawurlencode($id) . '?$select=' . self::MESSAGE_SELECT,
                // Sub-request headers do NOT inherit from the outer POST.
                'headers' => ['Prefer' => 'IdType="ImmutableId"'],
            ];
        }

        $response = $this->request($account, 'POST', self::BATCH, [
            'json' => ['requests' => $requests],
        ])->toArray();

        $messages  = [];
        $throttled = [];
        $failed    = [];

        foreach ($response['responses'] ?? [] as $sub) {
            $index  = (int) ($sub['id'] ?? -1);
            $status = (int) ($sub['status'] ?? 0);
            $id     = $ids[$index] ?? null;

            if (null === $id) {
                continue;
            }

            if ($status >= 200 && $status < 300) {
                $body = $sub['body'] ?? null;

                if (true === is_array($body)) {
                    $messages[] = $body;
                }

                continue;
            }

            if (429 === $status || 503 === $status) {
                $throttled[] = $id;
                continue;
            }

            $failed[$id] = $status;
        }

        return [
            'messages'  => $messages,
            'throttled' => $throttled,
            'failed'    => $failed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getMessage(Account $account, string $messageId): array
    {
        return $this->request($account, 'GET', self::ME . '/messages/' . rawurlencode($messageId), [
            'query' => ['$select' => self::MESSAGE_SELECT],
        ])->toArray();
    }

    /**
     * Update mutable message state (isRead, flag, categories).
     *
     * @param array<string,mixed> $properties
     */
    public function patchMessage(Account $account, string $messageId, array $properties): void
    {
        $this->request($account, 'PATCH', self::ME . '/messages/' . rawurlencode($messageId), [
            'json' => $properties,
        ])->getContent();
    }

    /**
     * Move a message to another folder. Returns the moved resource — whose id
     * is unchanged when immutable ids are in play, and different when they
     * are not, which is exactly why callers must re-read it.
     *
     * @return array<string,mixed>
     */
    public function moveMessage(Account $account, string $messageId, string $destinationFolderId): array
    {
        return $this->request($account, 'POST', self::ME . '/messages/' . rawurlencode($messageId) . '/move', [
            'json' => ['destinationId' => $destinationFolderId],
        ])->toArray();
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    /**
     * Attachment metadata for a message. `contentBytes` is inlined only for
     * small fileAttachments, so this deliberately does not request it — the
     * bytes are pulled lazily by getAttachmentContent().
     *
     * @return list<array<string,mixed>>
     */
    public function listAttachments(Account $account, string $messageId): array
    {
        $response = $this->request(
            $account,
            'GET',
            self::ME . '/messages/' . rawurlencode($messageId) . '/attachments',
            ['query' => ['$select' => 'id,name,contentType,size,isInline,contentId']],
        )->toArray();

        return array_values($response['value'] ?? []);
    }

    /**
     * Attachment metadata for many messages in one $batch POST.
     *
     * Graph does not inline attachment metadata on the message resource the
     * way Gmail's payload tree does, so without this the importer would issue
     * one extra request per message that has attachments.
     *
     * @param list<string> $messageIds
     * @return array<string, list<array<string,mixed>>>  messageId => attachments
     */
    public function batchListAttachments(Account $account, array $messageIds): array
    {
        $messageIds = array_values($messageIds);

        if (count($messageIds) === 0) {
            return [];
        }

        $result = [];

        foreach (array_chunk($messageIds, self::BATCH_LIMIT) as $chunk) {
            $requests = [];

            foreach ($chunk as $index => $id) {
                $requests[] = [
                    'id'      => (string) $index,
                    'method'  => 'GET',
                    'url'     => '/me/messages/' . rawurlencode($id)
                        . '/attachments?$select=id,name,contentType,size,isInline,contentId',
                    'headers' => ['Prefer' => 'IdType="ImmutableId"'],
                ];
            }

            $response = $this->request($account, 'POST', self::BATCH, [
                'json' => ['requests' => $requests],
            ])->toArray();

            foreach ($response['responses'] ?? [] as $sub) {
                $index  = (int) ($sub['id'] ?? -1);
                $status = (int) ($sub['status'] ?? 0);
                $id     = $chunk[$index] ?? null;

                if (null === $id || $status < 200 || $status >= 300) {
                    continue;
                }

                $result[$id] = array_values($sub['body']['value'] ?? []);
            }
        }

        return $result;
    }

    /**
     * Raw bytes of a fileAttachment. `/$value` streams the content directly
     * and sidesteps the ~3MB contentBytes inlining limit.
     */
    public function getAttachmentContent(Account $account, string $messageId, string $attachmentId): string
    {
        return $this->request(
            $account,
            'GET',
            self::ME . '/messages/' . rawurlencode($messageId)
            . '/attachments/' . rawurlencode($attachmentId) . '/$value',
        )->getContent();
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Send pre-built RFC822 MIME.
     *
     * Graph accepts a base64-encoded MIME body on /sendMail when the request
     * Content-Type is text/plain, which lets us reuse the same MIME that
     * MessageSendService already builds for SMTP and Gmail. Sent Items is
     * filed by the service. Hard limit is 4MB for this route; above that a
     * draft + upload session is required.
     */
    public function sendMime(Account $account, string $mime): void
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $response = $this->httpClient->request('POST', self::ME . '/sendMail', [
            'auth_bearer' => $token,
            'headers'     => ['Content-Type' => 'text/plain'],
            'body'        => base64_encode($mime),
        ]);

        $this->assertSuccess($response);
    }

    // ── Batched writes ───────────────────────────────────────────────────────

    /**
     * PATCH many messages in one $batch POST.
     *
     * Graph has no batchModify equivalent, so without this a "mark 200 threads
     * read" action becomes 200 requests against a mailbox that permits roughly
     * four concurrent — which reliably produces 429s on an action that feels
     * instant in the UI.
     *
     * @param array<string, array<string,mixed>> $patches  graphId => properties
     * @return array{throttled: list<string>, failed: array<string,int>}
     */
    public function batchPatchMessages(Account $account, array $patches): array
    {
        return $this->batchWrite($account, $patches, function (string $id, mixed $body): array {
            return [
                'method' => 'PATCH',
                'url'    => '/me/messages/' . rawurlencode($id),
                'body'   => $body,
            ];
        });
    }

    /**
     * Move many messages into one destination folder in a single $batch POST.
     *
     * Sub-responses carry the moved resource, whose id differs from the input
     * on mailboxes without immutable-id support — hence the id map in the
     * return value.
     *
     * @param list<string> $graphIds
     * @return array{moved: array<string,string>, throttled: list<string>, failed: array<string,int>}
     */
    public function batchMoveMessages(Account $account, array $graphIds, string $destinationFolderId): array
    {
        $payloads = [];

        foreach ($graphIds as $graphId) {
            $payloads[$graphId] = ['destinationId' => $destinationFolderId];
        }

        $result = $this->batchWrite(
            $account,
            $payloads,
            function (string $id, mixed $body): array {
                return [
                    'method' => 'POST',
                    'url'    => '/me/messages/' . rawurlencode($id) . '/move',
                    'body'   => $body,
                ];
            },
            $moved,
        );

        return [
            'moved'     => $moved,
            'throttled' => $result['throttled'],
            'failed'    => $result['failed'],
        ];
    }

    // ── Master categories ────────────────────────────────────────────────────

    /**
     * The mailbox's category definitions.
     *
     * Enumerated up front by GraphCategorySyncer, exactly as GmailLabelSyncer
     * enumerates labels — discovering them from individual messages' category
     * arrays would trickle them in one at a time with no colour and no
     * complete list.
     *
     * @return list<array<string,mixed>>
     */
    public function listMasterCategories(Account $account): array
    {
        $response = $this->request($account, 'GET', self::ME . '/outlook/masterCategories')->toArray();

        return array_values($response['value'] ?? []);
    }

    /**
     * Define a new category on the mailbox so it can be applied to messages.
     *
     * Colour is fixed at preset0. Graph exposes colours as preset0–preset24
     * rather than hex, and a lossy bidirectional mapping onto plMail's palette
     * would drift on every sync — losing colour fidelity is much cheaper.
     *
     * @return array<string,mixed>
     */
    public function createMasterCategory(Account $account, string $displayName): array
    {
        return $this->request($account, 'POST', self::ME . '/outlook/masterCategories', [
            'json' => [
                'displayName' => $displayName,
                'color'       => 'preset0',
            ],
        ])->toArray();
    }

    // ── Subscriptions (push) ─────────────────────────────────────────────────

    /**
     * Create a change subscription over the mailbox.
     *
     * Graph validates $notificationUrl synchronously during this call: it POSTs
     * a validationToken and expects a 200 with the raw token echoed back within
     * 10 seconds. If the URL is not publicly reachable — which it will not be
     * behind a misconfigured reverse proxy — this throws, and the caller falls
     * back to polling.
     *
     * @return array<string,mixed>
     */
    public function createSubscription(
        Account            $account,
        string             $notificationUrl,
        string             $lifecycleNotificationUrl,
        string             $clientState,
        \DateTimeImmutable $expiresAt,
    ): array {
        return $this->request($account, 'POST', self::BASE . '/subscriptions', [
            'json' => [
                'changeType'               => 'created,updated,deleted',
                'notificationUrl'          => $notificationUrl,
                'lifecycleNotificationUrl' => $lifecycleNotificationUrl,
                'resource'                 => '/me/messages',
                'clientState'              => $clientState,
                'expirationDateTime'       => $expiresAt->format(\DATE_ATOM),
            ],
        ])->toArray();
    }

    /**
     * Push the expiry out. Subscriptions must be renewed before they lapse;
     * once expired they cannot be revived and a new one must be created.
     *
     * @return array<string,mixed>
     */
    public function renewSubscription(Account $account, string $subscriptionId, \DateTimeImmutable $expiresAt): array
    {
        return $this->request($account, 'PATCH', self::BASE . '/subscriptions/' . rawurlencode($subscriptionId), [
            'json' => ['expirationDateTime' => $expiresAt->format(\DATE_ATOM)],
        ])->toArray();
    }

    public function deleteSubscription(Account $account, string $subscriptionId): void
    {
        $this->request($account, 'DELETE', self::BASE . '/subscriptions/' . rawurlencode($subscriptionId))
            ->getContent();
    }

    // ── Maintenance ──────────────────────────────────────────────────────────

    /**
     * Convert stored ids between formats — the repair tool for rows written
     * before immutable ids were enabled, or by a code path that missed the
     * Prefer header. Accepts up to 1000 ids per call.
     *
     * @param list<string> $ids
     * @return array<string,string>  inputId => targetId
     */
    public function translateExchangeIds(
        Account $account,
        array   $ids,
        string  $sourceFormat = 'restId',
        string  $targetFormat = 'restImmutableEntryId',
    ): array {
        if (count($ids) === 0) {
            return [];
        }

        $response = $this->request($account, 'POST', self::ME . '/translateExchangeIds', [
            'json' => [
                'inputIds'             => array_values($ids),
                'sourceIdType'         => $sourceFormat,
                'targetIdType'         => $targetFormat,
            ],
        ])->toArray();

        $map = [];

        foreach ($response['value'] ?? [] as $entry) {
            $source = (string) ($entry['sourceId'] ?? '');
            $target = (string) ($entry['targetId'] ?? '');

            if ('' !== $source && '' !== $target) {
                $map[$source] = $target;
            }
        }

        return $map;
    }

    /**
     * Does this mailbox actually honour immutable ids?
     *
     * Graph echoes `Preference-Applied: IdType="ImmutableId"` when it does.
     * Personal accounts and shared mailboxes frequently do not. Probed once at
     * connect time and cached on the Account.
     */
    public function probeImmutableIds(Account $account): bool
    {
        $response = $this->request($account, 'GET', self::ME . '/messages', [
            'query' => ['$top' => 1, '$select' => 'id'],
        ]);

        $applied = $response->getHeaders(false)['preference-applied'] ?? [];

        foreach ($applied as $value) {
            if (true === str_contains((string) $value, 'ImmutableId')) {
                return true;
            }
        }

        return false;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Follow @odata.nextLink until exhausted, accumulating `value` entries.
     *
     * @param array<string,mixed> $query
     * @return array{items: list<array<string,mixed>>, deltaLink: string|null}
     */
    private function collectPages(Account $account, string $url, array $query): array
    {
        $items     = [];
        $deltaLink = null;
        $next      = $url;
        $first     = true;

        while (null !== $next) {
            $options = [];

            // nextLink/deltaLink already carry every parameter they need.
            if (true === $first && count($query) > 0) {
                $options['query'] = $query;
            }

            $body = $this->request($account, 'GET', $next, $options)->toArray();
            $first = false;

            foreach ($body['value'] ?? [] as $item) {
                $items[] = $item;
            }

            $next = $body['@odata.nextLink'] ?? null;

            if (true === array_key_exists('@odata.deltaLink', $body)) {
                $deltaLink = (string) $body['@odata.deltaLink'];
            }
        }

        return [
            'items'     => $items,
            'deltaLink' => $deltaLink,
        ];
    }

    /**
     * Shared assembly + partial-failure handling for write batches.
     *
     * Identical throttle semantics to batchGetMessages(): a 200 on the outer
     * POST says nothing about the parts, so successes, throttled ids and hard
     * failures are separated for the caller.
     *
     * @param array<string, mixed>                     $payloads  graphId => request body
     * @param callable(string, mixed): array<string,mixed> $build   builds one sub-request
     * @param array<string,string>|null                 $resultIds out-param: graphId => returned id
     * @return array{throttled: list<string>, failed: array<string,int>}
     */
    private function batchWrite(Account $account, array $payloads, callable $build, ?array &$resultIds = null): array
    {
        $throttled = [];
        $failed    = [];
        $resultIds = [];

        if (count($payloads) === 0) {
            return ['throttled' => [], 'failed' => []];
        }

        foreach (array_chunk($payloads, self::BATCH_LIMIT, true) as $chunk) {
            $ids      = array_keys($chunk);
            $requests = [];

            foreach ($ids as $index => $graphId) {
                $sub = $build((string) $graphId, $chunk[$graphId]);

                $requests[] = [
                    'id'      => (string) $index,
                    'method'  => $sub['method'],
                    'url'     => $sub['url'],
                    'body'    => $sub['body'],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Prefer'       => 'IdType="ImmutableId"',
                    ],
                ];
            }

            $response = $this->request($account, 'POST', self::BATCH, [
                'json' => ['requests' => $requests],
            ])->toArray();

            foreach ($response['responses'] ?? [] as $subResponse) {
                $index   = (int) ($subResponse['id'] ?? -1);
                $status  = (int) ($subResponse['status'] ?? 0);
                $graphId = $ids[$index] ?? null;

                if (null === $graphId) {
                    continue;
                }

                if ($status >= 200 && $status < 300) {
                    $returnedId = (string) ($subResponse['body']['id'] ?? '');

                    if ('' !== $returnedId) {
                        $resultIds[(string) $graphId] = $returnedId;
                    }

                    continue;
                }

                if (429 === $status || 503 === $status) {
                    $throttled[] = (string) $graphId;
                    continue;
                }

                $failed[(string) $graphId] = $status;
            }
        }

        return [
            'throttled' => $throttled,
            'failed'    => $failed,
        ];
    }

    /**
     * Every outbound call funnels through here so the bearer token and the
     * immutable-id Prefer header are applied uniformly.
     *
     * @param array<string,mixed> $options
     */
    private function request(Account $account, string $method, string $url, array $options = []): ResponseInterface
    {
        $token = $this->tokenManager->getValidAccessToken($account);

        $options['auth_bearer'] = $token;
        $options['headers']     = array_merge(
            ['Prefer' => 'IdType="ImmutableId"'],
            $options['headers'] ?? [],
        );

        $response = $this->httpClient->request($method, $url, $options);

        $this->assertSuccess($response);

        return $response;
    }

    private function assertSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = $response->getContent(false);

        // An expired or invalidated delta token. The chain cannot be resumed;
        // the caller must restart enumeration for that folder.
        if (410 === $status) {
            throw new GraphResyncRequiredException($body);
        }

        if (429 === $status || 503 === $status) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

            throw new GraphThrottledException(
                $body,
                null !== $retryAfter ? (int) $retryAfter : null,
            );
        }

        throw new GraphApiException(sprintf('Graph request failed with %d: %s', $status, $body), $status);
    }
}
