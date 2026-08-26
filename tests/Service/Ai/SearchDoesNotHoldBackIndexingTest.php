<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\AiCallFeature;
use App\Entity\Ai\AiSettings;
use App\Infrastructure\Event\Subscriber\InteractiveAiActivitySubscriber;
use App\Repository\Ai\AiBackfillStateRepository;
use App\Repository\Ai\AiCallMetricRepository;
use App\Service\Ai\InteractiveAiActivity;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A search must not make the indexer stand aside; the composer must.
 *
 * WHY THIS IS THE TEST WORTH HAVING
 * ─────────────────────────────────
 * plMail runs two models with nothing in common. The WRITING model is 20.3 GiB
 * and about thirteen seconds to load cold, and somebody is watching a cursor
 * while it runs — that is what the yielding arrangement was built for and it
 * has its own test next door. The EMBEDDING model is well under a gigabyte and
 * a couple of seconds, and search and indexing SHARE it.
 *
 * That sharing is the whole point. A search that has just run has paid to load
 * exactly the model the indexer needs, and by the time it is over the person
 * has their results — so a finished search is an invitation to index, not a
 * reason to stand aside. Counting it made a search suppress for ninety seconds
 * the very work its own cold load had bought, which on an install where
 * somebody searches often was most of the working day.
 *
 * BOTH HALVES, because the signal has two and either one alone would put the
 * yielding back: a stamp written by a listener at the edge of the request, and
 * a row written when a model call finishes. This asserts the search is absent
 * from both and the composer is present in both — the second half being what
 * stops a future "tidy-up" restoring one of them.
 */
final class SearchDoesNotHoldBackIndexingTest extends KernelTestCase
{
    /** Longer than anything these tests stamp, so a yield can only be the signal. */
    private const int COOLDOWN = 90;

    private Connection $connection;
    private InteractiveAiActivity $activity;
    private AiCallMetricRepository $metrics;
    private AiBackfillStateRepository $state;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->activity   = $container->get(InteractiveAiActivity::class);
        $this->metrics    = $container->get(AiCallMetricRepository::class);
        $this->state      = $container->get(AiBackfillStateRepository::class);

        $this->connection->beginTransaction();

        // Both tables are singletons or shared, so the transaction is what
        // keeps one test's stamp out of the next one's answer.
        $this->connection->executeStatement('DELETE FROM ai_call_metric');
        $this->connection->executeStatement('DELETE FROM ai_backfill_state');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The recorded half: a finished search-box query is not interactive work.
     *
     * It IS a real model call and it IS on the request path — which is exactly
     * why it used to count. What changed is the reading: it warmed the small
     * model, and the indexing that follows it is cheaper because of it.
     */
    public function testAFinishedSearchIsNotAReasonToYield(): void
    {
        $now = new DateTimeImmutable();

        $this->recordCall(AiCallFeature::SearchQuery, $now);

        self::assertNull(
            $this->metrics->lastInteractiveCallAt($now->modify('-10 minutes')),
            'a search is an invitation to index, not interactive work to stand aside for',
        );
        self::assertFalse($this->activity->shouldYield(self::COOLDOWN, $now));
    }

    /**
     * And the indexer's own calls certainly are not, or it would report itself
     * as a reason to pause itself.
     */
    public function testTheIndexerDoesNotYieldToItself(): void
    {
        $now = new DateTimeImmutable();

        $this->recordCall(AiCallFeature::MailIndex, $now);

        self::assertNull($this->metrics->lastInteractiveCallAt($now->modify('-10 minutes')));
        self::assertFalse($this->activity->shouldYield(self::COOLDOWN, $now));
    }

    /**
     * The case the whole arrangement exists for, unchanged.
     *
     * A 20 GiB model, thirteen seconds cold, and somebody watching a cursor. If
     * this ever goes green the wrong way, the change that did it has taken the
     * feature apart rather than narrowed it.
     */
    public function testTheComposerStillMakesTheIndexerStandAside(): void
    {
        $now = new DateTimeImmutable();

        $this->recordCall(AiCallFeature::WritingHelp, $now);

        self::assertNotNull($this->metrics->lastInteractiveCallAt($now->modify('-10 minutes')));
        self::assertTrue($this->activity->shouldYield(self::COOLDOWN, $now));
    }

    /**
     * The stamped half, which is the one that covers a request still in flight.
     *
     * A streamed draft runs for half a minute before it records anything, so
     * without this listener the cooldown would only begin once the person had
     * already been kept waiting. The search route is deliberately not among the
     * prefixes it matches.
     */
    public function testTheSearchRouteDoesNotStampTheStateRow(): void
    {
        $this->enableTheAi();

        $this->dispatchBothEndsOfARequestTo('app_mail_search');

        self::assertNull(
            $this->state->current()->interactiveSeenAt,
            'searching must not tell the indexer to keep its hands off the model it just warmed',
        );
        self::assertFalse($this->activity->shouldYield(self::COOLDOWN));
    }

    public function testTheComposerRouteStillStampsTheStateRow(): void
    {
        $this->enableTheAi();

        $this->dispatchBothEndsOfARequestTo('app_compose_assist');

        self::assertNotNull($this->state->current()->interactiveSeenAt);
        self::assertTrue($this->activity->shouldYield(self::COOLDOWN));
    }

    /**
     * Matched by prefix, so a streamed variant of the composer endpoint is
     * still the composer. This is what makes the narrowing above a change of
     * WHICH feature counts rather than an accident of route naming.
     */
    public function testAStreamedComposerVariantStampsToo(): void
    {
        $this->enableTheAi();

        $this->dispatchBothEndsOfARequestTo('app_compose_assist_stream');

        self::assertNotNull($this->state->current()->interactiveSeenAt);
    }

    /** One recorded model call, with the timings a real one would carry. */
    private function recordCall(AiCallFeature $feature, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ai_call_metric (feature, model, succeeded, created_at)
                VALUES (:feature, :model, true, :at)
            SQL,
            [
                'feature' => $feature->value,
                'model'   => 'qwen3-embedding:0.6b',
                'at'      => $at->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Both ends, because the subscriber stamps on both and a test that only
     * fired one would pass while half of it was broken.
     */
    private function dispatchBothEndsOfARequestTo(string $route): void
    {
        $subscriber = self::getContainer()->get(InteractiveAiActivitySubscriber::class);
        $kernel     = self::getContainer()->get('http_kernel');
        $request    = new Request();

        // The listener matches on the route NAME, which is an attribute the
        // router puts there — building the Request by hand is what lets this
        // ask about a route without owning a controller.
        $request->attributes->set('_route', $route);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelResponse(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()),
        );
    }

    /** The master switch, which the subscriber checks before it writes anything. */
    private function enableTheAi(): void
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled       = true;
        $settings->baseUrl         = 'http://model-host.invalid:11434';
        $settings->chatModel       = 'qwen3:30b-a3b-instruct-2507-q4_K_M';
        $settings->embeddingModel  = 'qwen3-embedding:0.6b';
        $settings->searchEnabled   = true;
        $settings->writingHelpEnabled = true;

        $em->persist($settings);
        $em->flush();
    }
}
