<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\ThreadSummariser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A summary arriving a token at a time, and what it costs the metrics table.
 *
 * WHAT IS ACTUALLY AT RISK HERE
 * ─────────────────────────────
 * Not the tokens — OllamaStreamTest already pins the line buffering, and a
 * summary that never arrives is obvious within a minute of using the feature.
 *
 * What is at risk is the ACCOUNTING, and it is at risk because it is invisible.
 * This is the most expensive workload plMail has: a cold call is forty seconds
 * before the first token and about a minute end to end, on the same one GPU the
 * indexer wants. A streamed summary is also normally ABANDONED rather than
 * finished — the reader gets bored, closes the pane, or opens another thread,
 * and every one of those destroys the generator part-way through. Record after
 * the loop and the table gets a row for every call except the expensive ones.
 *
 * The second subject is what an abandoned run leaves behind, which must be
 * nothing: half a summary stored on the thread reads as a finished one the next
 * time it is opened, and there is no way for the reader to tell.
 */
final class ThreadSummaryStreamTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    /** @var list<array<string, mixed>> */
    private array $recorded = [];

    private MessageThread $thread;

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

        $this->thread = $this->seedThread(3);
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
        $summariser = $this->summariser($this->ndjson(['They agreed ', 'on ', 'Thursday.']));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'From: a <a@b.test>\nhello');

        self::assertNotNull($tokens);
        self::assertSame(['They agreed ', 'on ', 'Thursday.'], iterator_to_array($tokens, false));
        self::assertSame('They agreed on Thursday.', $tokens->getReturn()->content);
    }

    /**
     * The fence is stripped from the RETURN rather than from the tokens.
     *
     * It matters more here than in the composer, because the return is what
     * gets STORED: a stray fence in a stored summary is on the page every time
     * the thread is opened until somebody regenerates it.
     */
    public function testTheFenceIsStrippedFromTheReturnRatherThanFromTheTokens(): void
    {
        $summariser = $this->summariser($this->ndjson(["```\n", 'They agreed.', "\n```"]));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'a transcript');

        self::assertNotNull($tokens);
        self::assertSame("```\nThey agreed.\n```", implode('', iterator_to_array($tokens, false)));
        self::assertSame('They agreed.', $tokens->getReturn()->content);
    }

    /** One row, on the ordinary ending, tagged as the summary workload. */
    public function testAFinishedStreamIsRecordedOnceAsAThreadSummary(): void
    {
        $summariser = $this->summariser($this->ndjson(['a', 'b']));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'a transcript');

        self::assertNotNull($tokens);

        iterator_to_array($tokens, false);

        self::assertCount(1, $this->recorded);
        self::assertTrue($this->recorded[0]['succeeded']);
        self::assertNull($this->recorded[0]['errorKind']);
        self::assertSame(
            'thread_summary',
            $this->recorded[0]['feature'],
            'a summary recorded as writing help would bury the composer\'s numbers under a much longer call',
        );
    }

    /**
     * And one row on the ending that actually happens.
     *
     * A reader who opens another thread destroys this generator while it is
     * suspended, and PHP runs a suspended generator's `finally` when it does.
     * This is the assertion that would fail if the recording were moved to
     * after the loop "for clarity" — where it would look completely correct and
     * would silently drop every call that cost the most.
     *
     * Note the bug AiAssistant::recorded() records in its own comment: a
     * SUCCEEDED result has a null errorKind, so a `??` coalesce there stamped
     * "cancelled" on finished streams. Both halves are asserted, in this test
     * and the one above, so neither can come back.
     */
    public function testAStreamNobodyWaitedForIsStillRecordedExactlyOnce(): void
    {
        $summariser = $this->summariser($this->ndjson(['a', 'b', 'c']));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'a transcript');

        self::assertNotNull($tokens);
        self::assertSame('a', $tokens->current());

        unset($tokens);

        self::assertCount(1, $this->recorded, 'an abandoned stream is still a call the host performed');
        self::assertFalse($this->recorded[0]['succeeded']);
        self::assertSame(
            AiAssistant::ERROR_CANCELLED,
            $this->recorded[0]['errorKind'],
            'a call the reader stopped must be distinguishable from a host that failed',
        );
    }

    /**
     * An abandoned run leaves NOTHING in thread_summary.
     *
     * The controller stores only after the generator has finished, and this
     * pins the property from the other side: a half-written summary sitting on
     * the thread the next time it is opened reads as a finished one, and there
     * is nothing on the card that would say otherwise.
     */
    public function testAnAbandonedStreamStoresNothing(): void
    {
        $summariser = $this->summariser($this->ndjson(['half ', 'a ', 'summary']));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'a transcript');

        self::assertNotNull($tokens);
        self::assertSame('half ', $tokens->current());

        unset($tokens);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM thread_summary WHERE thread_id = :id',
                ['id' => $this->thread->id],
            ),
        );
    }

    /** A host that refuses is a category, not an exception, and still one row. */
    public function testAModelThatWasNeverPulledIsOneRecordedFailure(): void
    {
        $summariser = $this->summariser(new MockResponse('not found', ['http_code' => 404]));

        $tokens = $summariser->stream($this->reader(), $this->thread, 'a transcript');

        self::assertNotNull($tokens);
        self::assertSame([], iterator_to_array($tokens, false));
        self::assertSame(OllamaClient::ERROR_HTTP_404, $tokens->getReturn()->errorKind);
        self::assertCount(1, $this->recorded);
    }

    /**
     * The refusals happen when stream() is CALLED, not when somebody iterates.
     *
     * If this were a generator function the guards would run at the caller's
     * first foreach — so a caller that never iterated would have been silently
     * permitted, and a switched-off installation would have depended on nobody
     * holding the return value.
     */
    public function testAThreadOfOneMessageIsRefusedBeforeAnythingIsAsked(): void
    {
        $calls      = 0;
        $summariser = $this->summariser($this->ndjson(['a']), $calls);

        self::assertNull($summariser->stream($this->reader(), $this->seedThread(1), 'a transcript'));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded, 'a call that was never made is not a call');
    }

    public function testAThreadWithNothingToReadIsRefusedBeforeAnythingIsAsked(): void
    {
        $calls      = 0;
        $summariser = $this->summariser($this->ndjson(['a']), $calls);

        self::assertNull($summariser->stream($this->reader(), $this->thread, '   '));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded);
    }

    public function testAnInstallationWithSummariesOffStreamsNothing(): void
    {
        $calls      = 0;
        $summariser = $this->summariser($this->ndjson(['a']), $calls, enabled: false);

        self::assertNull($summariser->stream($this->reader(), $this->thread, 'a transcript'));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded);
    }

    /**
     * And the floor under the ceiling: a reader who has switched summaries off
     * gets nothing with the installation's switch fully on.
     */
    public function testAReaderWhoHasSwitchedSummariesOffStreamsNothing(): void
    {
        $calls      = 0;
        $summariser = $this->summariser($this->ndjson(['a']), $calls);

        $reader = $this->reader();
        $reader->aiPreferences->summaryOff = true;

        self::assertNull($summariser->stream($reader, $this->thread, 'a transcript'));
        self::assertSame(0, $calls);
        self::assertCount(0, $this->recorded);
    }

    /**
     * THE LANGUAGE RULE IS IN THE PROMPT, and it is the same sentence the
     * composer uses.
     *
     * A German thread summarised into English is a translation nobody asked
     * for, and — unlike a translated draft, which its writer reads before
     * sending — nobody checks it, because the whole point of the summary is not
     * reading the mail underneath. Asserted against PromptRules directly so a
     * second copy of the sentence cannot be introduced and drift.
     */
    public function testTheSystemPromptCarriesTheSharedLanguageRule(): void
    {
        $sent = null;

        $summariser = $this->summariser($this->ndjson(['a']), sent: $sent);

        $tokens = $summariser->stream($this->reader(), $this->thread, 'Von: Anna <anna@example.test>');

        self::assertNotNull($tokens);

        // Iterated, because the request is not made until it is.
        foreach ($tokens as $ignored) {
        }

        self::assertIsArray($sent);
        self::assertStringContainsString(\App\Domain\Ai\PromptRules::LANGUAGE, $sent['messages'][0]['content']);
    }

    /**
     * The persona is deliberately NOT in the prompt.
     *
     * WritingAssistant appends it because a writer can only talk themselves out
     * of the rules, on their own draft, which they read before they send it. A
     * summary is a statement about somebody else's mail presented as fact, and
     * the reader does not read the mail underneath — so "how the writer has
     * asked to be written for" shaping it produces a summary that is wrong in
     * the direction the reader asked for, with nothing to say so.
     */
    public function testThePersonaIsNotAppliedToASummary(): void
    {
        $sent = null;

        $reader = $this->reader();
        $reader->aiPreferences->aboutMe      = 'I run a bicycle repair shop in Leipzig.';
        $reader->aiPreferences->systemPrompt = 'Always answer in English.';

        $summariser = $this->summariser($this->ndjson(['a']), sent: $sent);

        $tokens = $summariser->stream($reader, $this->thread, 'a transcript');

        self::assertNotNull($tokens);

        foreach ($tokens as $ignored) {
        }

        self::assertIsArray($sent);
        self::assertStringNotContainsString('bicycle repair shop', (string) json_encode($sent));
        self::assertStringNotContainsString('Always answer in English.', (string) json_encode($sent));
    }

    /** The transcript is the user message and nothing else is. */
    public function testTheTranscriptIsTheUserMessage(): void
    {
        $sent = null;

        $summariser = $this->summariser($this->ndjson(['a']), sent: $sent);

        $tokens = $summariser->stream($this->reader(), $this->thread, 'From: Anna <anna@example.test>');

        self::assertNotNull($tokens);

        foreach ($tokens as $ignored) {
        }

        self::assertIsArray($sent);
        self::assertStringContainsString('From: Anna <anna@example.test>', $sent['messages'][1]['content']);
        self::assertSame(0.0, $sent['options']['temperature'], 'a summary given room to be creative invents an outcome');
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
            'load_duration' => 18000000000,
        ]);

        return new MockResponse(implode("\n", $lines) . "\n");
    }

    /** @param array<string, mixed>|null $sent the request body the model was actually sent, decoded */
    private function summariser(
        MockResponse $chat,
        int          &$calls = 0,
        bool         $enabled = true,
        ?array       &$sent = null,
    ): ThreadSummariser {
        $settings                 = new AiSettings();
        $settings->isEnabled      = $enabled;
        $settings->baseUrl        = 'http://10.0.0.5:11434';
        $settings->chatModel      = 'qwen3:30b';
        $settings->summaryEnabled = $enabled;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$sent, $chat): MockResponse {
            if (true === str_contains($url, '/api/ps')) {
                return new MockResponse((string) json_encode(['models' => []]));
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

        return new ThreadSummariser($ai, new AiPermissions($ai));
    }

    /**
     * A reader who has not opted out of anything.
     *
     * Never persisted: AiPermissions reads the embeddable straight off the
     * object, so a row would buy nothing and would need cleaning up.
     */
    private function reader(): User
    {
        return new User();
    }

    /**
     * The REAL recorder over a Connection that remembers instead of writing.
     *
     * A stub of the RECORDER would have made every assertion above impossible —
     * the whole subject of this file is how many rows exist and what is in them
     * — so the real class goes in and the connection under it is the double.
     */
    private function recorder(): AiCallRecorder
    {
        $connection = $this->createStub(Connection::class);

        $connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->recorded[] = $params;

                return 1;
            });

        return new AiCallRecorder($connection, new NullLogger());
    }

    /** One thread carrying $messageCount, which is all the refusal reads. */
    private function seedThread(int $messageCount): MessageThread
    {
        $user = new User();
        $user->email     = 'summary-stream-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Summary';
        $user->nameLast  = 'Stream';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'summary-stream-fixture@example.test';
        $account->username       = 'summary-stream-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Summary stream fixture';
        $thread->normalizedSubject = 'summary stream fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->messageCount      = $messageCount;
        $this->em->persist($thread);

        $this->em->flush();

        return $thread;
    }
}
