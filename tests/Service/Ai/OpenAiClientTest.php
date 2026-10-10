<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\OpenAiClient;
use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiClientTest extends TestCase
{
    public function testCompletionUsesBearerAndKeepsOllamaOptionsOffTheWire(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://synthetic.test/v1/chat/completions', $url);
            self::assertContains('Authorization: Bearer synthetic-secret', $options['headers']);
            $body = json_decode($options['body'], true);
            self::assertSame('test-model', $body['model']);
            self::assertSame(0.0, $body['temperature']);
            self::assertArrayNotHasKey('num_ctx', $body);
            self::assertArrayNotHasKey('keep_alive', $body);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse('{"choices":[{"message":{"content":"synthetic reply"}}],"usage":{"prompt_tokens":3,"completion_tokens":2}}');
        });
        $result = (new OpenAiClient($http))->chat('https://synthetic.test/v1/', 'test-model', [['role'=>'user','content'=>'synthetic text']], 0, 'synthetic-secret');
        self::assertTrue($result->succeeded);
        self::assertSame('synthetic reply', $result->content);
        self::assertSame(3, $result->timing->promptTokens);
        self::assertNull($result->timing->loadDurationNs);
    }

    public function testFragmentedSseAndEmptyDeltas(): void
    {
        $chunks = (function () {
            yield ': keepalive\n\n';
            yield "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\r\n\r\n";
            yield "data: {\"choices\":[{\"delta\":{\"content\":\"hel";
            yield "lo\"}}]}\r";
            yield "\n\r\ndata: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\ndata: [DONE]\n\n";
        })();
        $stream = (new OpenAiClient(new MockHttpClient(new MockResponse($chunks))))->chatStream('https://synthetic.test/v1', 'test', [['role'=>'user','content'=>'synthetic']]);
        self::assertSame(['hello', ' world'], iterator_to_array($stream));
        self::assertSame('hello world', $stream->getReturn()->content);
        self::assertTrue($stream->getReturn()->succeeded);
    }

    public function testTruncatedStreamFails(): void
    {
        $stream = (new OpenAiClient(new MockHttpClient(new MockResponse(["data: {\"choices\":[{\"delta\":{\"content\":\"partial\"}}]}\n\n"]))))->chatStream('https://synthetic.test/v1', 'test', []);
        iterator_to_array($stream);
        self::assertFalse($stream->getReturn()->succeeded);
    }

    public function testUpstreamSecretAndMailAreNotReturnedOnFailure(): void
    {
        $client = new OpenAiClient(new MockHttpClient(new MockResponse('synthetic-secret synthetic mail', ['http_code'=>401])));
        $result = $client->chat('https://synthetic.test/v1', 'test', [], key: 'synthetic-secret');
        self::assertSame(OllamaClient::ERROR_HTTP_STATUS, $result->errorKind);
        self::assertNull($result->content);
        self::assertStringNotContainsString('synthetic-secret', json_encode($result));
    }

    public function testModelDiscoveryUsesExactIds(): void
    {
        $probe = (new OpenAiClient(new MockHttpClient(new MockResponse('{"data":[{"id":"org:model"}]}'))))->probe('https://synthetic.test/v1');
        self::assertTrue($probe->hasModel('org:model'));
        self::assertFalse($probe->hasModel('org'));
    }
}
