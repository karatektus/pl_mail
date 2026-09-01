<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Ai\KeepAlive;
use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * How long a model stays loaded, and what that looks like on the wire.
 *
 * Against a mock transport for OllamaClientTest's reason — what is being pinned
 * is the shape of the request, not any particular host's behaviour.
 *
 * THE FIRST TEST IS THE ONE WORTH HAVING
 * ──────────────────────────────────────
 * `-1` sent as the JSON string "-1" and `-1` sent as the JSON number -1 look
 * identical in the settings form and are not the same thing at all: Ollama
 * parses a string as a Go duration, a Go duration must carry a unit, and "-1"
 * has none — so the string form is a 400 on every model call, from a setting
 * that reads as perfectly correct. Nothing else in the system can catch that.
 */
final class OllamaKeepAliveTest extends TestCase
{
    /**
     * A number goes as a number and a duration goes as a string.
     */
    public function testTheNumericSpellingsGoOnTheWireAsNumbers(): void
    {
        self::assertSame(-1, KeepAlive::forBody('-1'));
        self::assertSame(3600, KeepAlive::forBody('3600'));
        self::assertSame('30m', KeepAlive::forBody('30m'));
    }

    /**
     * Empty means SAY NOTHING, and null in the JSON is not saying nothing —
     * Ollama reads it as a zero duration and unloads the model immediately,
     * which is the opposite of what an operator deferring to their host meant.
     */
    public function testAnEmptySettingSendsNoFieldAtAll(): void
    {
        $body = null;

        $client = $this->client(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['message' => ['content' => 'hello']]));
        });

        $client->chat('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']], null, '');

        self::assertIsArray($body);
        self::assertArrayNotHasKey('keep_alive', $body);
    }

    /**
     * And the embedding path carries its own, which is a different column.
     */
    public function testTheEmbeddingCallCarriesTheSearchModelsSetting(): void
    {
        $body = null;

        $client = $this->client(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['embeddings' => [[0.1, 0.2]]]));
        });

        $client->embed('http://h:11434', 'nomic-embed-text', 'some mail', '-1');

        self::assertIsArray($body);
        self::assertSame(-1, $body['keep_alive']);
    }

    /**
     * The preload posts a model and NO prompt, which is what makes it a load
     * rather than a generation.
     */
    public function testWarmingUpAsksForAModelAndNoPrompt(): void
    {
        $body = null;
        $seen = null;

        $client = $this->client(static function (string $method, string $url, array $options) use (&$body, &$seen): MockResponse {
            $seen = $url;
            $body = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['done' => true]));
        });

        $result = $client->preload('http://h:11434', 'qwen3:30b', '5m');

        self::assertTrue($result->loaded);
        self::assertStringContainsString('/api/generate', (string) $seen);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('prompt', $body);
        self::assertSame('5m', $body['keep_alive']);
    }

    /**
     * "The host is not there" and "the host is there and has never heard of
     * that model" send an administrator to two different fields, so they are
     * two different answers.
     */
    public function testAMissingModelIsNotTheSameAnswerAsAMissingHost(): void
    {
        $refused = $this->client(static fn (): MockResponse => new MockResponse(
            json_encode(['error' => "model 'qwen3:30b' not found"]),
            ['http_code' => 404],
        ));

        $missing = $refused->preload('http://h:11434', 'qwen3:30b');

        self::assertFalse($missing->loaded);
        self::assertSame('model_missing', $missing->reason);
        // The host's own sentence, because it names the model as the host read
        // it — which is how a trailing space or a wrong tag gets found.
        self::assertSame("model 'qwen3:30b' not found", $missing->reasonParams['error']);

        $down = $this->client(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        self::assertSame('unreachable', $down->preload('http://h:11434', 'qwen3:30b')->reason);
    }

    private function client(callable $handler): OllamaClient
    {
        return new OllamaClient(new MockHttpClient($handler), new NullLogger());
    }
}
