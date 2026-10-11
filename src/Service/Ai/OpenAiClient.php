<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\AiConnection;
use App\Domain\DTO\Ai\AiEmbedResult;
use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\OllamaModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Talks to anything that speaks the OpenAI request format.
 *
 * The second dialect, beside {@see OllamaClient}. Three requests and no more:
 * `/chat/completions`, `/embeddings`, and `/models` for the Test button. That
 * is the part of the format everything implements — llama.cpp's server, vLLM,
 * LM Studio, LiteLLM, the hosted providers, Ollama itself under `/v1` — and a
 * client that reached for anything further would work against one of them and
 * be reported as broken by the rest.
 *
 * THE SAME ANSWERS AS THE OTHER CLIENT
 * ────────────────────────────────────
 * The same value objects and the same six error categories, on purpose.
 * {@see AiAssistant} chooses between the two clients and nothing above it knows
 * there are two: a summary that timed out is a summary that timed out, and the
 * metrics table has one column for why.
 *
 * WHAT IS NOT SENT
 * ────────────────
 * Nothing the format does not define. No `keep_alive`, no `num_ctx`, no
 * `stream_options` — several servers validate the body strictly and answer 400
 * or 422 to a field they do not know, and a feature that works on four
 * endpoints and fails on the fifth with "Extra inputs are not permitted" is
 * not worth a token count. Where a server volunteers its usage the numbers are
 * kept; where it does not they stay null, which the panel already knows how to
 * show.
 *
 * REDIRECTS ARE NEVER FOLLOWED
 * ────────────────────────────
 * Every request here carries a key far more often than not, and it carries
 * somebody's mail. A 301 names a different address, and neither was meant for
 * it. The redirect comes back as the status it is.
 *
 * THE ADDRESS IS NOT VALIDATED, for the reason OllamaClient gives at length: it
 * is typed by an administrator and stored as configuration, and a private
 * address is as legitimate here as there — the commonest OpenAI-compatible
 * server is one on the operator's own network.
 */
final readonly class OpenAiClient
{
    /** {@see OllamaClient::HEARTBEAT_SECONDS}, and for the same proxy in front of this one. */
    private const float HEARTBEAT_SECONDS = 10.0;

    /** An embedding is one short round trip. A minute covers a server that has to load the model first. */
    private const float EMBED_TIMEOUT = 60.0;

    /** A health check that hangs is worse than one that says no. */
    private const float PROBE_TIMEOUT = 5.0;

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Is anything there, and which models does it offer?
     *
     * `/models` is the one listing the format has. Not every server implements
     * it, and one that does not can still write perfectly well — so a 404 is
     * its own answer rather than "unreachable", and says both things it might
     * mean: the address is missing its `/v1`, or this server has no list.
     *
     * 401 and 403 are separated out for the same reason OllamaClient separates
     * 404: it is the mistake an administrator can fix from where they are
     * standing. The address is right, something answered, and the key in the
     * field beside it is the thing to look at.
     */
    public function probe(AiConnection $connection, ?float $timeout = null): AiProbe
    {
        try {
            $response = $this->http->request('GET', self::url($connection, '/models'), self::options($connection, [
                'timeout'      => $timeout ?? self::PROBE_TIMEOUT,
                // The idle timeout does not bound a server that answers one
                // byte at a time, and this is polled by the panel.
                'max_duration' => $timeout ?? self::PROBE_TIMEOUT,
            ]));

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiProbe::unreachable(match ($status) {
                    401, 403 => 'refused',
                    404      => 'no_model_list',
                    default  => 'status',
                }, ['status' => $status]);
            }

            /** @var array{data?: mixed} $payload */
            $payload = $response->toArray(false);

            return AiProbe::reachable(self::models($payload));
        } catch (DecodingExceptionInterface) {
            // 200 and not JSON: a login page, or a proxy's own answer. Nothing
            // behind that address speaks this format.
            return AiProbe::unreachable('not_an_api');
        } catch (HttpClientException $exception) {
            return AiProbe::unreachable('unreachable', ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logger->error('OpenAiClient: probe failed unexpectedly', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return AiProbe::unreachable('unreachable', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * One embedding vector for one piece of text.
     *
     * One input, one vector. The format accepts a list and answers a list, and
     * a server that answers anything other than exactly one entry for exactly
     * one input has not done what was asked — taking the first of several would
     * store a vector against a message it may not belong to.
     */
    public function embed(AiConnection $connection, string $model, string $text): AiEmbedResult
    {
        $started = microtime(true);

        try {
            $response = $this->http->request('POST', self::url($connection, '/embeddings'), self::options($connection, [
                'json'    => ['model' => $model, 'input' => $this->utf8($text, $model)],
                'timeout' => self::EMBED_TIMEOUT,
            ]));

            $status = $response->getStatusCode();

            if (200 !== $status) {
                return AiEmbedResult::failed(
                    404 === $status ? OllamaClient::ERROR_HTTP_404 : OllamaClient::ERROR_HTTP_STATUS,
                );
            }

            /** @var array<string, mixed> $body */
            $body   = $response->toArray(false);
            $timing = self::timing($body, $started);
            $data   = $body['data'] ?? null;

            if (false === is_array($data) || 1 !== count($data)) {
                return AiEmbedResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $timing);
            }

            $vector = reset($data)['embedding'] ?? null;

            if (false === is_array($vector) || [] === $vector) {
                return AiEmbedResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $timing);
            }

            foreach ($vector as $component) {
                // A string here is `encoding_format: base64` answered by a
                // server that defaults to it, and floatval() of that is 0.0 —
                // a vector of zeros that stores without complaint and matches
                // nothing, forever.
                if (false === is_int($component) && false === is_float($component)) {
                    return AiEmbedResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $timing);
                }
            }

            return AiEmbedResult::ok(array_map(floatval(...), array_values($vector)), $timing);
        } catch (TimeoutExceptionInterface) {
            return AiEmbedResult::failed(OllamaClient::ERROR_TIMEOUT);
        } catch (TransportExceptionInterface) {
            return AiEmbedResult::failed(OllamaClient::ERROR_UNREACHABLE);
        } catch (DecodingExceptionInterface) {
            return AiEmbedResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
        } catch (Throwable $exception) {
            // Said once, where OllamaClient stays silent: there is no second
            // dialect to try here, so this is the only attempt and the only
            // place the reason exists. The message is the transport's own and
            // quotes no request body.
            $this->logger->error('OpenAiClient: embedding failed unexpectedly', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiEmbedResult::failed(OllamaClient::ERROR_UNEXPECTED);
        }
    }

    /**
     * A single completion, not streamed. {@see OllamaClient::chat()} for why
     * the short answers are asked for whole.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function chat(AiConnection $connection, string $model, array $messages, ?float $temperature = null): AiChatResult
    {
        $started = microtime(true);

        try {
            $response = $this->http->request('POST', self::url($connection, '/chat/completions'), self::options($connection, [
                'json'    => $this->payload($model, $messages, $temperature, false),
                'timeout' => OllamaClient::GENERATE_TIMEOUT,
            ]));

            $status = $response->getStatusCode();

            if (200 !== $status) {
                $this->logger->warning('OpenAiClient: chat refused', [
                    'status' => $status,
                    'model'  => $model,
                    'error'  => self::refusal($response->getContent(false)),
                ]);

                return AiChatResult::failed(
                    404 === $status ? OllamaClient::ERROR_HTTP_404 : OllamaClient::ERROR_HTTP_STATUS,
                );
            }

            /** @var array<string, mixed> $body */
            $body    = $response->toArray(false);
            $timing  = self::timing($body, $started);
            $content = $body['choices'][0]['message']['content'] ?? null;

            if (false === is_string($content) || '' === trim($content)) {
                return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $timing);
            }

            return AiChatResult::ok($content, $timing);
        } catch (TimeoutExceptionInterface $exception) {
            $this->logger->warning('OpenAiClient: chat timed out', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_TIMEOUT);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('OpenAiClient: chat could not reach the endpoint', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_UNREACHABLE);
        } catch (DecodingExceptionInterface $exception) {
            $this->logger->warning('OpenAiClient: chat answered with something that is not JSON', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
        } catch (Throwable $exception) {
            $this->logger->error('OpenAiClient: chat failed unexpectedly', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_UNEXPECTED);
        }
    }

    /**
     * The same completion, token by token.
     *
     * The contract is {@see OllamaClient::chatStream()}'s exactly — a Generator
     * that yields each token, yields an empty string as a heartbeat while the
     * endpoint is silent, and returns an AiChatResult — because the controllers
     * that turn it into frames were written against that one and must not have
     * to know which client they are holding.
     *
     * SERVER-SENT EVENTS, READ A LINE AT A TIME
     * ─────────────────────────────────────────
     * Each token arrives as `data: {json}` and the stream ends with
     * `data: [DONE]`. A chunk off the wire is not a line — it can hold half an
     * event or three — so the tail is held back until its newline arrives, the
     * same rule the other client follows for its newline-delimited JSON.
     *
     * Anything that is not a `data:` line is skipped rather than refused. The
     * format allows comments, and at least one widely used router sends them as
     * keep-alives while a model is being found; treating those as a broken
     * stream would fail exactly the requests that were merely slow to start.
     *
     * [DONE] IS NOT REQUIRED. A stream that ends with text and no terminator is
     * judged by its text, as the other client judges one with no final object.
     * Requiring it would discard a whole draft for want of one line that a
     * handful of servers have been known to leave out.
     *
     * AN ERROR CAN ARRIVE INSIDE A 200. A provider that fails mid-answer has
     * already sent its headers, so the failure comes as an event with an
     * `error` object in it. Logged and returned as a failure — the alternative
     * is recording half a sentence as a successful draft.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return \Generator<int, string, void, AiChatResult>
     */
    public function chatStream(AiConnection $connection, string $model, array $messages, ?float $temperature = null, ?float $timeout = null): \Generator
    {
        $whole = '';
        $usage = [];

        // Above the try, for the reason the other client gives: the catch
        // blocks have to be able to say how long they had been waiting.
        $budget     = $timeout ?? OllamaClient::GENERATE_TIMEOUT;
        $started    = microtime(true);
        $firstToken = null;

        try {
            $response = $this->http->request('POST', self::url($connection, '/chat/completions'), self::options($connection, [
                'json'    => $this->payload($model, $messages, $temperature, true),
                // How often this wakes up, not how long the silence may last.
                // The budget is counted below.
                'timeout' => self::HEARTBEAT_SECONDS,
            ]));

            $buffer = '';

            // Two loops, and the outer one is what waits: stream() ends when it
            // yields a timeout chunk, and calling it again resumes the same
            // response. OllamaClient::chatStream() has the long version.
            while (true) {
                $timedOut = false;

                foreach ($this->http->stream($response) as $chunk) {
                    if (true === $chunk->isTimeout()) {
                        $timedOut = true;

                        break;
                    }

                    // Read here and not before the loop: getStatusCode() blocks
                    // until the headers arrive, and a slow endpoint sends none
                    // until it starts generating.
                    if (true === $chunk->isFirst()) {
                        $status = $response->getStatusCode();

                        if (200 !== $status) {
                            // The answer is quotable and the request is not —
                            // the request is somebody's conversation.
                            $this->logger->error('OpenAiClient: streamed chat refused', [
                                'model'  => $model,
                                'status' => $status,
                                'error'  => self::refusal($response->getContent(false)),
                            ]);

                            return AiChatResult::failed(
                                404 === $status ? OllamaClient::ERROR_HTTP_404 : OllamaClient::ERROR_HTTP_STATUS,
                            );
                        }

                        continue;
                    }

                    if (true === $chunk->isLast()) {
                        break 2;
                    }

                    $buffer .= $chunk->getContent();

                    while (false !== ($break = strpos($buffer, "\n"))) {
                        $line   = trim(substr($buffer, 0, $break));
                        $buffer = substr($buffer, $break + 1);

                        if (false === str_starts_with($line, 'data:')) {
                            continue;
                        }

                        $data = trim(substr($line, 5));

                        if ('[DONE]' === $data) {
                            break 3;
                        }

                        $event = json_decode($data, true);

                        if (false === is_array($event)) {
                            continue;
                        }

                        if (true === isset($event['error'])) {
                            $this->logger->error('OpenAiClient: the endpoint failed part-way through an answer', [
                                'model'        => $model,
                                'chars_so_far' => mb_strlen($whole),
                                'error'        => self::refusal($data),
                            ]);

                            return AiChatResult::failed(
                                OllamaClient::ERROR_HTTP_STATUS,
                                self::streamTiming($usage, $started, $firstToken),
                            );
                        }

                        // Sent by the servers that send it at all on the last
                        // event, sometimes with an empty `choices` beside it.
                        if (true === is_array($event['usage'] ?? null)) {
                            $usage = $event['usage'];
                        }

                        $token = $event['choices'][0]['delta']['content'] ?? null;

                        if (true === is_string($token) && '' !== $token) {
                            $firstToken ??= microtime(true);
                            $whole       .= $token;

                            yield $token;
                        }
                    }
                }

                if (false === $timedOut) {
                    break;
                }

                $waited = microtime(true) - $started;

                if ($waited > $budget) {
                    $this->logger->error('OpenAiClient: gave up waiting for a streamed answer', [
                        'model'          => $model,
                        'waited_seconds' => round($waited),
                        'budget_seconds' => round($budget),
                        'chars_so_far'   => mb_strlen($whole),
                    ]);

                    return AiChatResult::failed(
                        OllamaClient::ERROR_TIMEOUT,
                        self::streamTiming($usage, $started, $firstToken),
                    );
                }

                yield '';
            }
        } catch (TimeoutExceptionInterface $exception) {
            $this->logger->error('OpenAiClient: a streamed chat timed out below the budget', [
                'model'          => $model,
                'waited_seconds' => round(microtime(true) - $started),
                'budget_seconds' => round($budget),
                'error'          => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_TIMEOUT, self::streamTiming($usage, $started, $firstToken));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('OpenAiClient: a streamed chat lost its connection', [
                'model'          => $model,
                'waited_seconds' => round(microtime(true) - $started),
                'chars_so_far'   => mb_strlen($whole),
                'error'          => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_UNREACHABLE, self::streamTiming($usage, $started, $firstToken));
        } catch (Throwable $exception) {
            $this->logger->error('OpenAiClient: streamed chat failed unexpectedly', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return AiChatResult::failed(OllamaClient::ERROR_UNEXPECTED, self::streamTiming($usage, $started, $firstToken));
        }

        $timing = self::streamTiming($usage, $started, $firstToken);

        if ('' === trim($whole)) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $timing);
        }

        return AiChatResult::ok($whole, $timing);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function payload(string $model, array $messages, ?float $temperature, bool $stream): array
    {
        $payload = [
            'model'    => $model,
            'messages' => array_map(
                fn (array $message): array => [
                    'role'    => $message['role'],
                    'content' => $this->utf8($message['content'], $model),
                ],
                $messages,
            ),
            'stream'   => $stream,
        ];

        if (null !== $temperature) {
            $payload['temperature'] = $temperature;
        }

        return $payload;
    }

    /**
     * What every request to this connection is sent with.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function options(AiConnection $connection, array $options): array
    {
        return [
            ...$options,
            'headers'       => $connection->requestHeaders(),
            'max_redirects' => 0,
        ];
    }

    /**
     * The address as typed, with the path on the end. The address already
     * carries its version segment — `…/v1` — because not every endpoint calls
     * it that, and guessing one on would break the ones that do not.
     */
    private static function url(AiConnection $connection, string $path): string
    {
        return rtrim(trim($connection->baseUrl), '/') . $path;
    }

    /**
     * The usage block as a timing, with the wall clock for a total.
     *
     * The format reports how many tokens and never how long, so the only
     * duration there is to record is the one measured from here. That is the
     * round trip rather than the model's own figure — it includes the network
     * and whatever queue the provider keeps — and for a hosted endpoint it is
     * the number that matters: it is what the reader waited.
     *
     * @param array<string, mixed> $body
     */
    private static function timing(array $body, float $started): AiCallTiming
    {
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiCallTiming(
            promptTokens:    self::whole($usage['prompt_tokens'] ?? null),
            evalTokens:      self::whole($usage['completion_tokens'] ?? null),
            totalDurationNs: self::nanoseconds(microtime(true) - $started),
        );
    }

    /**
     * The same, for a stream — which can also say how long the writing took.
     *
     * From the first token to now is the generation, and with a token count
     * beside it that is a tokens-per-second figure the panel can show. With no
     * count there is nothing to divide, so the duration is left out too rather
     * than recorded beside a null.
     *
     * @param array<string, mixed> $usage
     */
    private static function streamTiming(array $usage, float $started, ?float $firstToken): AiCallTiming
    {
        $now        = microtime(true);
        $evalTokens = self::whole($usage['completion_tokens'] ?? null);

        return new AiCallTiming(
            promptTokens:    self::whole($usage['prompt_tokens'] ?? null),
            evalTokens:      $evalTokens,
            evalDurationNs:  null !== $evalTokens && null !== $firstToken ? self::nanoseconds($now - $firstToken) : null,
            totalDurationNs: self::nanoseconds($now - $started),
        );
    }

    private static function whole(mixed $value): ?int
    {
        if (false === is_int($value)) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }

    private static function nanoseconds(float $seconds): int
    {
        return (int) round(max(0.0, $seconds) * 1_000_000_000);
    }

    /**
     * What the endpoint said when it refused, short enough to log.
     *
     * The format's errors are `{"error": {"message": "…"}}`; a few servers send
     * the bare string instead. The raw body is the fallback for a proxy
     * answering its own HTML, clipped because that can be a whole page.
     */
    private static function refusal(string $body): string
    {
        $decoded = json_decode($body, true);
        $error   = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (true === is_array($error) && true === is_string($error['message'] ?? null)) {
            return mb_substr($error['message'], 0, 200);
        }

        if (true === is_string($error)) {
            return mb_substr($error, 0, 200);
        }

        return mb_substr(trim($body), 0, 200);
    }

    /**
     * @param array{data?: mixed} $payload
     *
     * @return list<OllamaModel>
     */
    private static function models(array $payload): array
    {
        $models = [];

        foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (false === is_string($id) || '' === $id) {
                continue;
            }

            $models[] = new OllamaModel(name: $id);
        }

        usort($models, static fn (OllamaModel $a, OllamaModel $b): int => strcmp($a->name, $b->name));

        return $models;
    }

    /**
     * Mail, made encodable. {@see OllamaClient::utf8()} is the long account of
     * why: Symfony's `json` option refuses malformed UTF-8 before the request
     * is made, one stray byte is enough, and mail is full of them.
     */
    private function utf8(string $text, string $model): string
    {
        if (true === mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $scrubbed = mb_scrub($text, 'UTF-8');

        $this->logger->warning('OpenAiClient: repaired malformed UTF-8 before sending', [
            'model' => $model,
            'bytes' => strlen($text) - strlen($scrubbed),
        ]);

        return $scrubbed;
    }
}
