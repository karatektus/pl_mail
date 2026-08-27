<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\WritingAssistant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The composer's draft, arriving a token at a time.
 *
 * WHAT IS ACTUALLY AT RISK HERE
 * ─────────────────────────────
 * Not the tokens. OllamaStreamTest already pins the line buffering, and the
 * happy path is the part anybody would notice broken within a minute of using
 * it.
 *
 * What is at risk is the ACCOUNTING, and it is at risk precisely because it is
 * invisible. A streamed call is normally abandoned rather than finished — the
 * writer presses stop, closes the window, or navigates away — and every one of
 * those destroys the generator part-way through. Record after the loop and the
 * table gets a row for every call except the expensive ones; record in two
 * places and a stopped draft is counted twice. Neither shows up anywhere a
 * person looks until somebody tries to work out why the panel's numbers do not
 * match the GPU's.
 *
 * The other half is cancellation, which is the same fact seen from the host:
 * an abandoned draft that keeps generating holds a 20 GiB model on a machine
 * with one GPU, and everything else queues behind a reply nobody will read.
 * That the generator's `finally` runs on destruction is what makes both work,
 * and it is a language guarantee this leans on hard enough to be worth pinning.
 */
final class WritingStreamTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    /** @var list<array<string, mixed>> */
    private array $recorded = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);
        $this->recorded   = [];

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTokensArriveOneAtATimeAndTheAnswerIsTheWholeOfThem(): void
    {
        $assistant = $this->assistant($this->ndjson(['Dear ', 'Kim', ',']));

        $tokens = $assistant->stream($this->writer(), WritingTask::Reply, '', 'Can you send the invoice?');

        self::assertNotNull($tokens);
        self::assertSame(['Dear ', 'Kim', ','], iterator_to_array($tokens, false));
        self::assertSame('Dear Kim,', $tokens->getReturn()->content);
    }

    /**
     * The fence is recognisable only from both ends, so it cannot be stripped
     * as the tokens go past — the opening ``` is the first thing out of the
     * model and the closing one the last. The RETURN carries the tidied whole,
     * which is what the browser inserts into the draft; the tokens it showed on
     * the way are allowed to be the raw thing.
     */
    public function testTheFenceIsStrippedFromTheReturnRatherThanFromTheTokens(): void
    {
        $assistant = $this->assistant($this->ndjson(["```\n", 'Dear Kim.', "\n```"]));

        $tokens = $assistant->stream($this->writer(), WritingTask::Reply, '', 'hello');

        self::assertNotNull($tokens);

        $streamed = implode('', iterator_to_array($tokens, false));

        self::assertSame("```\nDear Kim.\n```", $streamed);
        self::assertSame('Dear Kim.', $tokens->getReturn()->content);
    }

    /** One row, on the ordinary ending. */
    public function testAFinishedStreamIsRecordedOnce(): void
    {
        $assistant = $this->assistant($this->ndjson(['a', 'b']));

        $tokens = $assistant->stream($this->writer(), WritingTask::Reply, '', 'hello');

        self::assertNotNull($tokens);

        iterator_to_array($tokens, false);

        self::assertCount(1, $this->recorded);
        self::assertTrue($this->recorded[0]['succeeded']);
        self::assertNull($this->recorded[0]['errorKind']);
        self::assertSame('writing_help', $this->recorded[0]['feature']);
    }

    /**
     * And one row on the ending that actually happens.
     *
     * Stop pressed, window closed, browser navigated away: the generator is
     * destroyed while suspended, and PHP runs its `finally` when it does. This
     * is the assertion that would fail if the recording were moved to after the
     * loop "for clarity" — where it would look completely correct.
     */
    public function testAStreamNobodyWaitedForIsStillRecordedExactlyOnce(): void
    {
        $assistant = $this->assistant($this->ndjson(['a', 'b', 'c']));

        $tokens = $assistant->stream($this->writer(), WritingTask::Reply, '', 'hello');

        self::assertNotNull($tokens);

        // One token read, and then the reader goes away mid-generation.
        self::assertSame('a', $tokens->current());

        unset($tokens);

        self::assertCount(1, $this->recorded, 'an abandoned stream is still a call the host performed');
        self::assertFalse($this->recorded[0]['succeeded']);
        self::assertSame(
            AiAssistant::ERROR_CANCELLED,
            $this->recorded[0]['errorKind'],
            'a call the user stopped must be distinguishable from a host that failed',
        );
    }

    /** A host that refuses is a category, not an exception, and still one row. */
    public function testAModelThatWasNeverPulledIsOneRecordedFailure(): void
    {
        $assistant = $this->assistant(new MockResponse('not found', ['http_code' => 404]));

        $tokens = $assistant->stream($this->writer(), WritingTask::Reply, '', 'hello');

        self::assertNotNull($tokens);
        self::assertSame([], iterator_to_array($tokens, false));
        self::assertSame(OllamaClient::ERROR_HTTP_404, $tokens->getReturn()->errorKind);
        self::assertCount(1, $this->recorded);
        self::assertSame(OllamaClient::ERROR_HTTP_404, $this->recorded[0]['errorKind']);
    }

    /**
     * The refusals happen when stream() is CALLED, not when somebody gets round
     * to iterating what it returned.
     *
     * If this were a generator function the guard would run at the caller's
     * first foreach — so a caller that never iterated would have been silently
     * permitted, and a switched-off installation would have depended on nobody
     * holding the return value.
     */
    public function testNothingToWorkFromIsRefusedBeforeAnythingIsAsked(): void
    {
        $calls     = 0;
        $assistant = $this->assistant($this->ndjson(['a']), $calls);

        self::assertNull($assistant->stream($this->writer(), WritingTask::Shorten, '   ', 'a message'));
        self::assertNull($assistant->stream($this->writer(), WritingTask::Reply, '', ''));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded, 'a call that was never made is not a call');
    }

    public function testAnInstallationWithTheFeatureOffStreamsNothing(): void
    {
        $calls     = 0;
        $assistant = $this->assistant($this->ndjson(['a']), $calls, enabled: false);

        self::assertNull($assistant->stream($this->writer(), WritingTask::Reply, '', 'hello'));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded);
    }

    /**
     * A writer who has switched drafting help off gets nothing, with the
     * installation's switch fully on — and nothing is recorded, because no call
     * was made.
     */
    public function testAWriterWhoHasSwitchedDraftingHelpOffStreamsNothing(): void
    {
        $calls     = 0;
        $assistant = $this->assistant($this->ndjson(['a']), $calls);

        $writer = $this->writer();
        $writer->aiPreferences->writingHelpOff = true;

        self::assertNull($assistant->stream($writer, WritingTask::Reply, '', 'hello'));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded);
    }

    /**
     * The streamed path builds the same prompt as the unstreamed one.
     *
     * Both go through messagesFor() precisely so that they cannot diverge, and
     * this is the assertion that keeps it that way: two copies of the persona
     * rule would be one that gets a fix and one that does not.
     */
    public function testTheStreamedPromptCarriesThePersonaInTheSystemMessage(): void
    {
        $sent = null;

        $writer = $this->writer();
        $writer->aiPreferences->aboutMe = 'I run a bicycle repair shop in Leipzig.';

        $assistant = $this->assistant($this->ndjson(['a']), sent: $sent);

        $tokens = $assistant->stream($writer, WritingTask::Reply, '', 'When are you open?');

        self::assertNotNull($tokens);

        // Iterated, because the request is not made until it is.
        foreach ($tokens as $ignored) {
        }

        self::assertIsArray($sent);
        self::assertStringContainsString('I run a bicycle repair shop in Leipzig.', $sent['messages'][0]['content']);
        self::assertStringStartsWith(WritingTask::Reply->systemPrompt(), $sent['messages'][0]['content']);
        self::assertStringNotContainsString('bicycle repair shop', $sent['messages'][1]['content']);
    }

    /**
     * A model name without a tag and the same name with `:latest` are the same
     * model. The settings field holds whatever an administrator typed and
     * /api/ps always answers the long form, so comparing them raw reports a
     * correctly configured host as cold on every single request — and the
     * composer would then promise a fifteen-second load before every reply it
     * ever drafted.
     */
    public function testAModelIsRecognisedAsResidentWhicheverWayItsTagIsSpelled(): void
    {
        $assistant = $this->assistant(
            $this->ndjson(['a']),
            model: 'llama3.1',
            ps: ['models' => [['name' => 'llama3.1:latest', 'model' => 'llama3.1:latest']]],
        );

        self::assertTrue($assistant->isModelWarm());
    }

    public function testAHostHoldingSomethingElseIsColdRatherThanWarm(): void
    {
        $assistant = $this->assistant(
            $this->ndjson(['a']),
            ps: ['models' => [['name' => 'nomic-embed-text:latest']]],
        );

        self::assertFalse($assistant->isModelWarm());
    }

    /**
     * A host that is not there reads as cold, which is the right way round: the
     * composer says "this may take a moment" and reports the real failure a
     * moment later. The other way round it would promise tokens that are never
     * coming.
     */
    public function testAHostThatSaysNothingReadsAsCold(): void
    {
        $assistant = $this->assistant($this->ndjson(['a']), ps: null);

        self::assertFalse($assistant->isModelWarm());
    }

    // ── Scaffolding ───────────────────────────────────────────────────────

    /** @param list<string> $tokens */
    private function ndjson(array $tokens): MockResponse
    {
        $lines = [];

        foreach ($tokens as $token) {
            $lines[] = json_encode(['message' => ['content' => $token], 'done' => false]);
        }

        $lines[] = json_encode([
            'done'          => true,
            'eval_count'    => count($tokens),
            'eval_duration' => 1000000000,
            'load_duration' => 13000000000,
        ]);

        return new MockResponse(implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string, mixed>|null $ps   what /api/ps answers, or null for a host that is down
     * @param array<string, mixed>|null $sent  the request body the model was actually sent, decoded
     */
    private function assistant(
        MockResponse $chat,
        int          &$calls = 0,
        bool         $enabled = true,
        string       $model = 'llama3.1:8b',
        ?array       $ps = ['models' => []],
        ?array       &$sent = null,
    ): WritingAssistant {
        $settings                     = new AiSettings();
        $settings->isEnabled          = $enabled;
        $settings->baseUrl            = 'http://10.0.0.5:11434';
        $settings->chatModel          = $model;
        $settings->writingHelpEnabled = $enabled;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$sent, $chat, $ps): MockResponse {
            if (true === str_contains($url, '/api/ps')) {
                return null === $ps
                    ? new MockResponse('', ['http_code' => 500])
                    : new MockResponse((string) json_encode($ps));
            }

            ++$calls;

            $body = $options['body'] ?? '';
            $sent = is_string($body) ? json_decode($body, true) : null;

            return $chat;
        });

        $ai = new AiAssistant(
            $this->settings,
            new OllamaClient($http, new NullLogger()),
            $this->recorder(),
            new NullLogger(),
        );

        return new WritingAssistant($ai, new AiPermissions($ai));
    }

    /**
     * A user who has not opted out of anything.
     *
     * Never persisted: AiPermissions reads the embeddable straight off the
     * object, so a row would buy nothing and would need cleaning up.
     */
    private function writer(): User
    {
        return new User();
    }

    /**
     * The REAL recorder over a Connection that remembers instead of writing.
     *
     * A stub would have made every assertion above impossible — the whole
     * subject of this file is how many rows exist and what is in them. The real
     * class rather than a fake so that a change to its constructor or its
     * parameter names breaks here, where it is cheap.
     */
    private function recorder(): AiCallRecorder
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->recorded[] = $params;

                return 1;
            });

        return new AiCallRecorder($connection, new NullLogger());
    }
}
