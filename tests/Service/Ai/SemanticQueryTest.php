<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\SemanticSkipReason;
use App\Service\Ai\AiCallRecorder;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\SemanticQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * When a search asks a model anything, and what it does when the answer does
 * not come.
 *
 * Half the contract is unchanged: whatever happens, the search behind this runs
 * exactly as it always has, because search must never be worse for somebody
 * than it was before this feature existed.
 *
 * The other half is new and is what these mostly assert. Every reason a vector
 * might be missing used to be the same null, so a switched-off feature and an
 * unplugged model host were indistinguishable to everything downstream — and
 * therefore indistinguishable to the person searching, who was told nothing in
 * either case. The reason now travels with the absence, and getting the WRONG
 * reason to a person is worse than getting none: "your query is too short" on
 * an installation whose model host is down would send somebody off to type more
 * words at a search that cannot answer.
 */
final class SemanticQueryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);

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

    public function testASwitchedOffInstallationAsksNothing(): void
    {
        $calls  = 0;
        $result = $this->query(enabled: false, calls: $calls)->forQuery($this->searcher(), 'quarterly figures');

        self::assertNull($result->literal);
        self::assertSame(SemanticSkipReason::FeatureOff, $result->skipped);
        self::assertSame(0, $calls);

        // ...and off is the one reason the page keeps to itself. An
        // installation with no model configured is a whole mail client, not a
        // degraded one, and a line under every search explaining which optional
        // feature is switched off is the nagging AiSettings refuses.
        self::assertFalse($result->skipped->tellsTheUser());
    }

    /**
     * A searcher who has switched search off asks nothing, and is told nothing.
     *
     * Silent on purpose — the two halves of that. Nothing is asked, because
     * this is one of the four places in src/ that build content for a model and
     * the user is in the signature of all four. And nothing is said, because it
     * is their own decision: a notice under the search box explaining a setting
     * somebody made themselves is the nagging AiSettings refuses.
     */
    public function testASearcherWhoHasSwitchedSearchOffAsksNothingAndIsToldNothing(): void
    {
        $calls    = 0;
        $searcher = $this->searcher();
        $searcher->aiPreferences->searchOff = true;

        $result = $this->query(calls: $calls)->forQuery($searcher, 'quarterly figures');

        self::assertNull($result->literal);
        self::assertSame(SemanticSkipReason::FeatureOff, $result->skipped);
        self::assertFalse($result->skipped->tellsTheUser());
        self::assertSame(0, $calls, 'an opted-out search still reached the model host');
    }

    /**
     * A principal the page could not resolve to a mailbox is refused too.
     *
     * An API token today, a guest later. It owns no vectors for a search to
     * match against, so there is nothing for a round trip to buy — and no
     * person to explain the absence to.
     */
    public function testAnUnrecognisedPrincipalAsksNothing(): void
    {
        $calls  = 0;
        $result = $this->query(calls: $calls)->forQuery(null, 'quarterly figures');

        self::assertSame(SemanticSkipReason::FeatureOff, $result->skipped);
        self::assertSame(0, $calls);
    }

    /**
     * Switched ON and unusable is not the same as switched off.
     *
     * enabledFor() answers false for both, which is why this was ever one
     * state. To the person searching they could not be less alike: the first is
     * a decision somebody made, the second is a setup nobody finished, and only
     * the second is worth interrupting a search to mention.
     */
    public function testSwitchedOnWithNoHostSaysSoInsteadOfSayingOff(): void
    {
        $calls  = 0;
        $result = $this->query(baseUrl: null, calls: $calls)->forQuery($this->searcher(), 'quarterly figures');

        self::assertSame(SemanticSkipReason::NotConfigured, $result->skipped);
        self::assertTrue($result->skipped->tellsTheUser());
        self::assertSame(0, $calls, 'there is no address to ask');
    }

    /**
     * Short queries are ceded to lexical search, which is better at them — and
     * not paid for with a round trip whose answer would be noise.
     */
    public function testAShortQueryIsNotWorthARoundTrip(): void
    {
        $calls = 0;
        $query = $this->query(calls: $calls);

        self::assertSame(SemanticSkipReason::QueryTooShort, $query->forQuery($this->searcher(), 'ab')->skipped);
        self::assertSame(0, $calls);

        // ...and a query with no words in it at all is a different thing. A
        // search that is nothing but operators — `is:unread` — arrives here as
        // an empty string, and "type a little more to search by meaning" is
        // advice about something the person was not doing. Silent.
        foreach (['   ', null] as $nothing) {
            $result = $query->forQuery($this->searcher(), $nothing);

            self::assertSame(SemanticSkipReason::NoFreeText, $result->skipped);
            self::assertFalse($result->skipped->tellsTheUser());
        }

        self::assertTrue(
            SemanticSkipReason::QueryTooShort->tellsTheUser(),
            'a query one word away from working is worth saying out loud',
        );
        self::assertSame(0, $calls);
    }

    public function testAUsableQueryComesBackAsAUnitLengthLiteral(): void
    {
        $result = $this->query()->forQuery($this->searcher(), 'quarterly figures');

        self::assertTrue($result->hasVector());
        self::assertNull($result->skipped);
        self::assertStringStartsWith('{', (string) $result->literal);

        // Normalised: [3,4] has length 5, so the stored form is [0.6, 0.8].
        self::assertSame('{0.6,0.8}', $result->literal);

        // The model and the width ride along, because the SQL matches on them:
        // vectors from two models are not comparable, and the search has to be
        // able to leave the ones that do not match alone rather than compare
        // across two spaces and rank the result.
        self::assertSame('nomic-embed-text', $result->model);
        self::assertSame(2, $result->dimensions, 'counted from the answer, not read from settings');
    }

    /**
     * A host that is off must make search exactly the search it always was —
     * not an error, not an empty result, not a warning.
     */
    public function testAnUnreachableHostSimplyAnswersNothing(): void
    {
        $result = $this->query(throws: true)->forQuery($this->searcher(), 'quarterly figures');

        self::assertNull($result->literal);
        self::assertSame(SemanticSkipReason::HostUnreachable, $result->skipped);
    }

    /**
     * A host that is there and a model that is not.
     *
     * Ollama answers 404 for a model it does not hold, and the client's two
     * attempts both end there. It is worth telling apart from an unreachable
     * host because the fix is a different one and belongs to a different
     * person: one is a cable, the other is `ollama pull`.
     */
    public function testAMissingModelIsNotAMissingHost(): void
    {
        $result = $this->query(status: 404)->forQuery($this->searcher(), 'quarterly figures');

        self::assertSame(SemanticSkipReason::ModelMissing, $result->skipped);
    }

    /** A vector that cannot be normalised is not a vector. */
    public function testAZeroVectorIsRefused(): void
    {
        $result = $this->query(vector: [0, 0, 0])->forQuery($this->searcher(), 'quarterly figures');

        self::assertNull($result->literal);
        self::assertSame(SemanticSkipReason::ModelAnsweredBadly, $result->skipped);
    }

    /**
     * @param list<float|int> $vector
     */
    private function query(
        bool $enabled = true,
        array $vector = [3, 4],
        bool $throws = false,
        int &$calls = 0,
        ?string $baseUrl = 'http://10.0.0.5:11434',
        int $status = 200,
        ?string $instruction = null,
        float $minSimilarity = AiSettings::DEFAULT_MIN_SIMILARITY,
        ?string &$sentInput = null,
    ): SemanticQuery {

        $settings = new AiSettings();
        $settings->isEnabled              = $enabled;
        $settings->baseUrl                = $baseUrl;
        $settings->embeddingModel         = 'nomic-embed-text';
        $settings->searchEnabled          = $enabled;
        $settings->searchQueryInstruction = $instruction;
        $settings->semanticMinSimilarity  = $minSimilarity;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$sentInput, $vector, $throws, $status): MockResponse {
            ++$calls;

            // The body as it actually goes on the wire, which is the only place
            // the instruction can be checked: everything above it is plMail's
            // own strings, and the question is what the MODEL was asked.
            $body      = json_decode((string) ($options['body'] ?? '{}'), true);
            $sentInput = is_array($body) ? ($body['input'] ?? null) : null;

            if (true === $throws) {
                throw new TransportException('Connection refused');
            }

            return new MockResponse(
                json_encode(['embeddings' => [$vector]]),
                ['http_code' => $status],
            );
        });

        $ai = new AiAssistant(
            $this->settings,
            new OllamaClient($http, new NullLogger()),
            $this->recorder(),
            new NullLogger(),
        );

        return new SemanticQuery($ai, new AiPermissions($ai), new NullLogger());
    }

    /**
     * The instruction reaches the model, in front of what somebody typed.
     *
     * THIS IS THE WHOLE OF SEARCH QUALITY, in one string. Embedding models are
     * asymmetric — trained to be told which side of a search they are looking
     * at — and an unlabelled query is treated as one more document. A mailbox
     * is mostly boilerplate, so an unlabelled query lands nearest the most
     * boilerplate thing in it. Measured on qwen3-embedding:0.6b over twelve
     * messages: "mails about food" put five newsletters and login links above
     * every mail that was about food, and "essen" put all seven of them above
     * all five. With the instruction, none, and precision went 0.42 to 1.00.
     *
     * Asserted on the request body rather than on a score, because a score is
     * the model's opinion and this is plMail's contract: the configured string,
     * then the query, concatenated, once.
     */
    public function testTheQueryInstructionIsSentInFrontOfTheQuery(): void
    {
        $sent = null;
        $query = $this->query(
            instruction: "Instruct: Given a search query, retrieve email messages\nQuery: ",
            sentInput: $sent,
        );

        $query->forQuery($this->searcher(), 'mails about food');

        self::assertSame(
            "Instruct: Given a search query, retrieve email messages\nQuery: mails about food",
            $sent,
            'the instruction goes in front of the query, and the newline survives',
        );
    }

    /**
     * And an installation that has not set one sends exactly what was typed.
     *
     * Null has to stay a working configuration rather than a half-finished one:
     * some models were not trained to be instructed, and prefixing those adds
     * tokens that mean nothing to them and move every score by an amount nobody
     * has measured. It is also every installation that upgrades into this.
     */
    public function testWithoutAnInstructionTheQueryIsSentUnchanged(): void
    {
        $sent = null;
        $query = $this->query(instruction: null, sentInput: $sent);

        $query->forQuery($this->searcher(), 'mails about food');

        self::assertSame('mails about food', $sent);
    }

    /**
     * The threshold travels with the vector.
     *
     * It was a constant in MessageThreadRepository, one number for every
     * installation, and cosine similarity is not comparable between models —
     * measured, qwen3-embedding:0.6b wants 0.42 with its instruction and 0.20
     * without, all-minilm wants 0.20, nomic-embed-text 0.45. A search that
     * carried the vector but not the scale it lives on would filter it with
     * somebody else's number.
     */
    public function testTheConfiguredThresholdTravelsWithTheVector(): void
    {
        $search = $this->query(minSimilarity: 0.31)->forQuery($this->searcher(), 'mails about food');

        self::assertTrue($search->hasVector());
        self::assertSame(0.31, $search->minSimilarity);
    }

    /**
     * A skipped pass has no threshold, because it has nothing to filter.
     *
     * Pinned so the repository never has to ask whether a reason-carrying
     * result also carries a number: null vector and null threshold are the same
     * state, and they cannot come apart.
     */
    public function testASkippedPassCarriesNoThreshold(): void
    {
        $search = $this->query(enabled: false)->forQuery($this->searcher(), 'mails about food');

        self::assertFalse($search->hasVector());
        self::assertNull($search->minSimilarity);
    }

    /**
     * A searcher who has not opted out of anything.
     *
     * Never persisted: AiPermissions reads the embeddable straight off the
     * object, so a row would buy nothing and would need cleaning up.
     */
    private function searcher(): User
    {
        return new User();
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
