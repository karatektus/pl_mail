<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\OllamaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tokens arriving one at a time, and the timing block that ends them.
 *
 * The assertion that earns its keep is testAChunkThatSplitsAnObjectIsNotLost.
 * A chunk off the wire is not a line: it can carry half a JSON object, or
 * three of them. A parser that splits on chunk boundaries works perfectly
 * against a local mock and drops tokens over a real network, which is the
 * hardest version of this bug to find because the code looks right and the
 * symptom is "the model sometimes misses a word".
 */
final class OllamaStreamTest extends TestCase
{
    public function testTokensArriveAndTheFinalObjectCarriesTheTimings(): void
    {
        $body = implode("\n", [
            '{"message":{"content":"Dear "},"done":false}',
            '{"message":{"content":"Paul"},"done":false}',
            '{"done":true,"total_duration":24000000000,"load_duration":13000000000,'
                . '"prompt_eval_count":120,"prompt_eval_duration":700000000,'
                . '"eval_count":187,"eval_duration":10000000000}',
        ]) . "\n";

        $tokens = $this->client($body)->chatStream('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(['Dear ', 'Paul'], iterator_to_array($tokens, false));

        $result = $tokens->getReturn();

        self::assertTrue($result->succeeded);
        self::assertSame('Dear Paul', $result->content);
        self::assertSame(187, $result->timing->evalTokens);
        self::assertSame(10000000000, $result->timing->evalDurationNs);

        // The cold load is the number the composer's "this takes about fifteen
        // seconds the first time" is answerable from.
        self::assertSame(13000000000, $result->timing->loadDurationNs);
    }

    /** A JSON object split across two chunks must survive the join. */
    public function testAChunkThatSplitsAnObjectIsNotLost(): void
    {
        $client = new OllamaClient(
            new MockHttpClient(new MockResponse([
                '{"message":{"content":"Hel',
                'lo"},"done":false}' . "\n" . '{"message":{"content":" there"},"done":fal',
                'se}' . "\n" . '{"done":true,"eval_count":2,"eval_duration":1000000000}' . "\n",
            ])),
            new NullLogger(),
        );

        $tokens = $client->chatStream('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(['Hello', ' there'], iterator_to_array($tokens, false));
        self::assertSame('Hello there', $tokens->getReturn()->content);
    }

    public function testAStreamThatSaysNothingIsAFailureRatherThanAnEmptyDraft(): void
    {
        $tokens = $this->client('{"done":true}' . "\n")
            ->chatStream('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        iterator_to_array($tokens, false);

        $result = $tokens->getReturn();

        self::assertFalse($result->succeeded);
        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
    }

    public function testAModelThatIsNotPulledIsSaidSoDistinctly(): void
    {
        $client = new OllamaClient(
            new MockHttpClient(new MockResponse('not found', ['http_code' => 404])),
            new NullLogger(),
        );

        $tokens = $client->chatStream('http://h:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        iterator_to_array($tokens, false);

        self::assertSame(OllamaClient::ERROR_HTTP_404, $tokens->getReturn()->errorKind);
    }

    private function client(string $body): OllamaClient
    {
        return new OllamaClient(new MockHttpClient(new MockResponse($body)), new NullLogger());
    }
}
