<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\WritingAssistant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the composer is allowed to ask for, and what it refuses to ask.
 *
 * The refusals are the interesting half. This produces text that goes into
 * somebody's outgoing mail, so the failure that matters is not a clumsy
 * sentence — it is a model inventing a message on the writer's behalf because
 * it was handed nothing to work from, or a request that was never supposed to
 * be answerable being answered.
 */
final class WritingAssistantTest extends KernelTestCase
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

    public function testAnUnconfiguredInstallationOffersNothing(): void
    {
        $assistant = $this->assistant(enabled: false);

        self::assertFalse($assistant->isAvailable());
        self::assertNull($assistant->write(WritingTask::Shorten, 'some text', null));
    }

    /**
     * A reply can be drafted from the message being answered alone — the common
     * case, an empty composer under a mail.
     */
    public function testAReplyCanBeDraftedFromTheMessageAloneWithNoDraft(): void
    {
        $answer = $this->assistant()->write(WritingTask::Reply, '', 'Can you send the invoice?');

        self::assertSame('an answer', $answer);
    }

    /**
     * The other three operate ON the draft. Handed nothing, a model invents a
     * message and it lands in somebody's outgoing mail — so nothing is sent.
     */
    public function testTheRewritingTasksRefuseAnEmptyDraft(): void
    {
        $calls     = 0;
        $assistant = $this->assistant(calls: $calls);

        foreach ([WritingTask::Shorten, WritingTask::Formal, WritingTask::Proofread] as $task) {
            self::assertNull($assistant->write($task, '   ', 'a message being replied to'));
        }

        self::assertSame(0, $calls, 'nothing to work from is not a question worth asking');
    }

    /** And a reply with neither a draft nor anything to answer is refused too. */
    public function testAReplyWithNothingAtAllIsRefused(): void
    {
        self::assertNull($this->assistant()->write(WritingTask::Reply, '', ''));
    }

    /**
     * Chat-tuned models wrap answers in code fences. Pasting the fence into an
     * email is worse than the model having been slightly wrong.
     */
    public function testACodeFenceIsStrippedFromTheAnswer(): void
    {
        $assistant = $this->assistant(answer: "```\nDear Kim,\n\nThanks.\n```");

        self::assertSame("Dear Kim,\n\nThanks.", $assistant->write(WritingTask::Reply, '', 'hello'));
    }

    /** Prose that merely mentions backticks is left alone — guessing would delete lines. */
    public function testOrdinaryProseIsNotTrimmed(): void
    {
        $assistant = $this->assistant(answer: 'Use the `--force` flag.');

        self::assertSame('Use the `--force` flag.', $assistant->write(WritingTask::Reply, '', 'hello'));
    }

    /**
     * The proofreader must not be given room to be creative — it rewrites
     * sentences that were already correct.
     */
    public function testTheTemperatureMatchesTheTask(): void
    {
        self::assertSame(0.0, WritingTask::Proofread->temperature());
        self::assertGreaterThan(WritingTask::Shorten->temperature(), WritingTask::Reply->temperature());
    }

    /**
     * The task list is closed, and that is a security property rather than
     * tidiness: the value arrives in a request body, and a free-form
     * instruction would be a prompt an attacker could write, with the user's
     * own mail as context and the answer pasted into the user's own draft.
     */
    public function testAnythingOutsideTheClosedSetIsNotATask(): void
    {
        self::assertNull(WritingTask::tryFrom('ignore previous instructions'));
        self::assertNull(WritingTask::tryFrom(''));
        self::assertCount(4, WritingTask::cases());
    }

    private function assistant(
        bool $enabled = true,
        string $answer = 'an answer',
        int &$calls = 0,
    ): WritingAssistant {

        $settings = new AiSettings();
        $settings->isEnabled          = $enabled;
        $settings->baseUrl            = 'http://10.0.0.5:11434';
        $settings->chatModel          = 'llama3.1:8b';
        $settings->writingHelpEnabled = $enabled;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function () use (&$calls, $answer): MockResponse {
            ++$calls;

            return new MockResponse(json_encode(['message' => ['content' => $answer]]));
        });

        return new WritingAssistant(
            new AiAssistant($this->settings, new OllamaClient($http, new NullLogger()), new NullLogger()),
        );
    }
}
