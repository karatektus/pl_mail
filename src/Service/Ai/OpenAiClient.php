<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\OllamaModel;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;

/**
 * OpenAI-compatible text generation, with SSE translated to the application's
 * token stream. Embeddings deliberately stay on Ollama: a provider switch must
 * never mix stored vectors. Upstream bodies and exception messages may contain
 * credentials or mail, so failures expose only the existing error categories.
 */
final readonly class OpenAiClient
{
    public function __construct(private HttpClientInterface $http) {}

    /** @param list<array{role: string, content: string}> $messages */
    public function chat(string $baseUrl, string $model, array $messages, ?float $temperature = null, ?string $key = null): AiChatResult
    {
        try {
            $response = $this->http->request('POST', $this->url($baseUrl, '/chat/completions'), $this->options($key, $this->payload($model, $messages, $temperature, false)));
            if (200 !== $response->getStatusCode()) {
                return AiChatResult::failed(OllamaClient::ERROR_HTTP_STATUS);
            }
            $body = $response->toArray(false);
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (!is_string($content)) {
                return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
            }

            return AiChatResult::ok($content, $this->timing($body));
        } catch (TimeoutExceptionInterface) {
            return AiChatResult::failed(OllamaClient::ERROR_TIMEOUT);
        } catch (DecodingExceptionInterface) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
        } catch (\Throwable) {
            return AiChatResult::failed(OllamaClient::ERROR_UNREACHABLE);
        }
    }

    /**
     * SSE boundaries need not coincide with network chunks. Buffer complete
     * events, accepting CRLF and multiple data lines, and require [DONE] so a
     * truncated draft cannot be recorded as successful. Destruction cancels the
     * response, including when the caller stops between tokens.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return \Generator<int, string, void, AiChatResult>
     */
    public function chatStream(string $baseUrl, string $model, array $messages, ?float $temperature = null, ?string $key = null, ?float $timeout = null): \Generator
    {
        $response = null;
        $content = '';
        $timing = AiCallTiming::none();
        $buffer = '';
        try {
            $options = $this->options($key, $this->payload($model, $messages, $temperature, true));
            $options['max_duration'] = $timeout ?? 180.0;
            $response = $this->http->request('POST', $this->url($baseUrl, '/chat/completions'), $options);
            if (200 !== $response->getStatusCode()) {
                return AiChatResult::failed(OllamaClient::ERROR_HTTP_STATUS);
            }
            foreach ($this->http->stream($response, 1.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    yield ''; // Existing application heartbeat contract.
                    continue;
                }
                $buffer .= $chunk->getContent();
                while (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
                    $offset = $match[0][1];
                    $event = substr($buffer, 0, $offset);
                    $buffer = substr($buffer, $offset + strlen($match[0][0]));
                    $data = [];
                    foreach (preg_split('/\r?\n/', $event) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $data[] = ltrim(substr($line, 5), ' ');
                        }
                    }
                    if ([] === $data) {
                        continue;
                    }
                    $json = implode("\n", $data);
                    if ('[DONE]' === $json) {
                        return AiChatResult::ok($content, $timing);
                    }
                    $body = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($body) || isset($body['error'])) {
                        return AiChatResult::failed(OllamaClient::ERROR_HTTP_STATUS);
                    }
                    if (isset($body['usage'])) {
                        $timing = $this->timing($body);
                    }
                    $token = $body['choices'][0]['delta']['content'] ?? null;
                    if (null !== $token && !is_string($token)) {
                        return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
                    }
                    if (is_string($token) && '' !== $token) {
                        $content .= $token;
                        yield $token;
                    }
                }
                if (strlen($buffer) > 1_048_576) {
                    return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
                }
            }

            return AiChatResult::failed(OllamaClient::ERROR_HTTP_STATUS);
        } catch (\JsonException) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
        } catch (TimeoutExceptionInterface) {
            return AiChatResult::failed(OllamaClient::ERROR_TIMEOUT);
        } catch (DecodingExceptionInterface) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE);
        } catch (\Throwable) {
            return AiChatResult::failed(OllamaClient::ERROR_UNREACHABLE);
        } finally {
            $response?->cancel();
        }
    }

    /** Optional discovery; generation itself never depends on /models. */
    public function probe(string $baseUrl, ?string $key = null, float $timeout = 2.5): AiProbe
    {
        try {
            $options = $this->options($key);
            $options['max_duration'] = $timeout;
            $response = $this->http->request('GET', $this->url($baseUrl, '/models'), $options);
            if (200 !== $response->getStatusCode()) {
                return AiProbe::unreachable('status', ['status' => $response->getStatusCode()]);
            }
            $models = [];
            foreach ($response->toArray(false)['data'] ?? [] as $entry) {
                if (is_string($entry['id'] ?? null)) {
                    $models[] = new OllamaModel($entry['id']);
                }
            }

            return AiProbe::reachable($models, 'OpenAI-compatible');
        } catch (\Throwable) {
            return AiProbe::unreachable('unreachable');
        }
    }

    private function url(string $baseUrl, string $path): string
    {
        if (!preg_match('~^https?://[^\s]+$~i', $baseUrl)) {
            throw new \InvalidArgumentException('Invalid AI endpoint');
        }
        $parts = parse_url($baseUrl);
        if (false === $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid AI endpoint');
        }

        return rtrim($baseUrl, '/') . $path;
    }

    /** @return array<string, mixed> */
    private function options(?string $key, ?array $payload = null): array
    {
        $options = ['timeout' => 30.0, 'max_duration' => 180.0, 'max_redirects' => 0];
        if (null !== $key && '' !== trim($key)) {
            $options['auth_bearer'] = $key;
        }
        if (null !== $payload) {
            $options['json'] = $payload;
        }

        return $options;
    }

    /** @param list<array{role: string, content: string}> $messages
     *  @return array<string, mixed>
     */
    private function payload(string $model, array $messages, ?float $temperature, bool $stream): array
    {
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => $stream];
        if (null !== $temperature) {
            $payload['temperature'] = $temperature;
        }

        return $payload;
    }

    /** @param array<string, mixed> $body */
    private function timing(array $body): AiCallTiming
    {
        $usage = $body['usage'] ?? [];

        return new AiCallTiming(
            promptTokens: is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null,
            evalTokens: is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null,
        );
    }
}
