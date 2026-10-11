<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\DTO\Ai\AiConnection;
use App\Domain\Enum\Ai\AiCallFeature;
use App\Domain\Enum\Ai\AiProvider;
use App\Service\Ai\AiCallRecorder;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Off means off, and it means it without asking the network.
 *
 * This is the file that matters most in the AI feature set. plMail is a
 * complete mail client with all of this switched off, which is the state every
 * existing installation is in and the state most will stay in — so the
 * expensive thing to get wrong is not a bad completion, it is a request being
 * made at all by an install that never turned this on.
 *
 * So the assertions are mostly about what did NOT happen: no HTTP call, no
 * exception, and a null the caller already knows how to handle because the host
 * can equally be switched off at the wall.
 */
final class AiAssistantTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(AiSettingsRepository::class);

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

    public function testAFreshInstallationAsksNothingOfAnybody(): void
    {
        $calls = 0;

        $assistant = $this->assistant(new AiSettings(), $calls);

        self::assertNull($assistant->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertNull($assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]));
        self::assertSame(0, $calls, 'a switched-off installation must not touch the network');
    }

    /**
     * The commonest half-finished state: switched on, nothing configured. It
     * has to behave exactly like switched off rather than producing a feature
     * that appears to exist and never answers.
     */
    public function testEnabledWithNoHostIsStillOff(): void
    {
        $settings = new AiSettings();
        $settings->isEnabled     = true;
        $settings->searchEnabled = true;

        $calls = 0;

        self::assertNull($this->assistant($settings, $calls)->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertSame(0, $calls);
    }

    /** The other half-finished state: a host, but no model named for the job. */
    public function testEnabledWithNoModelIsStillOff(): void
    {
        $settings = $this->configured();
        $settings->embeddingModel = null;

        $calls = 0;

        self::assertNull($this->assistant($settings, $calls)->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertSame(0, $calls);
    }

    /**
     * The master switch overrules the per-feature ones, so an administrator can
     * stop everything in one action without losing the configuration beneath
     * it.
     */
    public function testTheMasterSwitchOverrulesTheFeatureFlags(): void
    {
        $settings = $this->configured();
        $settings->isEnabled = false;

        $calls = 0;

        self::assertNull($this->assistant($settings, $calls)->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertNull($this->assistant($settings, $calls)->chat(AiFeature::Categorise, [['role' => 'user', 'content' => 'x']]));
        self::assertSame(0, $calls);
    }

    /** Each feature is separately switchable, because they have different costs. */
    public function testOneFeatureBeingOnDoesNotSwitchOnAnother(): void
    {
        $settings = $this->configured();
        $settings->searchEnabled         = true;
        $settings->categorisationEnabled = false;
        $settings->writingHelpEnabled    = false;

        $calls     = 0;
        $assistant = $this->assistant($settings, $calls);

        self::assertTrue($assistant->isEnabledFor(AiFeature::Search));
        self::assertFalse($assistant->isEnabledFor(AiFeature::Categorise));
        self::assertFalse($assistant->isEnabledFor(AiFeature::WritingHelp));
    }

    public function testAConfiguredFeatureActuallyAsks(): void
    {
        $settings = $this->configured();
        $settings->searchEnabled = true;

        $calls = 0;

        self::assertSame([1.0, 2.0], $this->assistant($settings, $calls)->embed(AiCallFeature::SearchQuery, 'hello'));
        self::assertGreaterThan(0, $calls);
    }

    /**
     * Search uses the embedding model. A caller asking for a completion on its
     * behalf is a bug, and answering it with the chat model would hide that bug
     * behind a plausible result.
     */
    public function testAskingForACompletionOnSearchesBehalfIsRefused(): void
    {
        $settings = $this->configured();
        $settings->searchEnabled = true;
        $settings->writingHelpEnabled = true;

        $calls = 0;

        self::assertNull(
            $this->assistant($settings, $calls)->chat(AiFeature::Search, [['role' => 'user', 'content' => 'x']]),
        );
        self::assertSame(0, $calls);
    }

    /** Empty input is not worth a round trip to another machine. */
    public function testEmptyInputIsNotSentAnywhere(): void
    {
        $settings = $this->configured();
        $settings->searchEnabled      = true;
        $settings->writingHelpEnabled = true;

        $calls     = 0;
        $assistant = $this->assistant($settings, $calls);

        self::assertNull($assistant->embed(AiCallFeature::SearchQuery, '   '));
        self::assertNull($assistant->chat(AiFeature::WritingHelp, []));
        self::assertSame(0, $calls);
    }

    /**
     * The test button has to work mid-configuration, before anything is saved
     * and before the feature is switched on — that is the only moment its
     * answer is useful.
     */
    public function testProbeIgnoresTheMasterSwitchAndAcceptsAnOverride(): void
    {
        $calls = 0;

        $probe = $this->assistant(new AiSettings(), $calls)->probe(new AiConnection('http://10.0.0.9:11434'));

        self::assertTrue($probe->reachable, 'an administrator setting this up has not switched it on yet');
        self::assertGreaterThan(0, $calls);
    }

    public function testProbeWithNoHostAnywhereSaysSo(): void
    {
        $calls = 0;

        $probe = $this->assistant(new AiSettings(), $calls)->probe();

        self::assertFalse($probe->reachable);
        self::assertSame('no_host', $probe->reason);
        self::assertSame(0, $calls);
    }

    /**
     * The key in Admin → AI is shown to the host, on every kind of request.
     *
     * It was stored and never sent. An Ollama behind a reverse proxy that asks
     * for a token answered 401 to all of it, and nothing in plMail said why:
     * the field was filled in, the page said a token was on file.
     *
     * Every request is checked rather than one, because the ones a caller does
     * not see are the ones a per-call argument would have missed — the version
     * read behind a probe, and the residency read in front of a draft.
     */
    public function testTheStoredKeyGoesWithEveryRequest(): void
    {
        $settings = $this->configured();
        $settings->apiToken           = 'proxy-key';
        $settings->searchEnabled      = true;
        $settings->writingHelpEnabled = true;

        $seen      = [];
        $assistant = $this->assistant($settings, headers: $seen);

        $assistant->embed(AiCallFeature::SearchQuery, 'anything');
        $assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]);
        $assistant->isModelResident(AiFeature::WritingHelp);
        $assistant->probe();
        $assistant->warmUp();

        self::assertGreaterThanOrEqual(6, count($seen), 'embed, chat, ps, tags, version and generate');

        foreach ($seen as $url => $headers) {
            self::assertStringContainsString('Authorization: Bearer proxy-key', $headers, $url);
        }
    }

    /**
     * The extra headers go wherever the token goes, in either dialect.
     *
     * They are for whatever stands in front of the model, and that thing sees
     * every request — so one that arrives without them is one it turns away.
     */
    public function testTheExtraHeadersGoWithEveryRequestInBothDialects(): void
    {
        $ollama = $this->configured();
        $ollama->headers            = '{"CF-Access-Client-Id":"an-id","CF-Access-Client-Secret":"a-secret"}';
        $ollama->searchEnabled      = true;
        $ollama->writingHelpEnabled = true;

        $openAi = $this->openAi();
        $openAi->headers = $ollama->headers;

        foreach ([$ollama, $openAi] as $settings) {
            $seen      = [];
            $assistant = $this->assistant($settings, headers: $seen);

            $assistant->embed(AiCallFeature::SearchQuery, 'anything');
            $assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]);
            $assistant->probe();

            self::assertGreaterThanOrEqual(3, count($seen));

            foreach ($seen as $url => $headers) {
                // Lower case: the HTTP client normalises the names it is given.
                self::assertStringContainsStringIgnoringCase('CF-Access-Client-Id: an-id', $headers, $url);
                self::assertStringContainsStringIgnoringCase('CF-Access-Client-Secret: a-secret', $headers, $url);
            }
        }
    }

    /** No key, no header: an Ollama on the LAN is asked exactly as it was. */
    public function testAHostWithNoKeyIsSentNoAuthorization(): void
    {
        $settings = $this->configured();
        $settings->writingHelpEnabled = true;

        $seen      = [];
        $assistant = $this->assistant($settings, headers: $seen);

        $assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]);

        self::assertCount(1, $seen);
        self::assertStringNotContainsStringIgnoringCase('authorization', (string) reset($seen));
    }

    /**
     * A test of an address shows it the key that came with it, not the stored
     * one. The stored key belongs to the stored address.
     */
    public function testATestedConnectionCarriesItsOwnKey(): void
    {
        $settings = $this->configured();
        $settings->apiToken = 'stored-key';

        $seen = [];

        $this->assistant($settings, headers: $seen)->probe(new AiConnection('http://10.0.0.9:11434', 'typed-key'));

        foreach ($seen as $url => $headers) {
            self::assertStringStartsWith('http://10.0.0.9:11434', $url);
            self::assertStringContainsString('Bearer typed-key', $headers);
            self::assertStringNotContainsString('stored-key', $headers);
        }
    }

    /**
     * The provider decides which dialect every feature is asked in.
     *
     * One setting, and it has to reach all of them: a draft, a vector and a
     * test of the address. Asked at Ollama's paths, an OpenAI-compatible server
     * answers 404 to each and the installation looks misconfigured in three
     * different places.
     */
    public function testAnOpenAiCompatibleHostIsAskedInItsOwnDialect(): void
    {
        $settings = $this->openAi();

        $seen      = [];
        $assistant = $this->assistant($settings, headers: $seen);

        self::assertSame('an answer', $assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]));
        self::assertSame([1.0, 2.0], $assistant->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertTrue($assistant->probe()->reachable);

        self::assertSame([
            'https://api.example.test/v1/chat/completions',
            'https://api.example.test/v1/embeddings',
            'https://api.example.test/v1/models',
        ], array_keys($seen));

        foreach ($seen as $url => $headers) {
            self::assertStringContainsString('Bearer sk-test', $headers, $url);
        }
    }

    /** The streamed draft goes the same way, and still comes back as tokens. */
    public function testAStreamedDraftUsesTheSameDialect(): void
    {
        $seen   = [];
        $tokens = $this->assistant($this->openAi(), headers: $seen)
            ->chatStream(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']], numCtx: 32768);

        self::assertNotNull($tokens);
        self::assertSame(['Dear ', 'Anna'], iterator_to_array($tokens, false));
        self::assertSame(['https://api.example.test/v1/chat/completions'], array_keys($seen));
    }

    /**
     * What only Ollama can be asked is not asked of anything else.
     *
     * The residency read and the warm-up are both requests to Ollama paths. On
     * another server they would be two 404s in front of every draft — and the
     * first would make the composer promise a long cold start that is not
     * coming.
     */
    public function testNothingAboutMemoryIsAskedOfAHostThatCannotAnswer(): void
    {
        $calls     = 0;
        $assistant = $this->assistant($this->openAi(), $calls);

        self::assertTrue($assistant->isModelResident(AiFeature::WritingHelp), 'no cold start is promised');
        self::assertSame('not_ollama', $assistant->warmUp()->reason);
        self::assertSame(0, $calls);
    }

    /** A stored keep-alive survives a change of provider and is simply not used. */
    public function testAKeepAliveIsNotOfferedToADialectWithNoWordForIt(): void
    {
        $settings = $this->openAi();
        $settings->chatKeepAlive = '30m';

        self::assertNull($settings->keepAliveFor(AiFeature::WritingHelp));
        self::assertNull($settings->keepAliveFor(AiFeature::Search));

        $settings->provider = AiProvider::Ollama;

        self::assertSame('30m', $settings->keepAliveFor(AiFeature::WritingHelp), 'and it is still there afterwards');
    }

    /**
     * Search on a host of its own: vectors from there, everything else from
     * the first host, and neither shown the other's credentials.
     *
     * The arrangement the second host exists for — writing rented, search at
     * home. The mailbox is what gets embedded, so the request that must not go
     * to the wrong address is the embedding one, and the credential that must
     * not travel with it is the hosted provider's key.
     */
    public function testSearchCanRunOnAHostOfItsOwn(): void
    {
        $settings = $this->openAi();
        $settings->headers                = '{"X-Project":"mail"}';
        $settings->embeddingOwnConnection = true;
        $settings->embeddingProvider      = AiProvider::Ollama;
        $settings->embeddingBaseUrl       = 'http://10.0.0.5:11434';
        $settings->embeddingApiToken      = 'home-token';
        $settings->embeddingHeaders       = '{"X-Home":"yes"}';

        $seen      = [];
        $assistant = $this->assistant($settings, headers: $seen);

        self::assertSame([1.0, 2.0], $assistant->embed(AiCallFeature::SearchQuery, 'anything'));
        self::assertSame('an answer', $assistant->chat(AiFeature::WritingHelp, [['role' => 'user', 'content' => 'hi']]));

        self::assertSame(
            ['http://10.0.0.5:11434/api/embed', 'https://api.example.test/v1/chat/completions'],
            array_keys($seen),
        );

        $embed = $seen['http://10.0.0.5:11434/api/embed'];
        $chat  = $seen['https://api.example.test/v1/chat/completions'];

        self::assertStringContainsString('Bearer home-token', $embed);
        self::assertStringContainsStringIgnoringCase('X-Home: yes', $embed);
        self::assertStringNotContainsString('sk-test', $embed, 'the hosted key stays with the hosted address');
        self::assertStringNotContainsStringIgnoringCase('X-Project', $embed);

        self::assertStringContainsString('Bearer sk-test', $chat);
        self::assertStringNotContainsString('home-token', $chat);
        self::assertStringNotContainsStringIgnoringCase('X-Home', $chat);
    }

    /**
     * A search host with no token is sent no token — not the other host's.
     *
     * "None" is a complete answer for an Ollama on the LAN. Falling back to
     * the writing host's key would post a hosted provider's credential to a
     * machine that never asked for one.
     */
    public function testASearchHostWithoutATokenDoesNotBorrowOne(): void
    {
        $settings = $this->openAi();
        $settings->embeddingOwnConnection = true;
        $settings->embeddingBaseUrl       = 'http://10.0.0.5:11434';

        $seen = [];

        $this->assistant($settings, headers: $seen)->embed(AiCallFeature::SearchQuery, 'anything');

        self::assertSame(['http://10.0.0.5:11434/api/embed'], array_keys($seen));
        self::assertStringNotContainsStringIgnoringCase('authorization', (string) reset($seen));
    }

    /**
     * Each feature is usable when ITS host is filled in.
     *
     * Search switched to its own host and that host left empty is search
     * switched off — whatever the writing host says. And the reverse: a search
     * host on its own is enough for search, with no writing host at all.
     */
    public function testAFeatureNeedsTheHostItRunsOn(): void
    {
        $settings = $this->openAi();
        $settings->embeddingOwnConnection = true;

        self::assertFalse($settings->enabledFor(AiFeature::Search), 'its own host was never typed');
        self::assertTrue($settings->enabledFor(AiFeature::WritingHelp));

        $settings->embeddingBaseUrl = 'http://10.0.0.5:11434';
        $settings->baseUrl          = null;

        self::assertTrue($settings->enabledFor(AiFeature::Search));
        self::assertFalse($settings->enabledFor(AiFeature::WritingHelp));
        self::assertTrue($settings->isConfigured(), 'a search host alone is still a configured installation');
    }

    /** The second host's fields mean nothing until the switch says so. */
    public function testTheSecondHostIsIgnoredWhileItIsSwitchedOff(): void
    {
        $settings = $this->openAi();
        $settings->embeddingProvider = AiProvider::Ollama;
        $settings->embeddingBaseUrl  = 'http://10.0.0.5:11434';
        $settings->embeddingApiToken = 'home-token';

        $connection = $settings->connectionFor(AiFeature::Search);

        self::assertSame('https://api.example.test/v1', $connection->baseUrl);
        self::assertSame(AiProvider::OpenAi, $connection->provider);
        self::assertSame('sk-test', $connection->token);
    }

    /** The search keep-alive goes to an Ollama search host even when writing is elsewhere. */
    public function testTheSearchKeepAliveFollowsTheSearchHost(): void
    {
        $settings = $this->openAi();
        $settings->embeddingKeepAlive     = '-1';
        $settings->embeddingOwnConnection = true;
        $settings->embeddingProvider      = AiProvider::Ollama;
        $settings->embeddingBaseUrl       = 'http://10.0.0.5:11434';

        self::assertSame('-1', $settings->keepAliveFor(AiFeature::Search));
        self::assertNull($settings->keepAliveFor(AiFeature::WritingHelp));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Everything on, at an OpenAI-compatible address with a key. */
    private function openAi(): AiSettings
    {
        $settings = $this->configured();
        $settings->provider           = AiProvider::OpenAi;
        $settings->baseUrl            = 'https://api.example.test/v1';
        $settings->apiToken           = 'sk-test';
        $settings->searchEnabled      = true;
        $settings->writingHelpEnabled = true;

        return $settings;
    }

    /** Switched on, a host, and both models named. */
    private function configured(): AiSettings
    {
        $settings = new AiSettings();
        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://10.0.0.5:11434';
        $settings->chatModel      = 'llama3.1:8b';
        $settings->embeddingModel = 'nomic-embed-text';

        return $settings;
    }

    /**
     * The real repository against the real table, because it is final and
     * because the wiring is part of what is being tested. Only the transport is
     * a stand-in — that is the thing whose absence the assertions are about.
     */
    /**
     * @param array<string, string> $headers the request headers each URL was asked with, by URL
     */
    private function assistant(AiSettings $settings, int &$calls = 0, array &$headers = []): AiAssistant
    {

        $this->connection->executeStatement('DELETE FROM ai_settings');
        $this->em->clear();
        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$headers): MockResponse {
            ++$calls;

            $headers[$url] = implode("\n", $options['headers'] ?? []);

            // The OpenAI-compatible paths, answered in that format. Before the
            // Ollama ones, because `/v1/embeddings` contains `/embed` too.
            if (true === str_ends_with($url, '/v1/models')) {
                return new MockResponse((string) json_encode(['data' => [['id' => 'llama3.1:8b']]]));
            }

            if (true === str_ends_with($url, '/v1/embeddings')) {
                return new MockResponse((string) json_encode(['data' => [['embedding' => [1, 2]]]]));
            }

            if (true === str_ends_with($url, '/v1/chat/completions')) {
                $body = json_decode((string) ($options['body'] ?? ''), true);

                return true === ($body['stream'] ?? false)
                    ? new MockResponse(
                        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Dear ']]]]) . "\n\n"
                        . 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Anna']]]]) . "\n\n"
                        . "data: [DONE]\n\n",
                    )
                    : new MockResponse((string) json_encode(['choices' => [['message' => ['content' => 'an answer']]]]));
            }

            if (true === str_contains($url, '/api/tags')) {
                return new MockResponse(json_encode(['models' => [['name' => 'llama3.1:8b']]]));
            }

            if (true === str_contains($url, '/api/embed')) {
                return new MockResponse(json_encode(['embeddings' => [[1, 2]]]));
            }

            return new MockResponse(json_encode(['message' => ['content' => 'an answer']]));
        });

        return new AiAssistant(
            $this->repository,
            new OllamaClient($http, new NullLogger()),
            new OpenAiClient($http, new NullLogger()),
            $this->recorder(),
            new NullLogger(),
        );
    }

    /**
     * A recorder whose database is a mock.
     *
     * A stub rather than a mock, deliberately: nothing here asserts anything
     * about the metrics write, and PHPUnit is right to say so. These are unit
     * tests over an HTTP mock, there is no database to write to, and the row is
     * not what any of them are about.
     *
     * The REAL recorder rather than a fake, so that a change to its constructor
     * breaks here, where it is cheap, instead of in production.
     */
    private function recorder(): AiCallRecorder
    {
        return new AiCallRecorder($this->createStub(Connection::class), new NullLogger());
    }
}
