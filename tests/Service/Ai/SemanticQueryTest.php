<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
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
 * Null is the whole contract here, and it has to mean one thing: run the search
 * that has always run. Every reason a vector might be unavailable — off,
 * unconfigured, host down, query too short — has to arrive at the same answer,
 * because search must never be worse for somebody than it was before this
 * feature existed.
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
        $calls = 0;

        self::assertNull($this->query(enabled: false, calls: $calls)->literalFor('quarterly figures'));
        self::assertSame(0, $calls);
    }

    /**
     * Short queries are ceded to lexical search, which is better at them — and
     * not paid for with a round trip whose answer would be noise.
     */
    public function testAShortQueryIsNotWorthARoundTrip(): void
    {
        $calls = 0;
        $query = $this->query(calls: $calls);

        self::assertNull($query->literalFor('ab'));
        self::assertNull($query->literalFor('   '));
        self::assertNull($query->literalFor(null));
        self::assertSame(0, $calls);
    }

    public function testAUsableQueryComesBackAsAUnitLengthLiteral(): void
    {
        $literal = $this->query()->literalFor('quarterly figures');

        self::assertIsString($literal);
        self::assertStringStartsWith('{', $literal);

        // Normalised: [3,4] has length 5, so the stored form is [0.6, 0.8].
        self::assertSame('{0.6,0.8}', $literal);
    }

    /**
     * A host that is off must make search exactly the search it always was —
     * not an error, not an empty result, not a warning.
     */
    public function testAnUnreachableHostSimplyAnswersNothing(): void
    {
        $query = $this->query(throws: true);

        self::assertNull($query->literalFor('quarterly figures'));
    }

    /** A vector that cannot be normalised is not a vector. */
    public function testAZeroVectorIsRefused(): void
    {
        self::assertNull($this->query(vector: [0, 0, 0])->literalFor('quarterly figures'));
    }

    /**
     * @param list<float|int> $vector
     */
    private function query(
        bool $enabled = true,
        array $vector = [3, 4],
        bool $throws = false,
        int &$calls = 0,
    ): SemanticQuery {

        $settings = new AiSettings();
        $settings->isEnabled      = $enabled;
        $settings->baseUrl        = 'http://10.0.0.5:11434';
        $settings->embeddingModel = 'nomic-embed-text';
        $settings->searchEnabled  = $enabled;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function () use (&$calls, $vector, $throws): MockResponse {
            ++$calls;

            if (true === $throws) {
                throw new TransportException('Connection refused');
            }

            return new MockResponse(json_encode(['embeddings' => [$vector]]));
        });

        return new SemanticQuery(
            new AiAssistant($this->settings, new OllamaClient($http, new NullLogger()), new NullLogger()),
            new NullLogger(),
        );
    }
}
