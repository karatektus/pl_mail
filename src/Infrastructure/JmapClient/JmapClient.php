<?php

declare(strict_types=1);

namespace App\Infrastructure\JmapClient;

use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapThrottledException;
use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Talks JMAP (RFC 8620) to a remote server on an account's behalf.
 *
 * The transport and nothing else: find the session, send a batch of method
 * calls, fetch and upload blobs. What the calls mean — Email/changes,
 * CalendarEvent/set — belongs to the services that make them, the way
 * GraphApiClient and GmailApiClient hand back decoded JSON and leave the
 * meaning to their syncers. No JMAP library: the protocol is JSON over one
 * POST, and the libraries that exist are thin wrappers over exactly that.
 *
 * ## Which HTTP client
 *
 * `app.http_client.integration`, the one every user-typed URL goes through.
 * The host is typed into the account form, the session is reached through a
 * well-known path that is allowed to redirect, and the session itself then
 * names further URLs — three chances for a server to point plMail at the
 * container network. UserUrlHttpClient re-checks every hop. A JMAP server on
 * the LAN is therefore reached only once an administrator lists it in
 * INTEGRATIONS_ALLOWED_HOSTS, as a LAN CalDAV server is; IMAP needs no such
 * entry because a socket that speaks IMAP cannot be talked into fetching
 * anything else.
 *
 * ## Sessions
 *
 * Cached per account for the life of the process and dropped on reset(),
 * which Messenger calls between messages. A response whose sessionState
 * differs from the cached session's state drops it early, which is RFC 8620
 * §3.4's signal that the URLs or the accounts may have changed.
 */
final class JmapClient implements ResetInterface
{
    /**
     * Seconds a request may take before it is abandoned.
     *
     * Long enough for a Blob/get of a page of large messages; short enough
     * that a server which accepted the connection and went quiet does not
     * hold a worker for the default of the HTTP client, which is forever.
     */
    private const int TIMEOUT_SECONDS = 60;

    /** @var array<int, JmapSession> account id → session */
    private array $sessions = [];

    /**
     * Sessions of accounts that have not been saved — the connection test.
     *
     * Keyed by the object, weakly, because such an account has no id to key
     * on and the test makes two requests with it: the session, then a
     * Mailbox/get that needs the session's API URL.
     *
     * @var \WeakMap<Account, JmapSession>
     */
    private \WeakMap $unsaved;

    public function __construct(
        #[Autowire(service: 'app.http_client.integration')]
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->unsaved = new \WeakMap();
    }

    /**
     * Where an account's session resource is.
     *
     * The override when one is set, the RFC's well-known path on the incoming
     * host otherwise. The port is included only when it is not HTTPS's own,
     * so the common case is the URL a person would type.
     */
    public function sessionUrl(Account $account): string
    {
        $override = $account->jmapSessionUrl;

        if (null !== $override) {
            return $override;
        }

        $port = (int) ($account->imapPort ?? 443);

        return sprintf(
            'https://%s%s/.well-known/jmap',
            MailServerHost::forAuthority((string) $account->imapHost),
            443 === $port || 0 === $port ? '' : ':' . $port,
        );
    }

    /**
     * The session for an account, fetched on first use.
     *
     * @throws JmapApiException when the server cannot be reached, refuses the
     *                          credentials, or answers with no usable session
     */
    public function session(Account $account): JmapSession
    {
        $key    = (int) $account->id;
        $cached = 0 === $key ? ($this->unsaved[$account] ?? null) : ($this->sessions[$key] ?? null);

        if (null !== $cached) {
            return $cached;
        }

        $url  = $this->sessionUrl($account);
        $json = $this->decode($this->send($account, 'GET', $url, []), 'session');

        try {
            $session = JmapSession::fromArray($json, $url);
        } catch (\InvalidArgumentException $invalid) {
            throw new JmapApiException($invalid->getMessage());
        }

        if (0 === $key) {
            $this->unsaved[$account] = $session;
        } else {
            $this->sessions[$key] = $session;
        }

        return $session;
    }

    /**
     * Send one request of method calls and hand back the answers.
     *
     * @param list<string>                                             $using capability URIs the calls need; core is added
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $calls [name, arguments, call id]
     *
     * @throws JmapApiException
     */
    public function call(Account $account, array $using, array $calls): JmapResponse
    {
        $session = $this->session($account);
        $using   = array_values(array_unique([JmapCapability::CORE, ...$using]));

        $json = $this->decode($this->send($account, 'POST', $session->apiUrl, [
            'json' => ['using' => $using, 'methodCalls' => $calls],
        ]), 'API');

        $invocations = [];

        foreach (is_array($json['methodResponses'] ?? null) ? $json['methodResponses'] : [] as $invocation) {
            if (true === is_array($invocation) && true === is_string($invocation[0] ?? null)
                && true === is_array($invocation[1] ?? null) && true === is_string($invocation[2] ?? null)) {
                $invocations[] = [$invocation[0], $invocation[1], $invocation[2]];
            }
        }

        $state = is_string($json['sessionState'] ?? null) ? $json['sessionState'] : $session->state;

        if ($state !== $session->state) {
            $this->forget($account);
        }

        return new JmapResponse($invocations, $state);
    }

    /**
     * Download one blob's bytes.
     *
     * @throws JmapApiException
     */
    public function download(Account $account, string $accountId, string $blobId, string $type = 'application/octet-stream', string $name = 'blob'): string
    {
        $url = $this->session($account)->downloadUrlFor($accountId, $blobId, $type, $name);

        return $this->send($account, 'GET', $url, [])->getContent();
    }

    /**
     * Upload bytes as a blob and return its id.
     *
     * @throws JmapApiException
     */
    public function upload(Account $account, string $accountId, string $bytes, string $type): string
    {
        $url  = $this->session($account)->uploadUrlFor($accountId);
        $json = $this->decode($this->send($account, 'POST', $url, [
            'body'    => $bytes,
            'headers' => ['Content-Type' => $type],
        ]), 'upload');

        $blobId = $json['blobId'] ?? null;

        if (false === is_string($blobId) || '' === $blobId) {
            throw new JmapApiException('JMAP upload answered without a blob id.');
        }

        return $blobId;
    }

    /**
     * Open a request whose body is read as it arrives — the EventSource push
     * channel, which is a response that never ends.
     *
     * Not through send(): its status is the caller's to read once the headers
     * are in, and a timeout here means "no event for a while", which the
     * caller turns into a reconnect rather than a failure.
     *
     * @param array<string, mixed> $options
     */
    public function open(Account $account, string $url, array $options): ResponseInterface
    {
        $options['headers'] = ['Authorization' => $this->authorization($account)] + ($options['headers'] ?? []);

        return $this->httpClient->request('GET', $url, $options);
    }

    /**
     * Wait on several open() responses at once, for the push channel.
     *
     * The same HTTP client's stream(): a response is only ever streamed by the
     * client that made it, and the push listener holds one per account.
     *
     * @param iterable<ResponseInterface> $responses
     */
    public function stream(iterable $responses, float $timeout): ResponseStreamInterface
    {
        return $this->httpClient->stream($responses, $timeout);
    }

    /**
     * The Authorization header value for this account.
     *
     * Public for the push channel, which opens its own request.
     */
    public function authorization(Account $account): string
    {
        if (true === $account->jmapTokenAuth) {
            return 'Bearer ' . (string) $account->password;
        }

        return 'Basic ' . base64_encode(sprintf('%s:%s', (string) $account->username, (string) $account->password));
    }

    public function reset(): void
    {
        $this->sessions = [];
        $this->unsaved  = new \WeakMap();
    }

    /**
     * Forget one account's session, so the next call fetches it again.
     *
     * For the connection test and for an account whose settings were just
     * edited — both cases where the cached session describes a server the
     * account may no longer point at.
     */
    public function forget(Account $account): void
    {
        unset($this->sessions[(int) $account->id], $this->unsaved[$account]);
    }

    /**
     * One authenticated request, with every failure turned into a JMAP one.
     *
     * The status is read here, before the body, so a refusal is reported as
     * what it is rather than as the JSON decode error its HTML body would
     * have caused.
     *
     * @param array<string, mixed> $options
     *
     * @throws JmapApiException
     */
    private function send(Account $account, string $method, string $url, array $options): ResponseInterface
    {
        $headers = ['Authorization' => $this->authorization($account), 'Accept' => 'application/json'];

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers + ($options['headers'] ?? []),
                'timeout' => self::TIMEOUT_SECONDS,
            ] + $options);

            $status = $response->getStatusCode();
        } catch (HttpClientException $failure) {
            // Kept as the cause: whether a request left before it failed is
            // what decides whether a send may have gone through — see
            // JmapApiSender and SendTransportFailure.
            throw new JmapApiException(sprintf('JMAP server could not be reached: %s', $failure->getMessage()), 0, null, $failure);
        }

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

        if (429 === $status || (503 === $status && null !== $retryAfter)) {
            throw new JmapThrottledException(
                sprintf('JMAP server asked to slow down (%d).', $status),
                true === is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
                $status,
            );
        }

        // RFC 8620 §3.6.1: a request-level refusal is a problem-details
        // object whose type names the reason. Read it when it is there; a
        // proxy in front of the server answers in HTML and has no type to give.
        $type = null;

        try {
            $problem = json_decode($response->getContent(false), true);
            $type    = is_array($problem) && is_string($problem['type'] ?? null) ? $problem['type'] : null;
        } catch (HttpClientException) {
            // The status stands on its own.
        }

        throw new JmapApiException(
            sprintf('JMAP server refused the %s request (%d%s).', $method, $status, null === $type ? '' : ', ' . $type),
            $status,
            $type,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JmapApiException
     */
    private function decode(ResponseInterface $response, string $what): array
    {
        try {
            $json = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException|HttpClientException $failure) {
            throw new JmapApiException(sprintf('JMAP %s response could not be read: %s', $what, $failure->getMessage()));
        }

        if (false === is_array($json)) {
            throw new JmapApiException(sprintf('JMAP %s response is not an object.', $what));
        }

        return $json;
    }
}
