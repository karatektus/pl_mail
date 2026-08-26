<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging;

use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use App\Entity\Ai\AiSettings;
use App\Infrastructure\Messaging\Handler\BackfillEmbeddingsHandler;
use App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage;
use App\Repository\Ai\AiBackfillStateRepository;
use App\Service\Ai\InteractiveAiActivity;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The backfill steps aside when somebody is using the AI.
 *
 * WHY THIS IS THE TEST WORTH HAVING
 * ─────────────────────────────────
 * Backfill and the composer share one integrated GPU. A click that lands while
 * a batch is in flight waits behind that batch, and "I pressed the button and
 * nothing happened" is the complaint the whole yielding arrangement exists to
 * remove. Everything else about a backfill is recoverable; this is the part
 * that decides whether the feature feels broken.
 *
 * Two properties are asserted, because either alone would be a bug: the chunk
 * does NO work while somebody is waiting, and it does not simply stop — a
 * delayed delivery of the SAME cursor stays in the queue, so the pass resumes
 * by itself with nothing skipped.
 */
final class BackfillYieldsToInteractiveWorkTest extends KernelTestCase
{
    private Connection $connection;
    private AiBackfillStateRepository $state;
    private BackfillEmbeddingsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->state      = $container->get(AiBackfillStateRepository::class);
        $this->handler    = $container->get(BackfillEmbeddingsHandler::class);

        $this->connection->beginTransaction();

        $this->enableSemanticSearch();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAChunkStepsAsideWhileSomebodyIsUsingTheAi(): void
    {
        $now = new DateTimeImmutable();

        $this->state->begin('nomic-embed-text', [1], $now);
        $this->state->recordChunk(1, 500, false, 0, $now);

        // Somebody just asked the composer for a draft.
        self::getContainer()->get(InteractiveAiActivity::class)->touch($now);

        ($this->handler)(new BackfillEmbeddingsMessage(1, 500));

        $run = $this->state->current();

        self::assertSame(BackfillStatus::Paused, $run->status);
        self::assertSame(BackfillPauseReason::Interactive, $run->pauseReason);
        self::assertSame(500, $run->cursorFor(1), 'the cursor did not move, so nothing was skipped');

        // Still live: a pause that lifts on its own has a delivery in the
        // queue, and the handler reads exactly this to decide to carry on.
        self::assertTrue($run->isLive());

        $sent = $this->queue()->getSent();

        self::assertCount(1, $sent, 'the same chunk was posted back');

        $envelope = $sent[0];
        $message  = $envelope->getMessage();

        self::assertInstanceOf(BackfillEmbeddingsMessage::class, $message);
        self::assertSame(500, $message->afterMessageId, 'it comes back to the same place');
        self::assertNotNull($envelope->last(DelayStamp::class), 'and it comes back later, not immediately');
    }

    /**
     * A paused run ends the chain rather than posting itself back — otherwise
     * Pause would only slow the walk down.
     */
    public function testAnOperatorPauseStopsTheChain(): void
    {
        $now = new DateTimeImmutable();

        $this->state->begin('nomic-embed-text', [1], $now);
        $this->state->pause(BackfillPauseReason::Operator, $now);

        ($this->handler)(new BackfillEmbeddingsMessage(1));

        self::assertCount(0, $this->queue()->getSent(), 'a paused run posted another chunk');
    }

    /**
     * A delivery from a superseded chain is dropped, so a resume that raced an
     * in-flight chunk does not end up walking one mailbox twice.
     */
    public function testADeliveryBehindTheRecordedCursorIsDropped(): void
    {
        $now = new DateTimeImmutable();

        $this->state->begin('nomic-embed-text', [1], $now);
        $this->state->recordChunk(1, 900, false, 0, $now);

        ($this->handler)(new BackfillEmbeddingsMessage(1, 100));

        self::assertCount(0, $this->queue()->getSent());
        self::assertSame(900, $this->state->current()->cursorFor(1));
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.maintenance');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * The three conditions AiSettings insists on: switched on, a host, and a
     * model named for the job.
     */
    private function enableSemanticSearch(): void
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->embeddingModel = 'nomic-embed-text';
        $settings->searchEnabled  = true;

        $em->persist($settings);
        $em->flush();
    }
}
