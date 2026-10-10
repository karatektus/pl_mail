<?php

declare(strict_types=1);
namespace App\Tests\Service\Ai;

use App\Domain\Ai\EmbeddingSpace;
use App\Entity\Ai\AiSettings;
use App\Service\Ai\OpenAiClient;
use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CompatibleEmbeddingTest extends TestCase
{
    public function testIndependentAndSharedConnectionsKeepModelSeparate(): void
    {
        $settings = new AiSettings();
        $settings->baseUrl = 'http://ollama.test';
        $settings->embeddingModel = 'embedding-only';
        $settings->chatProvider = 'openai';
        $settings->openAiBaseUrl = 'https://synthetic.test/v1';
        $settings->openAiApiToken = 'synthetic-secret';
        $settings->openAiModel = 'chat-only';
        self::assertSame('ollama', $settings->effectiveEmbeddingProvider());
        $original = $settings->embeddingSpace();
        $settings->embeddingSharedConnection = true;
        self::assertSame('openai', $settings->effectiveEmbeddingProvider());
        self::assertSame('synthetic-secret', $settings->effectiveEmbeddingToken());
        self::assertSame('embedding-only', $settings->embeddingModel);
        self::assertNotSame($original, $settings->embeddingSpace());
        $space = $settings->embeddingSpace();
        $settings->openAiApiToken = 'replacement-secret';
        self::assertSame($space, $settings->embeddingSpace());
        $settings->embeddingSharedConnection = false;
        self::assertSame($original, $settings->embeddingSpace());
    }

    public function testIdentityNormalisesOnlyEndpointSyntaxAndSeparatesProviderPathAndModel(): void
    {
        $space = EmbeddingSpace::identity('openai', 'HTTPS://Synthetic.test:443/v1/', 'embedding');
        self::assertSame($space, EmbeddingSpace::identity('openai', 'https://synthetic.test/v1', 'embedding'));
        self::assertNotSame($space, EmbeddingSpace::identity('ollama', 'https://synthetic.test/v1', 'embedding'));
        self::assertNotSame($space, EmbeddingSpace::identity('openai', 'https://other.test/v1', 'embedding'));
        self::assertNotSame($space, EmbeddingSpace::identity('openai', 'https://synthetic.test/V1', 'embedding'));
        self::assertNotSame($space, EmbeddingSpace::identity('openai', 'https://synthetic.test/v1', 'Embedding'));
        self::assertNotSame($space, EmbeddingSpace::identity('openai', 'https://synthetic.test/v1', 'embedding', 'revision-2'));
    }

    public function testOpenRouterEmbeddingDiscoveryUsesItsSeparateCatalog(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            self::assertSame('GET', $method);
            self::assertSame('https://openrouter.ai/api/v1/embeddings/models', $url);
            return new MockResponse('{"data":[{"id":"synthetic-embedding-model"}]}');
        });
        $probe = (new OpenAiClient($http))->probe('https://openrouter.ai/api/v1', embeddings: true);
        self::assertTrue($probe->reachable);
        self::assertTrue($probe->hasModel('synthetic-embedding-model'));
    }

    public function testBearerEmbeddingUsesSeparateModelAndFiniteOrderedResponse(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://synthetic.test/v1/embeddings', $url);
            self::assertContains('Authorization: Bearer synthetic-secret', $options['headers']);
            self::assertSame(['model'=>'embedding-only', 'input'=>'synthetic text'], json_decode($options['body'], true));
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse('{"data":[{"index":0,"embedding":[3,4]}],"usage":{"prompt_tokens":2,"total_tokens":2}}');
        });
        $result = (new OpenAiClient($http))->embed('https://synthetic.test/v1', 'embedding-only', 'synthetic text', 'synthetic-secret');
        self::assertTrue($result->succeeded);
        self::assertSame([3.0,4.0], $result->vector);
        self::assertSame(2, $result->timing->promptTokens);
        foreach (['{"data":[]}', '{"data":[{"index":1,"embedding":[1,2]}]}', '{"data":[{"index":0,"embedding":["secret",2]}]}', '{"data":[{"index":0,"embedding":{"x":1}}]}'] as $body) {
            $result = (new OpenAiClient(new MockHttpClient(new MockResponse($body))))->embed('https://synthetic.test/v1', 'embedding', 'synthetic');
            self::assertFalse($result->succeeded);
            self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
        }
    }
}
