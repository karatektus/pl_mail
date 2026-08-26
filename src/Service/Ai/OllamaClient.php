<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\OllamaModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Talks to an Ollama host on the local network.
 *
 * WHY THIS IS NOT ImageProxyFetcher
 * ─────────────────────────────────
 * That class exists to refuse private addresses: it takes a URL out of a
 * message somebody was sent, so a link to 10.0.0.5 is an attacker asking this
 * server to fetch something on the network only this server can reach.
 *
 * Here the private address IS the feature. The endpoint is typed by an
 * administrator into a form only an administrator can open, it is stored as
 * configuration rather than carried in content, and the whole point is that it
 * names a box on their own LAN. The two must never share a policy, and they
 * must never share a code path — a helper that both call would eventually be
 * relaxed for one of them and quietly weaken the other.
 *
 * So: no address validation here at all, and the safety comes from WHERE the
 * URL is allowed to originate. See AiSettings.
 *
 * WHAT IT DOES NOT DO
 * ───────────────────
 * It does not decide whether AI is enabled, it does not know which model to
 * use, and it never falls back to a different host. Those belong to the caller
 * and to the settings; this is a transport that speaks Ollama's dialect.
 */
final readonly class OllamaClient
{
    /**
     * A generous ceiling, and it is generous on purpose: a first request to a
     * cold model makes the host load several gigabytes off disk before it says
     * anything, and a timeout tuned for a warm host reports that as a failure
     * every time an install is not busy.
     */
    private const float GENERATE_TIMEOUT = 120.0;

    /**
     * Embeddings are small and fast once the model is resident. The same
     * cold-start argument applies, so this is not as tight as it looks.
     */
    private const float EMBED_TIMEOUT = 60.0;

    /**
     * Asking what models a host holds is a health check, and a health check
     * that hangs is worse than one that says no.
     */
    private const float PROBE_TIMEOUT = 5.0;

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Is anything there, and what is it holding?
     *
     * Answers a value object rather than throwing, because every caller is
     * either an administrator pressing a button or a health check — both want
     * the reason, and neither wants an exception.
     */
    public function probe(string $baseUrl): AiProbe
    {
        try {
            $response = $this->http->request('GET', $this->url($baseUrl, '/api/tags'), [
                'timeout' => self::PROBE_TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiProbe::unreachable('status', ['status' => $status]);
            }

            /** @var array{models?: list<array<string,mixed>>} $payload */
            $payload = $response->toArray(false);

            return AiProbe::reachable(self::models($payload), $this->version($baseUrl));
        } catch (HttpClientException $exception) {
            // The ordinary case, and not worth an error: an address typed one
            // digit wrong, or a container that is not up yet.
            return AiProbe::unreachable('unreachable', ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logger->error('OllamaClient: probe failed unexpectedly', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiProbe::unreachable('unreachable', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * One embedding vector for one piece of text.
     *
     * `/api/embed` is the current endpoint and `/api/embeddings` the one older
     * hosts answer. Both are tried, newest first, because an installation this
     * ships to may be running whatever was current when it was set up — and a
     * feature that refuses to work against a year-old Ollama would be reported
     * as broken rather than as out of date.
     *
     * @return list<float>|null null when the host could not answer
     */
    public function embed(string $baseUrl, string $model, string $text): ?array
    {
        $modern = $this->tryEmbed($baseUrl, '/api/embed', [
            'model' => $model,
            'input' => $text,
        ], static fn (array $body): ?array => $body['embeddings'][0] ?? null);

        if (null !== $modern) {
            return $modern;
        }

        return $this->tryEmbed($baseUrl, '/api/embeddings', [
            'model'  => $model,
            'prompt' => $text,
        ], static fn (array $body): ?array => $body['embedding'] ?? null);
    }

    /**
     * A single completion, not streamed.
     *
     * Not streamed deliberately: everything this is used for — a subject line,
     * a suggested reply, a category — is short, wanted whole, and inserted into
     * a form. Streaming would buy a progress illusion in exchange for a second
     * transport and a second failure mode.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function chat(string $baseUrl, string $model, array $messages, ?float $temperature = null): ?string
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ];

        if (null !== $temperature) {
            $payload['options'] = ['temperature' => $temperature];
        }

        try {
            $response = $this->http->request('POST', $this->url($baseUrl, '/api/chat'), [
                'json'    => $payload,
                'timeout' => self::GENERATE_TIMEOUT,
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('OllamaClient: chat refused', [
                    'status' => $response->getStatusCode(),
                    'model'  => $model,
                ]);

                return null;
            }

            /** @var array{message?: array{content?: string}} $body */
            $body    = $response->toArray(false);
            $content = $body['message']['content'] ?? null;

            return true === is_string($content) && '' !== trim($content) ? $content : null;
        } catch (HttpClientException $exception) {
            $this->logger->warning('OllamaClient: chat failed', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        } catch (Throwable $exception) {
            $this->logger->error('OllamaClient: chat failed unexpectedly', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed>                       $payload
     * @param callable(array<string,mixed>): (list<float>|null) $pluck
     *
     * @return list<float>|null
     */
    private function tryEmbed(string $baseUrl, string $path, array $payload, callable $pluck): ?array
    {
        try {
            $response = $this->http->request('POST', $this->url($baseUrl, $path), [
                'json'    => $payload,
                'timeout' => self::EMBED_TIMEOUT,
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            /** @var array<string,mixed> $body */
            $body   = $response->toArray(false);
            $vector = $pluck($body);

            if (false === is_array($vector) || [] === $vector) {
                return null;
            }

            // Ollama answers JSON numbers, which decode to int for a whole
            // value. A mixed int/float list is a valid embedding and an invalid
            // list<float>, and the difference surfaces a long way from here.
            return array_map(floatval(...), array_values($vector));
        } catch (Throwable) {
            // Caller tries the other endpoint, and reports if both fail. A log
            // line per attempt would print one for every embedding on a host
            // that only speaks the older dialect.
            return null;
        }
    }

    private function version(string $baseUrl): ?string
    {
        try {
            $response = $this->http->request('GET', $this->url($baseUrl, '/api/version'), [
                'timeout' => self::PROBE_TIMEOUT,
            ]);

            /** @var array{version?: string} $body */
            $body = $response->toArray(false);

            return $body['version'] ?? null;
        } catch (Throwable) {
            // A host too old to have /api/version still answers /api/tags, and
            // the version is decoration on a screen that has already succeeded.
            return null;
        }
    }

    /**
     * @param array{models?: list<array<string,mixed>>} $payload
     *
     * @return list<OllamaModel>
     */
    private static function models(array $payload): array
    {
        $models = [];

        foreach ($payload['models'] ?? [] as $entry) {
            $name = $entry['name'] ?? null;

            if (false === is_string($name) || '' === $name) {
                continue;
            }

            $details = $entry['details'] ?? [];

            $models[] = new OllamaModel(
                name: $name,
                sizeBytes: true === is_int($entry['size'] ?? null) ? $entry['size'] : null,
                family: true === is_string($details['family'] ?? null) ? $details['family'] : null,
            );
        }

        usort($models, static fn (OllamaModel $a, OllamaModel $b): int => strcmp($a->name, $b->name));

        return $models;
    }

    /** Tolerates a trailing slash, which is what people paste. */
    private function url(string $baseUrl, string $path): string
    {
        return rtrim(trim($baseUrl), '/') . $path;
    }
}
