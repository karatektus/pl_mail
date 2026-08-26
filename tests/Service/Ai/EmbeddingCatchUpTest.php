<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\Enum\Ai\SemanticSkipReason;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\BackfillPolicy;
use App\Service\Ai\EmbeddingCatchUp;
use App\Service\Ai\EmbeddingStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The two triggers that replaced indexing mail as it arrives.
 *
 * Mail used to be embedded within seconds of landing, which spent a request to
 * the model host on every message the installation ever received — for a
 * question almost nobody asks, since mail you might search for is rarely mail
 * you read ten minutes ago. Now it happens in the warm window after a search,
 * and once a night as a backstop.
 *
 * Three properties, and each of them is a way this could quietly become
 * something worse than what it replaced:
 *
 *  · BOUNDED. Without a ceiling this is EmbeddingBackfill with no state row and
 *    no pause button — a first night that queues a hundred thousand messages
 *    onto the ingest transport and puts new mail behind them.
 *  · THROTTLED. Without it, paging through results queues a batch per page and
 *    every one of them is the SAME batch, because nothing has been embedded yet
 *    when the next page asks.
 *  · IT DISPATCHES, IT DOES NOT EMBED. The search must not wait on the model,
 *    and the work must land on the path that already knows how to skip what is
 *    stored and re-check the feature — EmbedMessagesMessage and its handler.
 */
final class EmbeddingCatchUpTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private EmbeddingStore $store;
    private int $userId;

    /** @var list<int> oldest first, so the last one is the newest message */
    private array $messageIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->store      = $container->get(EmbeddingStore::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $this->seedMailbox(12);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The ceiling holds, and what it spends the budget on is the NEWEST mail.
     *
     * Newest first is the opposite of the backfill's walk, and deliberately:
     * that one has to cover a whole mailbox exactly once and never lose its
     * place, while this one will only ever do a handful and should do the
     * handful somebody might search for tomorrow morning.
     */
    public function testTheSweepStopsAtItsCeilingAndTakesTheNewestMailFirst(): void
    {
        $this->enableSemanticSearch();

        $queued = $this->catchUp()->sweep($this->userId, 4);

        self::assertSame(4, $queued);
        self::assertSame(array_slice(array_reverse($this->messageIds), 0, 4), $this->queuedIds());
    }

    /**
     * A mailbox with nothing outstanding queues nothing at all — no empty
     * envelope, which a worker would still have to pick up and open.
     */
    public function testAnIndexedMailboxQueuesNothing(): void
    {
        $this->enableSemanticSearch();

        foreach ($this->messageIds as $id) {
            $this->store->store($id, [1.0, 0.0], 'qwen3-embedding:0.6b');
        }

        self::assertSame(0, $this->catchUp()->sweep($this->userId, 50));
        self::assertSame([], $this->queue()->getSent());
    }

    /**
     * Vectors from another model are not vectors for today's purposes.
     *
     * Changing the search model invalidates the lot — different space,
     * different width, and the shipped distance function compares whatever
     * overlaps rather than refusing — so a mailbox that was fully indexed
     * yesterday is fully outstanding this morning.
     */
    public function testAVectorFromAPreviousModelDoesNotCountAsIndexed(): void
    {
        $this->enableSemanticSearch();

        foreach ($this->messageIds as $id) {
            $this->store->store($id, [1.0, 0.0], 'nomic-embed-text');
        }

        self::assertSame(3, $this->catchUp()->sweep($this->userId, 3));
    }

    /**
     * Chunked by the tuned batch size rather than posted as one envelope.
     *
     * A sweep that put five hundred ids in one message would hold the ingest
     * worker for minutes with arriving mail queued behind it. BackfillPolicy
     * already owns the answer to "how long may one embedding job hold the
     * host", and it is already settable per deployment.
     */
    public function testTheWorkIsChunkedIntoJobsAWorkerCanFinish(): void
    {
        $this->enableSemanticSearch();

        $policy = self::getContainer()->get(BackfillPolicy::class);

        $this->catchUp()->sweep($this->userId, 12);

        $sent = $this->queue()->getSent();

        self::assertCount((int) ceil(12 / $policy->batchSize), $sent);

        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();

            self::assertInstanceOf(EmbedMessagesMessage::class, $message);
            self::assertLessThanOrEqual($policy->batchSize, count($message->messageIds));
        }
    }

    /**
     * The opportunistic trigger fires once and then keeps quiet.
     *
     * A person paging through results makes several requests over a couple of
     * minutes, and the second one would find exactly the same outstanding
     * messages as the first — nothing has been embedded yet. Without the
     * throttle that is one batch per page, all of them the same batch.
     */
    public function testASecondSearchWithinTheQuietWindowQueuesNothing(): void
    {
        $this->enableSemanticSearch();

        $catchUp = $this->catchUp();

        self::assertSame(count($this->messageIds), $catchUp->afterSearch($this->userId, $this->searchThatRan()));

        $afterFirst = count($this->queue()->getSent());

        self::assertSame(0, $catchUp->afterSearch($this->userId, $this->searchThatRan()));
        self::assertSame(0, $catchUp->afterSearch($this->userId, $this->searchThatRan()));

        self::assertCount($afterFirst, $this->queue()->getSent(), 'the same batch was queued three times over');
    }

    /**
     * A search that never reached a model is not a warm model.
     *
     * The feature can be on with a host that is unplugged, and queueing a batch
     * against a host that has just refused a four-word query would be fifty
     * failures in a row on the ingest queue. hasVector() is the only honest way
     * to know the model answered.
     */
    public function testASearchThatNeverEmbeddedAnythingQueuesNothing(): void
    {
        $this->enableSemanticSearch();

        $catchUp = $this->catchUp();
        $skipped = SemanticSearch::skipped(SemanticSkipReason::HostUnreachable);

        self::assertSame(0, $catchUp->afterSearch($this->userId, $skipped));
        self::assertSame([], $this->queue()->getSent());

        // The SAME instance, so it is the same quiet window: a search that
        // never reached the host must not spend it, or one unreachable moment
        // would cost the next five minutes of indexing.
        self::assertSame(
            count($this->messageIds),
            $catchUp->afterSearch($this->userId, $this->searchThatRan()),
        );
    }

    /**
     * Both switches, asked again here rather than trusted from the caller:
     * settings change between one request and the next, and a mailbox on an
     * installation with search off has nothing to catch up on.
     */
    public function testNeitherTriggerRunsWhileTheFeatureIsOff(): void
    {
        $this->enableSemanticSearch(masterSwitch: false);

        self::assertSame(0, $this->catchUp()->sweep($this->userId, 50));
        self::assertSame(0, $this->catchUp()->afterSearch($this->userId, $this->searchThatRan()));

        $this->enableSemanticSearch(searchFeature: false);

        self::assertSame(0, $this->catchUp()->sweep($this->userId, 50));
        self::assertSame([], $this->queue()->getSent());
    }

    /**
     * A fresh service per call, with a cache of its own.
     *
     * ArrayAdapter rather than the container's cache.app: the throttle key is
     * per mailbox, and a filesystem pool would carry one test's marker into the
     * next run of the suite — a green test that fails only on a machine that
     * has run it before.
     */
    private function catchUp(?CacheItemPoolInterface $cache = null): EmbeddingCatchUp
    {
        $container = self::getContainer();

        return new EmbeddingCatchUp(
            $container->get(MessageRepository::class),
            $container->get(AiAssistant::class),
            $container->get(AiSettingsRepository::class),
            $container->get(BackfillPolicy::class),
            $container->get(MessageBusInterface::class),
            $cache ?? new ArrayAdapter(),
            new NullLogger(),
        );
    }

    /** A search that got a vector back, which is the only kind that triggers. */
    private function searchThatRan(): SemanticSearch
    {
        return SemanticSearch::ran('{0.6,0.8}', 'qwen3-embedding:0.6b', 2);
    }

    /** @return list<int> the ids actually posted, in the order they were posted */
    private function queuedIds(): array
    {
        $ids = [];

        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            self::assertInstanceOf(EmbedMessagesMessage::class, $message);

            $ids = [...$ids, ...$message->messageIds];
        }

        return $ids;
    }

    private function queue(): InMemoryTransport
    {
        // ingest, because catching up IS mail arriving, only later. The routing
        // is part of what is being asserted: unrouted, a Messenger message is
        // handled in the process that dispatched it, and the search would wait
        // on the model after all.
        $transport = self::getContainer()->get('messenger.transport.ingest');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** The three conditions AiSettings insists on, and the two switches. */
    private function enableSemanticSearch(bool $masterSwitch = true, bool $searchFeature = true): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled      = $masterSwitch;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->embeddingModel = 'qwen3-embedding:0.6b';
        $settings->searchEnabled  = $searchFeature;

        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * A mailbox of its own, threaded.
     *
     * In threads because the finder walks message → thread → account to decide
     * whose the mail is, which is the join EmbeddingStore::coverageDetailFor()
     * counts through — the two have to agree or the notice under somebody's
     * search box never reaches a hundred per cent.
     */
    private function seedMailbox(int $messages): void
    {
        $user = new User();
        $user->email     = 'catchup-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Catch';
        $user->nameLast  = 'Up';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'catchup-fixture@example.test';
        $account->username       = 'catchup-fixture@example.test';
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
        $thread->subject           = 'Catch-up fixture';
        $thread->normalizedSubject = 'catch-up fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        $created = [];

        for ($i = 0; $i < $messages; $i++) {
            $message = new Message();
            $message->account        = $account;
            $message->thread         = $thread;
            $message->subject        = sprintf('Catch-up fixture %d', $i);
            $message->fromAddress    = 'sender@example.test';
            $message->messageId      = sprintf('catchup-%s-%d@example.test', uniqid('', true), $i);
            $message->receivedAt     = new DateTimeImmutable();
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $this->em->persist($message);

            $created[] = $message;
        }

        $this->em->flush();

        $this->userId     = (int) $user->id;
        $this->messageIds = array_map(static fn (Message $m): int => (int) $m->id, $created);
    }
}
