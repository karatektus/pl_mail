<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\AiEmbedResult;
use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\LoadedModel;
use App\Domain\DTO\Ai\OllamaModel;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
     * The closed set of reasons a call can fail, as recorded.
     *
     * A CATEGORY and never a message. An HTTP client's exception text
     * routinely quotes the request body back, and the request body here is
     * somebody's mail — so the messages keep going to the logger, where they
     * already go, and one of these six strings is all that reaches the metrics
     * table. See AiCallRecorder.
     */
    public const string ERROR_TIMEOUT      = 'timeout';
    public const string ERROR_UNREACHABLE  = 'unreachable';

    /**
     * Its own category rather than one more http_status, because it is the
     * commonest operator mistake and it means two useful things: on /api/chat,
     * the named model was never pulled; on /api/embed, the host predates that
     * endpoint and the older dialect is worth trying.
     */
    public const string ERROR_HTTP_404     = 'http_404';
    public const string ERROR_HTTP_STATUS  = 'http_status';

    /** Answered 200 and said nothing usable. */
    public const string ERROR_BAD_RESPONSE = 'bad_response';
    public const string ERROR_UNEXPECTED   = 'unexpected';

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
     * What the host has in MEMORY right now.
     *
     * Passive, and that is the property that makes it safe to poll: /api/ps
     * reports, it does not load, and it never wakes anything. Asking every few
     * seconds costs the host nothing and does not reset any keep-alive.
     *
     * This is the only way to answer the question the composer needs — "will
     * the next request pay a cold load, and roughly how long is that" — which
     * is the difference between an honest wait and a button that reads as
     * dead. Ollama exposes no queue depth, so this is also as close as anything
     * gets to "am I behind something else".
     *
     * An empty list and an unreachable host are deliberately the same answer
     * here: both mean "nothing is resident", and the caller that cares which
     * one it is asks probe() instead.
     *
     * THE TIMEOUT IS THE CALLER'S TO SHORTEN
     * ──────────────────────────────────────
     * Five seconds is right for the admin panel, which is a page about the host
     * and can afford to wait for it. It is wrong in front of the composer's
     * streamed draft: that probe sits on the interactive path, ahead of the
     * thing the writer is actually waiting for, and its entire product is one
     * sentence of explanation. A probe that costs more than the explanation is
     * worth should give up instead — and giving up returns the empty list,
     * which already means "assume a cold load", which is the safe guess.
     *
     * Null keeps every existing caller on the five seconds they were written
     * for; only the composer passes anything.
     *
     * @return list<LoadedModel>
     */
    public function ps(string $baseUrl, ?float $timeout = null): array
    {
        try {
            $response = $this->http->request('GET', $this->url($baseUrl, '/api/ps'), [
                'timeout' => $timeout ?? self::PROBE_TIMEOUT,
            ]);

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            /** @var array{models?: list<array<string,mixed>>} $payload */
            $payload = $response->toArray(false);

            $loaded = [];

            foreach ($payload['models'] ?? [] as $entry) {
                $name = $entry['name'] ?? $entry['model'] ?? null;

                if (false === is_string($name) || '' === $name) {
                    continue;
                }

                $details = $entry['details'] ?? [];

                $loaded[] = new LoadedModel(
                    name:          $name,
                    sizeBytes:     self::whole($entry['size'] ?? null),
                    sizeVramBytes: self::whole($entry['size_vram'] ?? null),
                    contextLength: self::whole($entry['context_length'] ?? null),
                    expiresAt:     self::moment($entry['expires_at'] ?? null),
                    parameterSize: is_array($details) && is_string($details['parameter_size'] ?? null)
                        ? $details['parameter_size']
                        : null,
                    quantisation:  is_array($details) && is_string($details['quantization_level'] ?? null)
                        ? $details['quantization_level']
                        : null,
                );
            }

            return $loaded;
        } catch (Throwable) {
            // Silent by design. This is polled, and a host that is down would
            // otherwise write a log line every few seconds for as long as it
            // stayed down. probe() is where an operator asks for the reason.
            return [];
        }
    }

    private static function whole(mixed $value): ?int
    {
        if (false === is_int($value)) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }

    /**
     * Ollama stamps expires_at with nanosecond precision, which
     * DateTimeImmutable does not parse. Trimmed to microseconds rather than
     * refused: the field is a countdown shown to the nearest second.
     */
    private static function moment(mixed $value): ?DateTimeImmutable
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        $trimmed = preg_replace('/(\.\d{6})\d+/', '$1', $value);

        try {
            return new DateTimeImmutable(is_string($trimmed) ? $trimmed : $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Is anything there, and what is it holding?
     *
     * Answers a value object rather than throwing, because every caller is
     * either an administrator pressing a button or a health check — both want
     * the reason, and neither wants an exception.
     *
     * The timeout is the caller's to shorten, for the reason ps() gives and one
     * more of its own: the admin panel POLLS this, and a host that has gone
     * away without refusing the connection — a machine that is off, an address
     * with no route — holds the socket open for the full budget. At five
     * seconds a five-second poll never finishes before the next one starts.
     * The panel passes something short so "not reachable" renders promptly,
     * which is the answer it is going to give anyway.
     */
    public function probe(string $baseUrl, ?float $timeout = null): AiProbe
    {
        try {
            $response = $this->http->request('GET', $this->url($baseUrl, '/api/tags'), [
                'timeout' => $timeout ?? self::PROBE_TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiProbe::unreachable('status', ['status' => $status]);
            }

            /** @var array{models?: list<array<string,mixed>>} $payload */
            $payload = $response->toArray(false);

            return AiProbe::reachable(self::models($payload), $this->version($baseUrl, $timeout));
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
     * Both attempts collapse into ONE result, and therefore one recorded call.
     */
    public function embed(string $baseUrl, string $model, string $text): AiEmbedResult
    {
        $modern = $this->tryEmbed($baseUrl, '/api/embed', [
            'model' => $model,
            'input' => $text,
        ], static fn (array $body): ?array => $body['embeddings'][0] ?? null);

        if (true === $modern->succeeded) {
            return $modern;
        }

        $legacy = $this->tryEmbed($baseUrl, '/api/embeddings', [
            'model'  => $model,
            'prompt' => $text,
        ], static fn (array $body): ?array => $body['embedding'] ?? null);

        if (true === $legacy->succeeded) {
            // The older dialect answered, so the embedding worked. Its timings
            // are usually all null — that endpoint reports none — and a row
            // saying "succeeded, nothing measured" is the truth about this host
            // rather than a gap to be filled in with the modern attempt's
            // failure.
            return $legacy;
        }

        // Both failed, so ONE row, not two: a host old enough to need the
        // fallback would otherwise double every call count in the panel, which
        // is exactly the host somebody is most likely to be reading it about.
        //
        // A 404 on /api/embed only means the endpoint is not there, which says
        // nothing about the host. In that case the legacy attempt is the one
        // carrying the real reason.
        if (self::ERROR_HTTP_404 === $modern->errorKind) {
            return $legacy;
        }

        return $modern;
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
    public function chat(string $baseUrl, string $model, array $messages, ?float $temperature = null): AiChatResult
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

            $status = $response->getStatusCode();

            if (200 !== $status) {
                $this->logger->warning('OllamaClient: chat refused', [
                    'status' => $status,
                    'model'  => $model,
                ]);

                return AiChatResult::failed(
                    404 === $status ? self::ERROR_HTTP_404 : self::ERROR_HTTP_STATUS,
                );
            }

            /** @var array{message?: array{content?: string}} $body */
            $body = $response->toArray(false);

            // Read BEFORE the content is judged, so a refusal that still cost
            // thirteen seconds of model load is recorded as having cost it.
            $timing  = AiCallTiming::fromBody($body);
            $content = $body['message']['content'] ?? null;

            if (false === is_string($content) || '' === trim($content)) {
                return AiChatResult::failed(self::ERROR_BAD_RESPONSE, $timing);
            }

            return AiChatResult::ok($content, $timing);
        } catch (TimeoutExceptionInterface $exception) {
            // Before the transport catch below, which it extends. Worth its own
            // category: a host that answers slowly and a host that is not there
            // want opposite things done about them, and collapsing them was
            // most of why "the AI does nothing" had no diagnosis.
            $this->logger->warning('OllamaClient: chat timed out', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_TIMEOUT);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('OllamaClient: chat could not reach the host', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_UNREACHABLE);
        } catch (DecodingExceptionInterface $exception) {
            // 200 with something that is not JSON — a proxy's error page, most
            // often, which is a configuration problem and not a model one.
            $this->logger->warning('OllamaClient: chat answered with something that is not JSON', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_BAD_RESPONSE);
        } catch (HttpClientException $exception) {
            $this->logger->warning('OllamaClient: chat failed', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_UNREACHABLE);
        } catch (Throwable $exception) {
            $this->logger->error('OllamaClient: chat failed unexpectedly', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_UNEXPECTED);
        }
    }

    /**
     * The same completion, token by token.
     *
     * A Generator that YIELDS each token and RETURNS an AiChatResult when the
     * stream ends, so one call gives both the live text and the timing block
     * the metrics table wants. Read the return with `$tokens->getReturn()`
     * after the loop; PHP guarantees it is set once the generator completes.
     *
     * WHY STREAMING EXISTS HERE AT ALL, WHEN chat() DELIBERATELY DOES NOT
     * ────────────────────────────────────────────────────────────────────
     * chat() answers a subject line or a category: short, wanted whole, and
     * inserted into a form, where streaming would buy a progress illusion for
     * a second failure mode. Drafting a reply is the opposite. It is the one
     * place a person sits and watches, it is long enough to be worth watching,
     * and outside the keep-alive window the first thirteen seconds produce
     * nothing at all while a 20 GiB model loads. Tokens arriving ARE the
     * progress indicator for that, and no spinner substitutes for them.
     *
     * CANCELLATION IS THE CALLER'S JOB AND IT MATTERS
     * ───────────────────────────────────────────────
     * Break out of the loop and the response is cancelled, which stops the
     * host generating. An abandoned draft that keeps generating holds a 20 GiB
     * model busy on a single-GPU box and every other feature queues behind it.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return \Generator<int, string, void, AiChatResult>
     */
    public function chatStream(string $baseUrl, string $model, array $messages, ?float $temperature = null): \Generator
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => true,
        ];

        if (null !== $temperature) {
            $payload['options'] = ['temperature' => $temperature];
        }

        $whole  = '';
        $timing = AiCallTiming::none();

        try {
            $response = $this->http->request('POST', $this->url($baseUrl, '/api/chat'), [
                'json'    => $payload,
                'timeout' => self::GENERATE_TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiChatResult::failed(
                    404 === $status ? self::ERROR_HTTP_404 : self::ERROR_HTTP_STATUS,
                );
            }

            // Newline-delimited JSON, one object per token. A chunk off the
            // wire is NOT a line: it can carry half an object, or three of
            // them, so the tail is held back until its newline arrives.
            // Splitting on chunk boundaries instead is the classic way to get
            // a parser that works locally and drops tokens over a real network.
            $buffer = '';

            foreach ($this->http->stream($response) as $chunk) {
                if (true === $chunk->isTimeout()) {
                    return AiChatResult::failed(self::ERROR_TIMEOUT, $timing);
                }

                if (true === $chunk->isLast()) {
                    break;
                }

                $buffer .= $chunk->getContent();

                while (false !== ($break = strpos($buffer, "\n"))) {
                    $line   = trim(substr($buffer, 0, $break));
                    $buffer = substr($buffer, $break + 1);

                    if ('' === $line) {
                        continue;
                    }

                    $object = json_decode($line, true);

                    if (false === is_array($object)) {
                        continue;
                    }

                    $token = $object['message']['content'] ?? null;

                    if (true === is_string($token) && '' !== $token) {
                        $whole .= $token;

                        yield $token;
                    }

                    // The final object carries the whole timing block, and it
                    // is the only one that does.
                    if (true === ($object['done'] ?? false)) {
                        $timing = AiCallTiming::fromBody($object);
                    }
                }
            }
        } catch (TimeoutExceptionInterface) {
            return AiChatResult::failed(self::ERROR_TIMEOUT, $timing);
        } catch (TransportExceptionInterface) {
            return AiChatResult::failed(self::ERROR_UNREACHABLE, $timing);
        } catch (Throwable $exception) {
            $this->logger->error('OllamaClient: streamed chat failed unexpectedly', [
                'model'     => $model,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiChatResult::failed(self::ERROR_UNEXPECTED, $timing);
        }

        if ('' === trim($whole)) {
            return AiChatResult::failed(self::ERROR_BAD_RESPONSE, $timing);
        }

        return AiChatResult::ok($whole, $timing);
    }

    /**
     * @param array<string, mixed>                       $payload
     * @param callable(array<string,mixed>): (list<float>|null) $pluck
     */
    private function tryEmbed(string $baseUrl, string $path, array $payload, callable $pluck): AiEmbedResult
    {
        try {
            $response = $this->http->request('POST', $this->url($baseUrl, $path), [
                'json'    => $payload,
                'timeout' => self::EMBED_TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiEmbedResult::failed(
                    404 === $status ? self::ERROR_HTTP_404 : self::ERROR_HTTP_STATUS,
                );
            }

            /** @var array<string,mixed> $body */
            $body = $response->toArray(false);

            // An embedding has no generation phase, so eval_count and
            // eval_duration are simply absent here and stay null all the way
            // into the column. That absence is why the percentiles filter.
            $timing = AiCallTiming::fromBody($body);
            $vector = $pluck($body);

            if (false === is_array($vector) || [] === $vector) {
                return AiEmbedResult::failed(self::ERROR_BAD_RESPONSE, $timing);
            }

            // Ollama answers JSON numbers, which decode to int for a whole
            // value. A mixed int/float list is a valid embedding and an invalid
            // list<float>, and the difference surfaces a long way from here.
            return AiEmbedResult::ok(array_map(floatval(...), array_values($vector)), $timing);
        } catch (TimeoutExceptionInterface) {
            return AiEmbedResult::failed(self::ERROR_TIMEOUT);
        } catch (TransportExceptionInterface) {
            return AiEmbedResult::failed(self::ERROR_UNREACHABLE);
        } catch (DecodingExceptionInterface) {
            return AiEmbedResult::failed(self::ERROR_BAD_RESPONSE);
        } catch (Throwable) {
            // Still silent, and still for the original reason: the caller tries
            // the other endpoint and reports only if both fail, so a log line
            // per attempt would print one for every embedding on a host that
            // speaks only the older dialect. The CATEGORY is carried out in the
            // result instead, which is the part that was missing — it costs
            // nothing and it is what the panel reads.
            return AiEmbedResult::failed(self::ERROR_UNEXPECTED);
        }
    }

    private function version(string $baseUrl, ?float $timeout = null): ?string
    {
        try {
            $response = $this->http->request('GET', $this->url($baseUrl, '/api/version'), [
                // The caller's budget, not a second full one: this runs after
                // /api/tags has already answered, so a probe given two seconds
                // must not be able to spend seven.
                'timeout' => $timeout ?? self::PROBE_TIMEOUT,
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
