<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\RunBulkStatusHandler;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bulk handler over MORE THAN ONE CHUNK, which is the only size at which
 * the interesting thing happens.
 *
 * BulkStatusOffloadTest next door pins the controller's contract — a job is
 * created and the request returns — against a fixture of a few threads. That
 * is one chunk, and one chunk is exactly the size at which this handler's
 * central hazard is invisible: it clears the EntityManager at the foot of every
 * chunk, so anything it was still holding from before the clear is detached,
 * and flushing a detached thread makes Doctrine announce
 *
 *     Multiple non-persisted new entities were found through the given
 *     association graph … App\Entity\Mail\Message#thread
 *
 * which is what a real mailbox produced on the first run over five thousand.
 * The suite was green throughout, because nothing in it ever asked for a second
 * chunk.
 *
 * So the fixture here is deliberately oversized: enough threads to force three
 * passes, seeded as cheaply as possible.
 */
final class RunBulkStatusHandlerTest extends KernelTestCase
{
    /**
     * Two and a half chunks' worth — see RunBulkStatusHandler::CHUNK.
     *
     * Two would prove the clear is survivable once. Three also proves the
     * progress counter keeps counting across several, which is the other thing
     * that only shows up past the first.
     */
    private const int THREADS = 250;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Every thread in the view is marked read, and the job says so.
     *
     * The assertion that matters is simply that this completes: before the fix
     * it threw on the second chunk, the job stayed Running for ever, and the
     * user watched an indicator that would never move again.
     */
    public function testAViewLargerThanOneChunkIsAppliedInFull(): void
    {
        $messageIds = $this->seedThreads();

        $job = $this->job();

        $this->handler()(new RunBulkStatusMessage((int) $job->id));

        // Re-read everything: the handler cleared the EntityManager repeatedly,
        // so every object this test held before the call is stale.
        $this->em->clear();

        $fresh = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($fresh);
        self::assertSame(JobState::Done, $fresh->state, (string) $fresh->failureReason);
        self::assertSame(self::THREADS, $fresh->total);
        self::assertSame(self::THREADS, $fresh->processed, 'the counter must keep counting across chunks');

        $stillUnread = 0;

        foreach ($messageIds as $id) {
            if (null === $this->em->find(Message::class, $id)?->seenAt) {
                ++$stillUnread;
            }
        }

        self::assertSame(0, $stillUnread, 'every message in the view should have been marked read');
    }

    /**
     * A deadlock on the first attempt is retried, not reported as a failure.
     *
     * WHAT CAME OUT OF PRODUCTION
     *
     *     SQLSTATE[40P01]: Deadlock detected … while updating tuple (496,11)
     *     in relation "message"
     *
     * Two transactions took row locks on the same messages in opposite orders
     * and PostgreSQL killed one of them, which is the database doing its job:
     * the loser rolled back whole and would very likely win a moment later.
     * What the handler did with it was mark the whole bulk action Failed, so a
     * collision that would have cleared on its own became an error and a dead
     * indicator in front of whoever pressed "mark all read".
     *
     * THE COLLISION IS INJECTED AT THE REPOSITORY, which is the one seam in
     * apply() that a test can reach: ThreadStatusUpdater is final and the flush
     * that really deadlocks is inside it. What matters is not WHERE the
     * RetryableException comes from — the handler catches the marker, not a
     * call site — but that one of them does not end the job, and that the
     * chunk it interrupted is applied in full afterwards. Thrown once, on the
     * first chunk, so the retry has to succeed for the assertion below to hold
     * over all 250 threads.
     */
    public function testALockCollisionIsRetriedAndTheWorkStillLands(): void
    {
        $messageIds = $this->seedThreads();

        $job = $this->job();

        $threads = new class (self::getContainer()->get(ManagerRegistry::class)) extends MessageThreadRepository {
            public int $collisions = 0;

            /**
             * @param array<string, mixed> $criteria
             * @param array<string, string>|null $orderBy
             *
             * @return list<MessageThread>
             */
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                if (0 === $this->collisions++) {
                    // Closed first, because that is the state a real one leaves
                    // behind: UnitOfWork::commit() shuts the manager in a
                    // `finally` before the exception escapes. Without it this
                    // test would exercise the retry and skip the only line in
                    // it that can go wrong — a retry through a manager Doctrine
                    // has already closed throws EntityManagerClosed instead of
                    // re-applying anything.
                    $this->getEntityManager()->close();

                    throw new DeadlockException(
                        new class ('deadlock detected') extends \RuntimeException implements DriverException {
                            public function getSQLState(): string
                            {
                                return '40P01';
                            }
                        },
                        null,
                    );
                }

                return parent::findBy($criteria, $orderBy, $limit, $offset);
            }
        };

        $this->handler($threads)(new RunBulkStatusMessage((int) $job->id));

        // The collision really happened: one throw plus one lookup per chunk.
        self::assertGreaterThan(1, $threads->collisions, 'the repository was never reached twice');

        $this->em->clear();

        $reloaded = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($reloaded);
        self::assertSame(JobState::Done, $reloaded->state, (string) $reloaded->failureReason);
        self::assertSame(self::THREADS, $reloaded->processed);

        $stillUnread = 0;

        foreach ($messageIds as $id) {
            if (null === $this->em->find(Message::class, $id)?->seenAt) {
                ++$stillUnread;
            }
        }

        self::assertSame(0, $stillUnread, 'the chunk that collided was never re-applied');
    }

    /**
     * Collisions that outlast the retries hand the job BACK, they do not end it.
     *
     * The difference is whether the transport gets another go. A job marked
     * Failed is one the redelivery skips — __invoke() returns early on anything
     * that is no longer active — so recording a lock collision as a failure
     * would quietly turn the `bulk` queue's five-attempt ladder into one
     * attempt. Left active, the envelope comes back with a fresh
     * EntityManager, re-resolves the view and finishes what is left.
     *
     * Four collisions against three attempts, so the retry is exhausted on the
     * first chunk rather than by arithmetic that happens to line up.
     */
    public function testCollisionsThatOutlastTheRetriesLeaveTheJobToTheQueue(): void
    {
        $this->seedThreads();

        $job = $this->job();

        $threads = new class (self::getContainer()->get(ManagerRegistry::class)) extends MessageThreadRepository {
            public int $collisions = 0;

            /**
             * @param array<string, mixed> $criteria
             * @param array<string, string>|null $orderBy
             *
             * @return list<MessageThread>
             */
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                ++$this->collisions;

                $this->getEntityManager()->close();

                throw new DeadlockException(
                    new class ('deadlock detected') extends \RuntimeException implements DriverException {
                        public function getSQLState(): string
                        {
                            return '40P01';
                        }
                    },
                    null,
                );
            }
        };

        try {
            $this->handler($threads)(new RunBulkStatusMessage((int) $job->id));

            self::fail('the collision was swallowed instead of being handed back');
        } catch (DeadlockException) {
            // What the transport sees, and what makes it redeliver.
        }

        self::assertSame(3, $threads->collisions, 'the chunk was not retried the documented number of times');

        $this->em->clear();

        $reloaded = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive(), 'a lock collision ended the job instead of leaving it to be redelivered');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function handler(?MessageThreadRepository $threads = null): RunBulkStatusHandler
    {
        $container = self::getContainer();

        return new RunBulkStatusHandler(
            $container->get(\App\Repository\Job\BackgroundJobRepository::class),
            $threads ?? $container->get(\App\Repository\Mail\MessageThreadRepository::class),
            $container->get(\App\Service\Mail\ListViewResolver::class),
            $container->get(\App\Service\Mail\ThreadStatusUpdater::class),
            $container->get(\App\Service\Mail\ThreadSnoozeService::class),
            $container->get(\App\Service\Job\JobNotifier::class),
            $this->em,
            $container->get(\Doctrine\Persistence\ManagerRegistry::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }

    private function job(): BackgroundJob
    {
        $job = new BackgroundJob($this->user, JobKind::MarkRead);
        $job->view = ['scope' => 'inbox', 'value' => 'primary', 'unreadOnly' => true];

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    /**
     * @return list<int> the seeded message ids
     */
    private function seedThreads(int $count = self::THREADS): array
    {
        $inbox    = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $mailbox  = $this->seedMailbox();
        $now      = new \DateTimeImmutable();
        $messages = [];
        $ids      = [];

        for ($i = 0; $i < $count; ++$i) {
            $thread = new MessageThread();
            $thread->account           = $this->account;
            $thread->subject           = 'Bulk fixture ' . $i;
            $thread->normalizedSubject = 'bulk fixture ' . $i;
            $thread->lastMessageAt     = $now;
            $thread->threadingMethod   = ThreadingMethod::References;
            $thread->unreadCount       = 1;
            $thread->category          = MessageCategory::Primary;
            $thread->addLabel($inbox);

            $this->em->persist($thread);

            $message = new Message();
            $message->account        = $this->account;
            $message->subject        = 'Bulk fixture ' . $i;
            $message->fromAddress    = 'sender@example.test';
            $message->messageId      = 'bulk-' . $i . '-' . uniqid('', true) . '@example.test';
            $message->receivedAt     = $now;
            $message->sentAt         = $now;
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->category       = MessageCategory::Primary;
            $message->mailbox        = $mailbox;
            $message->imapUid        = 9000 + $i;
            $message->addLabel($inbox);
            $thread->addMessage($message);

            $this->em->persist($message);

            $messages[] = $message;
        }

        $this->em->flush();

        foreach ($messages as $message) {
            $ids[] = (int) $message->id;
        }

        return $ids;
    }

    private function seedAccount(): void
    {
        $user = new User();
        $user->email     = 'bulk-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Bulk';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'bulk-fixture@example.test';
        $account->username       = 'bulk-fixture@example.test';
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

        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
