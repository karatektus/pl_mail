<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The dialect, and the ways a host on somebody's LAN says no.
 *
 * Every test here is against a mock transport rather than a real Ollama,
 * because what is being pinned is the shape of the conversation — which
 * endpoint, which keys, what happens when one of them is not there. A real host
 * would make these tests depend on which version somebody happened to install.
 */
final class OllamaClientTest extends TestCase
{
    public function testAProbeListsTheModelsTheHostHolds(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            if (true === str_contains($url, '/api/tags')) {
                return new MockResponse(json_encode([
                    'models' => [
                        ['name' => 'nomic-embed-text:latest', 'size' => 274_000_000],
                        ['name' => 'llama3.1:8b', 'size' => 4_700_000_000, 'details' => ['family' => 'llama']],
                    ],
                ]), ['response_headers' => ['content-type' => 'application/json']]);
            }

            return new MockResponse(json_encode(['version' => '0.5.4']));
        });

        $probe = $client->probe('http://10.0.0.5:11434');

        self::assertTrue($probe->reachable);
        self::assertSame('0.5.4', $probe->version);
        self::assertCount(2, $probe->models);
        // Sorted, so the dropdown is not in whatever order the host felt like.
        self::assertSame('llama3.1:8b', $probe->models[0]->name);
    }

    /**
     * A trailing slash is what people paste out of a browser bar, and it must
     * not become a double slash the host answers 404 to.
     */
    public function testATrailingSlashOnTheBaseUrlIsTolerated(): void
    {
        $seen = [];

        $client = $this->client(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $url;

            return new MockResponse(json_encode(['models' => []]));
        });

        $client->probe('http://10.0.0.5:11434/');

        self::assertStringContainsString('http://10.0.0.5:11434/api/tags', $seen[0]);
        self::assertStringNotContainsString('11434//api', $seen[0]);
    }

    /**
     * "Nothing answered" and "something answered badly" are different problems
     * for whoever typed the address, so they are different reasons.
     */
    public function testAnUnreachableHostSaysSoRatherThanThrowing(): void
    {
        $client = $this->client(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $probe = $client->probe('http://10.0.0.5:11434');

        self::assertFalse($probe->reachable);
        self::assertSame('unreachable', $probe->reason);
    }

    public function testAHostAnsweringWithAnErrorReportsItsStatus(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('nope', ['http_code' => 403]));

        $probe = $client->probe('http://10.0.0.5:11434');

        self::assertFalse($probe->reachable);
        self::assertSame('status', $probe->reason);
        self::assertSame(403, $probe->reasonParams['status']);
    }

    /** The current endpoint, which answers a list of vectors. */
    public function testEmbeddingUsesTheModernEndpointWhenItAnswers(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            self::assertStringContainsString('/api/embed', $url);

            return new MockResponse(json_encode(['embeddings' => [[0.5, 1, -0.25]]]));
        });

        self::assertSame([0.5, 1.0, -0.25], $client->embed('http://h:11434', 'nomic-embed-text', 'hello')->vector);
    }

    /**
     * An older host has no /api/embed and answers a single vector under a
     * different key. Refusing to work against it would be reported as broken
     * rather than as out of date.
     */
    public function testEmbeddingFallsBackToTheOlderEndpoint(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            if (true === str_contains($url, '/api/embed') && false === str_contains($url, '/api/embeddings')) {
                return new MockResponse('not found', ['http_code' => 404]);
            }

            return new MockResponse(json_encode(['embedding' => [1, 2, 3]]));
        });

        self::assertSame([1.0, 2.0, 3.0], $client->embed('http://h:11434', 'nomic-embed-text', 'hello')->vector);
    }

    /**
     * Whole numbers decode from JSON as int. A mixed int/float list is a valid
     * embedding and an invalid list<float>, and the difference would surface a
     * long way from here.
     */
    public function testEveryComponentComesBackAsAFloat(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse(json_encode(['embeddings' => [[1, 2, 3]]])));

        foreach ((array) $client->embed('http://h:11434', 'm', 't')->vector as $component) {
            self::assertIsFloat($component);
        }
    }

    public function testEmbeddingGivesUpQuietlyWhenNeitherEndpointAnswers(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('no', ['http_code' => 500]));

        $result = $client->embed('http://h:11434', 'm', 't');

        self::assertNull($result->vector);
        self::assertFalse($result->succeeded);

        // ONE result for two attempts, carrying the reason the informative
        // attempt gave. Two rows for one logical embedding would double every
        // call count on a host old enough to need the fallback.
        self::assertSame(OllamaClient::ERROR_HTTP_STATUS, $result->errorKind);
    }

    public function testChatReturnsTheMessageContent(): void
    {
        $client = $this->client(static function (string $method, string $url, array $options): MockResponse {
            self::assertStringContainsString('/api/chat', $url);

            $body = json_decode($options['body'] ?? '{}', true);

            self::assertFalse($body['stream'], 'these answers are short and wanted whole');

            return new MockResponse(json_encode(['message' => ['content' => 'A subject line']]));
        });

        self::assertSame(
            'A subject line',
            $client->chat('http://h:11434', 'llama3.1', [['role' => 'user', 'content' => 'hi']])->content,
        );
    }

    /** An empty completion is a failure, not an answer to paste into a draft. */
    public function testAnEmptyCompletionIsTreatedAsNoAnswer(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse(json_encode(['message' => ['content' => '   ']])));

        $result = $client->chat('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertNull($result->content);
        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
    }

    public function testChatSurvivesAHostThatDropsTheConnection(): void
    {
        $client = $this->client(static function (): MockResponse {
            throw new TransportException('Connection reset by peer');
        });

        $result = $client->chat('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertNull($result->content);

        // Categorised, not merely null. "The host is not there" and "the host
        // took too long" want opposite things done about them, and collapsing
        // them was most of why a broken model host had no diagnosis.
        self::assertSame(OllamaClient::ERROR_UNREACHABLE, $result->errorKind);
    }

    private function client(callable $handler): OllamaClient
    {
        return new OllamaClient(new MockHttpClient($handler), new NullLogger());
    }
}
