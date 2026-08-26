<?php
declare(strict_types=1);
namespace App\Tests\Service\Ai;

use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaPsTest extends TestCase
{
    /** The real payload from the operator's host, verbatim. */
    private const string REAL = '{"models":[{"name":"qwen3:30b-a3b-instruct-2507-q4_K_M","model":"qwen3:30b-a3b-instruct-2507-q4_K_M","size":21761548614,"size_vram":21761548614,"context_length":32768,"expires_at":"2026-08-26T20:16:23.896966524Z","details":{"parameter_size":"30.5B","quantization_level":"Q4_K_M","family":"qwen3moe"}}]}';

    public function testARealPsPayloadIsUnderstood(): void
    {
        $client = new OllamaClient(new MockHttpClient(new MockResponse(self::REAL)), new NullLogger());
        $loaded = $client->ps('http://h:11434');

        self::assertCount(1, $loaded);
        $m = $loaded[0];
        self::assertSame('qwen3:30b-a3b-instruct-2507-q4_K_M', $m->name);
        self::assertSame(21761548614, $m->sizeBytes);
        self::assertSame(21761548614, $m->sizeVramBytes);
        self::assertSame(32768, $m->contextLength);
        self::assertSame('30.5B', $m->parameterSize);
        self::assertSame('Q4_K_M', $m->quantisation);
        self::assertTrue($m->fullyOnGpu(), 'size_vram == size means the whole model is on the GPU');
        self::assertNotNull($m->expiresAt, 'nanosecond precision must not defeat the parse');
    }

    public function testLayersSpilledToTheCpuAreVisible(): void
    {
        $body = '{"models":[{"name":"m","size":1000,"size_vram":600}]}';
        $client = new OllamaClient(new MockHttpClient(new MockResponse($body)), new NullLogger());
        $m = $client->ps('http://h:11434')[0];

        self::assertFalse($m->fullyOnGpu());
        self::assertSame(0.6, $m->gpuFraction());
    }

    public function testAnUnreachableHostSimplyHoldsNothing(): void
    {
        $client = new OllamaClient(new MockHttpClient(new MockResponse('no', ['http_code' => 500])), new NullLogger());
        self::assertSame([], $client->ps('http://h:11434'));
    }
}
