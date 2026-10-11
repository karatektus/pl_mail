<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\AiConnection;
use App\Domain\Enum\Ai\AiProvider;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The second dialect, against the answers real servers give.
 *
 * Every body here is the shape some OpenAI-compatible server actually sends,
 * including the awkward ones: an event split across two chunks, a comment line
 * used as a keep-alive, a stream with no terminator, an error delivered inside
 * a 200, and embeddings answered as base64. Each of those is a server that
 * works with other clients, so each is a report of "plMail does not work with
 * X" if it is handled wrongly.
 *
 * The client is checked for what it SENDS as well — the key, the address, and
 * above all the fields it leaves out, because a strict server answers 400 to
 * one it does not know.
 */
final class OpenAiClientTest extends TestCase
{
    private const string ROOT = 'https://api.example.test/v1';

    // ── chat ─────────────────────────────────────────────────────────────────

    public function testACompletionIsAskedForAtTheAddressWithTheKey(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'A reply.']]],
            'usage'   => ['prompt_tokens' => 40, 'completion_tokens' => 3],
        ]));

        $result = $client->chat($this->connection('sk-test'), 'a-model', [['role' => 'user', 'content' => 'hi']], 0.2);

        self::assertTrue($result->succeeded);
        self::assertSame('A reply.', $result->content);
        self::assertSame(40, $result->timing->promptTokens);
        self::assertSame(3, $result->timing->evalTokens);
        self::assertNotNull($result->timing->totalDurationNs, 'the round trip is the one duration there is');

        self::assertSame('POST', $seen[0]['method']);
        self::assertSame(self::ROOT . '/chat/completions', $seen[0]['url']);
        self::assertStringContainsString('Authorization: Bearer sk-test', $seen[0]['headers']);
        self::assertSame(0, $seen[0]['max_redirects'], 'a key and somebody\'s mail do not follow a redirect');
    }

    /**
     * Nothing the format does not define. A keep_alive or a num_ctx is a 400 or
     * a 422 from a server that validates its input, and those are servers that
     * otherwise work.
     */
    public function testTheBodyCarriesOnlyWhatTheFormatDefines(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode(['choices' => [['message' => ['content' => 'ok']]]]));

        $client->chat($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(
            ['model' => 'a-model', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => false],
            json_decode($seen[0]['body'], true),
        );
    }

    /** A trailing slash on the address is what people paste. */
    public function testATrailingSlashOnTheAddressIsTolerated(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode(['choices' => [['message' => ['content' => 'ok']]]]));

        $client->chat(new AiConnection(self::ROOT . '/', null, AiProvider::OpenAi), 'a-model', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(self::ROOT . '/chat/completions', $seen[0]['url']);
    }

    /** No key, no header — a server on the LAN that asks for nothing. */
    public function testNoKeyMeansNoAuthorizationHeader(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode(['choices' => [['message' => ['content' => 'ok']]]]));

        $client->chat($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]);

        self::assertStringNotContainsStringIgnoringCase('authorization', $seen[0]['headers']);
    }

    public function testAMissingModelIsItsOwnCategory(): void
    {
        $result = $this->answering('{"error":{"message":"model not found"}}', 404)
            ->chat($this->connection(), 'nope', [['role' => 'user', 'content' => 'hi']]);

        self::assertFalse($result->succeeded);
        self::assertSame(OllamaClient::ERROR_HTTP_404, $result->errorKind);
    }

    /** A 200 that is a proxy's HTML is a failure with a name, not an exception. */
    public function testA200ThatIsNotJsonIsABadResponse(): void
    {
        $result = $this->answering('<html>Sign in</html>')
            ->chat($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
    }

    /** Mail is full of stray bytes, and Symfony refuses to encode one. */
    public function testMalformedUtf8IsRepairedRatherThanFailingTheCall(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode(['choices' => [['message' => ['content' => 'ok']]]]));

        $result = $client->chat($this->connection(), 'a-model', [['role' => 'user', 'content' => "Gr\xFC\xDFe"]]);

        self::assertTrue($result->succeeded, 'one bad byte must not cost the reader the whole feature');
        self::assertCount(1, $seen);
    }

    // ── streaming ────────────────────────────────────────────────────────────

    public function testTokensArriveInOrderAndTheStreamEndsAtDone(): void
    {
        $client = $this->answering(
            self::event(['choices' => [['delta' => ['role' => 'assistant']]]])
            . self::event(['choices' => [['delta' => ['content' => 'Dear ']]]])
            . self::event(['choices' => [['delta' => ['content' => 'Anna']]]])
            . self::event(['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 2]])
            . "data: [DONE]\n\n",
        );

        [$tokens, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame(['Dear ', 'Anna'], $tokens);
        self::assertTrue($result->succeeded);
        self::assertSame('Dear Anna', $result->content);
        self::assertSame(2, $result->timing->evalTokens, 'the usage on the last event is kept');
        self::assertNotNull($result->timing->evalDurationNs, 'with a count there is a rate to show');
    }

    /**
     * A chunk off the wire is not an event. This one is cut in the middle of
     * the JSON, which is what a real network does and a local test never does.
     */
    public function testAnEventSplitAcrossChunksIsNotLost(): void
    {
        $whole = self::event(['choices' => [['delta' => ['content' => 'split']]]]);

        $client = new OpenAiClient(
            new MockHttpClient(new MockResponse([
                substr($whole, 0, 20),
                substr($whole, 20),
                "data: [DONE]\n\n",
            ])),
            new NullLogger(),
        );

        [$tokens, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame(['split'], $tokens);
        self::assertTrue($result->succeeded);
    }

    /**
     * Comment lines and CRLF line endings are both legal in the format, and
     * both are sent by servers people use: one router keeps the connection
     * alive with comments while it finds a model.
     */
    public function testCommentsAndCarriageReturnsAreReadPast(): void
    {
        $client = $this->answering(
            ": keep-alive\r\n\r\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'one']]]]) . "\r\n\r\n"
            . "event: ping\r\n\r\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['content' => ' two']]]]) . "\r\n\r\n"
            . "data: [DONE]\r\n\r\n",
        );

        [$tokens, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame(['one', ' two'], $tokens);
        self::assertSame('one two', $result->content);
    }

    /** A draft that arrived is a draft, with or without the last line. */
    public function testAStreamWithoutATerminatorIsJudgedByItsText(): void
    {
        $client = $this->answering(self::event(['choices' => [['delta' => ['content' => 'All of it.']]]]));

        [, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertTrue($result->succeeded);
        self::assertSame('All of it.', $result->content);
    }

    /** No usage, no rate: a duration beside a missing count would be a number about nothing. */
    public function testWithoutAUsageBlockNothingIsInventedForTheMetrics(): void
    {
        $client = $this->answering(self::event(['choices' => [['delta' => ['content' => 'text']]]]) . "data: [DONE]\n\n");

        [, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertNull($result->timing->evalTokens);
        self::assertNull($result->timing->evalDurationNs);
    }

    public function testAStreamThatSaysNothingIsAFailureRatherThanAnEmptyDraft(): void
    {
        $client = $this->answering(self::event(['choices' => [['delta' => ['role' => 'assistant']]]]) . "data: [DONE]\n\n");

        [$tokens, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame([], $tokens);
        self::assertFalse($result->succeeded);
        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
    }

    /**
     * A provider that fails mid-answer has already sent 200. Half a sentence
     * recorded as a finished draft is the failure this prevents.
     */
    public function testAnErrorInsideTheStreamFailsTheCall(): void
    {
        $client = $this->answering(
            self::event(['choices' => [['delta' => ['content' => 'Half a sen']]]])
            . self::event(['error' => ['message' => 'upstream overloaded', 'code' => 502]]),
        );

        [$tokens, $result] = self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame(['Half a sen'], $tokens, 'what arrived was shown');
        self::assertFalse($result->succeeded);
        self::assertSame(OllamaClient::ERROR_HTTP_STATUS, $result->errorKind);
    }

    public function testARefusedStreamYieldsNothingAndSaysWhy(): void
    {
        $client = $this->answering('{"error":{"message":"Incorrect API key"}}', 401);

        [$tokens, $result] = self::drain($client->chatStream($this->connection('wrong'), 'a-model', [['role' => 'user', 'content' => 'hi']]));

        self::assertSame([], $tokens);
        self::assertSame(OllamaClient::ERROR_HTTP_STATUS, $result->errorKind);
    }

    /**
     * While the endpoint is silent the caller is handed an empty string to turn
     * into a frame, and the stream carries on afterwards.
     *
     * The contract the controllers were written against. Without the heartbeat
     * a long prompt is one long silence, and a reverse proxy in front of plMail
     * is entitled to close the connection during it.
     */
    public function testSilenceIsAHeartbeatAndNotTheEnd(): void
    {
        $client = new OpenAiClient(
            new MockHttpClient(new MockResponse([
                // An empty chunk is how MockResponse says "the idle timeout
                // passed and nothing came".
                '',
                self::event(['choices' => [['delta' => ['content' => 'late']]]]),
                "data: [DONE]\n\n",
            ])),
            new NullLogger(),
        );

        $stream = $client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']]);
        $all    = [];

        foreach ($stream as $token) {
            $all[] = $token;
        }

        self::assertSame(['', 'late'], $all);
        self::assertTrue($stream->getReturn()->succeeded);
    }

    public function testAStreamedRequestAsksToBeStreamed(): void
    {
        $seen   = [];
        $client = $this->recording($seen, "data: [DONE]\n\n");

        self::drain($client->chatStream($this->connection(), 'a-model', [['role' => 'user', 'content' => 'hi']], 0.7));

        self::assertSame(
            ['model' => 'a-model', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true, 'temperature' => 0.7],
            json_decode($seen[0]['body'], true),
        );
    }

    // ── embeddings ───────────────────────────────────────────────────────────

    public function testOneInputGetsOneVector(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode([
            'data'  => [['index' => 0, 'embedding' => [0.25, 1, -0.5]]],
            'usage' => ['prompt_tokens' => 7],
        ]));

        $result = $client->embed($this->connection('sk-test'), 'an-embedder', 'some mail');

        self::assertTrue($result->succeeded);
        self::assertSame([0.25, 1.0, -0.5], $result->vector, 'a whole number in the JSON is still a float in the vector');
        self::assertSame(self::ROOT . '/embeddings', $seen[0]['url']);
        self::assertSame(['model' => 'an-embedder', 'input' => 'some mail'], json_decode($seen[0]['body'], true));
        self::assertStringContainsString('Bearer sk-test', $seen[0]['headers']);
    }

    /**
     * base64 is some servers' default encoding. floatval() of it is 0.0, which
     * would store a vector of zeros that matches nothing and never errors.
     */
    public function testAVectorThatIsNotNumbersIsRefused(): void
    {
        $result = $this->answering(json_encode(['data' => [['embedding' => 'AAAAAAAA8D8=']]]))
            ->embed($this->connection(), 'an-embedder', 'some mail');

        self::assertFalse($result->succeeded);
        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);

        $result = $this->answering(json_encode(['data' => [['embedding' => [0.1, '0.2']]]]))
            ->embed($this->connection(), 'an-embedder', 'some mail');

        self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
    }

    /** Two vectors for one text is not an answer to the question asked. */
    public function testAnythingButExactlyOneEntryIsRefused(): void
    {
        foreach ([[], [['embedding' => [0.1]], ['embedding' => [0.2]]]] as $data) {
            $result = $this->answering(json_encode(['data' => $data]))
                ->embed($this->connection(), 'an-embedder', 'some mail');

            self::assertSame(OllamaClient::ERROR_BAD_RESPONSE, $result->errorKind);
        }
    }

    // ── probe ────────────────────────────────────────────────────────────────

    public function testAProbeListsWhatTheEndpointOffers(): void
    {
        $seen   = [];
        $client = $this->recording($seen, json_encode(['object' => 'list', 'data' => [['id' => 'zeta'], ['id' => 'alpha'], ['object' => 'model']]]));

        $probe = $client->probe($this->connection('sk-test'));

        self::assertTrue($probe->reachable);
        self::assertSame(['alpha', 'zeta'], array_map(static fn ($model): string => $model->name, $probe->models));
        self::assertTrue($probe->hasModel('alpha'));
        self::assertSame('GET', $seen[0]['method']);
        self::assertSame(self::ROOT . '/models', $seen[0]['url']);
        self::assertStringContainsString('Bearer sk-test', $seen[0]['headers']);
    }

    /** The three refusals an administrator can do something different about. */
    public function testEachRefusalNamesWhatToLookAt(): void
    {
        self::assertSame('refused', $this->answering('{}', 401)->probe($this->connection('wrong'))->reason);
        self::assertSame('refused', $this->answering('{}', 403)->probe($this->connection('wrong'))->reason);
        self::assertSame('no_model_list', $this->answering('Not Found', 404)->probe($this->connection())->reason);
        self::assertSame('status', $this->answering('{}', 500)->probe($this->connection())->reason);
        self::assertSame('not_an_api', $this->answering('<html>Sign in</html>')->probe($this->connection())->reason);

        self::assertSame(401, $this->answering('{}', 401)->probe($this->connection())->reasonParams['status']);
    }

    /** A redirect is reported as the status it is, never followed. */
    public function testARedirectIsAnAnswerNotAnInstruction(): void
    {
        $seen   = [];
        $client = new OpenAiClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
                $seen[] = $url;

                return new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://elsewhere.example.test/v1/models']]);
            }),
            new NullLogger(),
        );

        $probe = $client->probe($this->connection('sk-test'));

        self::assertFalse($probe->reachable);
        self::assertSame(301, $probe->reasonParams['status']);
        self::assertSame([self::ROOT . '/models'], $seen, 'the key was not taken to the address the redirect named');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function connection(?string $key = null): AiConnection
    {
        return new AiConnection(self::ROOT, $key, AiProvider::OpenAi);
    }

    private function answering(string|false $body, int $status = 200): OpenAiClient
    {
        return new OpenAiClient(
            new MockHttpClient(new MockResponse((string) $body, ['http_code' => $status])),
            new NullLogger(),
        );
    }

    /**
     * @param list<array{method: string, url: string, headers: string, body: string, max_redirects: int}> $seen
     */
    private function recording(array &$seen, string|false $body): OpenAiClient
    {
        return new OpenAiClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $body): MockResponse {
                $seen[] = [
                    'method'        => $method,
                    'url'           => $url,
                    'headers'       => implode("\n", $options['headers'] ?? []),
                    'body'          => is_string($options['body'] ?? null) ? $options['body'] : '',
                    'max_redirects' => (int) ($options['max_redirects'] ?? -1),
                ];

                return new MockResponse((string) $body);
            }),
            new NullLogger(),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function event(array $payload): string
    {
        return 'data: ' . json_encode($payload) . "\n\n";
    }

    /**
     * @param \Generator<int, string, void, AiChatResult> $stream
     *
     * @return array{list<string>, AiChatResult}
     */
    private static function drain(\Generator $stream): array
    {
        $tokens = [];

        foreach ($stream as $token) {
            // The empty string is the heartbeat, not a token.
            if ('' !== $token) {
                $tokens[] = $token;
            }
        }

        return [$tokens, $stream->getReturn()];
    }
}
