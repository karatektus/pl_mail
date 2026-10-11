<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\AiProvider;
use App\Domain\Enum\Ai\MetricWindow;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiCallMetricRepository;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiPerformancePanel;
use App\Service\Ai\EmbeddingBackfill;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the model host card is told, for each kind of host.
 *
 * The card is polled every five seconds for as long as the page is open, so a
 * wrong reading here is not a wrong line once — it is an installation that
 * works being described as broken for as long as anybody looks at it.
 */
final class AiPerformancePanelTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);

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

    /** The reading every existing installation gets, unchanged. */
    public function testAnOllamaHostIsAskedWhatItHoldsAndWhatIsLoaded(): void
    {
        $asked    = [];
        $snapshot = $this->panel($this->settings(AiProvider::Ollama, 'http://10.0.0.5:11434'), $asked, [
            '/api/tags'    => ['models' => [['name' => 'writer:latest']]],
            '/api/version' => ['version' => '0.6.2'],
            '/api/ps'      => ['models' => [['name' => 'writer:latest', 'size' => 10, 'size_vram' => 10]]],
        ])->snapshot(MetricWindow::Hour);

        self::assertTrue($snapshot['host']['reachable']);
        self::assertTrue($snapshot['host']['managesMemory']);
        self::assertCount(1, $snapshot['loaded']);
        self::assertTrue($snapshot['models'][0]['installed']);
        self::assertFalse($snapshot['models'][1]['installed'], 'a model Ollama does not list has not been pulled');
        self::assertContains('http://10.0.0.5:11434/api/ps', $asked);
    }

    /**
     * An OpenAI-compatible host is asked for its model list and nothing else.
     *
     * No `/api/ps`: that is an Ollama path, and on any other server it is a 404
     * every five seconds in somebody's access log.
     */
    public function testAnOpenAiCompatibleHostIsNotAskedAboutMemory(): void
    {
        $asked    = [];
        $snapshot = $this->panel($this->settings(AiProvider::OpenAi, 'https://api.example.test/v1'), $asked, [
            '/v1/models' => ['data' => [['id' => 'writer']]],
        ])->snapshot(MetricWindow::Hour);

        self::assertSame(['https://api.example.test/v1/models'], $asked);
        self::assertTrue($snapshot['host']['reachable']);
        self::assertFalse($snapshot['host']['managesMemory']);
        self::assertSame('openai', $snapshot['host']['provider']);
        self::assertSame([], $snapshot['loaded']);
        self::assertNull($snapshot['coldLoadMs']);
        self::assertTrue($snapshot['models'][0]['installed']);
    }

    /**
     * A server with no model list is still a server that answers.
     *
     * `/models` is optional in practice. Reported as "no answer", a working
     * installation would show a dead host on this card for good.
     */
    public function testAHostWithNoModelListIsNotReportedAsDown(): void
    {
        $asked    = [];
        $snapshot = $this->panel($this->settings(AiProvider::OpenAi, 'https://api.example.test/v1'), $asked, [])
            ->snapshot(MetricWindow::Hour);

        self::assertTrue($snapshot['host']['reachable']);
        self::assertSame('no_model_list', $snapshot['host']['reason']);
        self::assertNull($snapshot['models'][0]['installed'], 'nothing was listed, so nothing is known to be missing');
    }

    /** A list that is there but empty says nothing about a model either. */
    public function testAnEmptyListDoesNotMarkTheConfiguredModelsMissing(): void
    {
        $asked    = [];
        $snapshot = $this->panel($this->settings(AiProvider::OpenAi, 'https://api.example.test/v1'), $asked, [
            '/v1/models' => ['data' => []],
        ])->snapshot(MetricWindow::Hour);

        self::assertNull($snapshot['models'][0]['installed']);
    }

    /**
     * A search model that lives on another host is unknown here, not missing.
     *
     * The card reads the host that writes. "Not on this host" beside a search
     * model that is exactly where it was put would send somebody to pull it
     * onto the wrong machine.
     */
    public function testASearchModelOnAnotherHostIsNotReportedMissing(): void
    {
        $settings = $this->settings(AiProvider::Ollama, 'http://10.0.0.5:11434');
        $settings->embeddingOwnConnection = true;
        $settings->embeddingBaseUrl       = 'http://10.0.0.9:11434';
        $this->em->flush();

        $asked    = [];
        $snapshot = $this->panel($settings, $asked, [
            '/api/tags'    => ['models' => [['name' => 'writer:latest']]],
            '/api/version' => ['version' => '0.6.2'],
            '/api/ps'      => ['models' => [['name' => 'embedder:latest']]],
        ])->snapshot(MetricWindow::Hour);

        self::assertTrue($snapshot['models'][0]['installed']);
        self::assertNull($snapshot['models'][1]['installed']);
        self::assertFalse($snapshot['models'][1]['resident'], 'a model of that name in THIS host\'s memory is not the one search uses');

        foreach ($asked as $url) {
            self::assertStringStartsWith('http://10.0.0.5:11434/', $url, 'the card reads the host that writes');
        }
    }

    /** A refused key is a host the card cannot vouch for. */
    public function testARefusedKeyIsNotAnAnswer(): void
    {
        $asked = [];
        $panel = new AiPerformancePanel(
            self::getContainer()->get(AiSettingsRepository::class),
            new OllamaClient(new MockHttpClient([]), new NullLogger()),
            new OpenAiClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])), new NullLogger()),
            self::getContainer()->get(AiCallMetricRepository::class),
            self::getContainer()->get(EmbeddingBackfill::class),
        );

        $this->settings(AiProvider::OpenAi, 'https://api.example.test/v1');

        $snapshot = $panel->snapshot(MetricWindow::Hour);

        self::assertFalse($snapshot['host']['reachable']);
        self::assertSame('refused', $snapshot['host']['reason']);
    }

    private function settings(AiProvider $provider, string $address): AiSettings
    {
        $settings = new AiSettings();
        $settings->isEnabled      = true;
        $settings->provider       = $provider;
        $settings->baseUrl        = $address;
        $settings->chatModel      = 'writer';
        $settings->embeddingModel = 'embedder';

        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    /**
     * @param list<string>                        $asked   every URL requested, in order
     * @param array<string, array<string, mixed>> $answers JSON by path suffix; anything else is a 404
     */
    private function panel(AiSettings $settings, array &$asked, array $answers): AiPerformancePanel
    {
        $http = new MockHttpClient(static function (string $method, string $url) use (&$asked, $answers): MockResponse {
            $asked[] = $url;

            foreach ($answers as $suffix => $body) {
                if (true === str_ends_with($url, $suffix)) {
                    return new MockResponse((string) json_encode($body));
                }
            }

            return new MockResponse('Not Found', ['http_code' => 404]);
        });

        return new AiPerformancePanel(
            self::getContainer()->get(AiSettingsRepository::class),
            new OllamaClient($http, new NullLogger()),
            new OpenAiClient($http, new NullLogger()),
            self::getContainer()->get(AiCallMetricRepository::class),
            self::getContainer()->get(EmbeddingBackfill::class),
        );
    }
}
