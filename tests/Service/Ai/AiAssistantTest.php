<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\AiCallFeature;
use App\Service\Ai\AiCallRecorder;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\OllamaClient;
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

        $probe = $this->assistant(new AiSettings(), $calls)->probe('http://10.0.0.9:11434');

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

    // ── fixtures ─────────────────────────────────────────────────────────────

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
    private function assistant(AiSettings $settings, int &$calls = 0): AiAssistant
    {

        $this->connection->executeStatement('DELETE FROM ai_settings');
        $this->em->clear();
        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;

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
