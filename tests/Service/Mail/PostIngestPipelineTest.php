<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Jmap\State\StateManager;
use App\Repository\Mail\ContactRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\RawMessageResolver;
use App\Service\Rule\MailRuleEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pipeline exists to own an order, so the order is what is pinned here.
 *
 * Not with a mock call log: every collaborator it takes is `final` and cannot
 * be doubled — the same reason ThreadSnoozeServiceTest gives. That turns out to
 * be the better test anyway. What the three sync paths actually depend on is
 * not "sanitize() was called fourth", it is the guarantee the step interface
 * advertises: by the time anything downstream looks, every id exists, every
 * message is threaded and categorised, and all of it is durable. So the
 * assertions are made from inside a real step, which is the one vantage point
 * where being wrong would cost something.
 *
 * The thread-id case is here because it is a bug that already happened once:
 * reading thread ids before the flush that creates them published every new
 * thread to JMAP clients as id 0.
 */
final class PostIngestPipelineTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->mailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The contract the step interface promises, asserted from inside a step:
     * ids exist, threading and categorisation have happened, and the row is
     * durable rather than merely pending in the unit of work.
     */
    public function testStepsSeeAFullyProcessedBatch(): void
    {
        $observed = null;

        $step = new class ($this->connection, $observed) implements PostIngestStepInterface {
            /** @param array<string,mixed>|null $observed */
            public function __construct(
                private readonly Connection $connection,
                public ?array &$observed,
            ) {
            }

            public function afterCommit(PostIngestResult $result): void
            {
                $message = $result->messages[0];
                $id      = $message->getId();

                $this->observed = [
                    'count'    => count($result->messages),
                    'id'       => $id,
                    'thread'   => $message->getThread()?->id,
                    'category' => $message->getCategory(),
                    // Straight past the identity map, and deliberately on a
                    // column the PIPELINE writes rather than one the fixture
                    // already flushed: still NULL here would mean a step is
                    // seeing work that has not been committed.
                    'storedCategory' => $this->connection->fetchOne(
                        'SELECT category FROM message WHERE id = ?',
                        [$id],
                    ),
                ];
            }
        };

        $this->pipeline([$step])->run($this->account, [
            new IngestedMessage($this->seedMessage('one'), $this->account),
            new IngestedMessage($this->seedMessage('two'), $this->account),
        ]);

        self::assertNotNull($observed, 'the step must run');
        self::assertSame(2, $observed['count']);
        self::assertNotNull($observed['id'], 'ids exist by the time a step runs');
        self::assertNotNull($observed['thread'], 'threading has happened');
        self::assertNotNull($observed['category'], 'categorisation has happened');
        self::assertNotFalse($observed['storedCategory'], 'the message row is still there');
        self::assertNotNull($observed['storedCategory'], 'the batch is flushed before steps run');
    }

    /**
     * A step is follow-up work. Whatever it throws is its own problem, and must
     * cost neither the mail nor the steps behind it in the queue.
     */
    public function testAThrowingStepNeitherPropagatesNorBlocksLaterSteps(): void
    {
        $reached = false;

        $throwing = new class implements PostIngestStepInterface {
            public function afterCommit(PostIngestResult $result): void
            {
                throw new \RuntimeException('extractor exploded');
            }
        };

        $later = new class ($reached) implements PostIngestStepInterface {
            public function __construct(public bool &$reached)
            {
            }

            public function afterCommit(PostIngestResult $result): void
            {
                $this->reached = true;
            }
        };

        $message = $this->seedMessage('survives');

        $this->pipeline([$throwing, $later])->run($this->account, [
            new IngestedMessage($message, $this->account),
        ]);

        self::assertTrue($reached, 'a later step still runs');
        self::assertNotNull($message->getThread(), 'the sync itself still succeeded');
    }

    /**
     * The bug this ordering exists to prevent: thread ids read before the flush
     * that creates them went out to every JMAP client as id 0.
     */
    public function testTouchedThreadsAreNeverRecordedAsIdZero(): void
    {
        $this->pipeline()->run($this->account, [
            new IngestedMessage($this->seedMessage('threaded'), $this->account),
        ]);

        $zeroes = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM jmap_change_log WHERE object_type = 'Thread' AND entity_id = '0'",
        );

        self::assertSame(0, $zeroes);
    }

    /**
     * IMAP updates the mailbox's last-seen UID and then calls the pipeline, so
     * an empty batch still has to flush. Skipping the work when there is
     * nothing to process would silently strand that write and re-fetch the
     * same UID range forever.
     */
    public function testAnEmptyBatchStillFlushesTheCallersWork(): void
    {
        $this->mailbox->lastSeenUid = 4242;

        $this->pipeline()->run($this->account, []);

        $this->em->clear();

        self::assertSame(
            4242,
            (int) $this->connection->fetchOne(
                'SELECT last_seen_uid FROM mailbox WHERE id = ?',
                [$this->mailbox->id],
            ),
        );
    }

    /** Nothing was ingested, so there is nothing to follow up on. */
    public function testStepsDoNotRunForAnEmptyBatch(): void
    {
        $ran = false;

        $step = new class ($ran) implements PostIngestStepInterface {
            public function __construct(public bool &$ran)
            {
            }

            public function afterCommit(PostIngestResult $result): void
            {
                $this->ran = true;
            }
        };

        $this->pipeline([$step])->run($this->account, []);

        self::assertFalse($ran);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Built by hand rather than pulled from the container so the test can pass
     * its own steps — the real service takes them from a tagged iterator.
     *
     * @param list<PostIngestStepInterface> $steps
     */
    private function pipeline(array $steps = []): PostIngestPipeline
    {
        $container = self::getContainer();

        return new PostIngestPipeline(
            $container->get(ContactRepository::class),
            $container->get(MailBodySanitizer::class),
            $container->get(RawMessageResolver::class),
            $container->get(MessageCategorizer::class),
            $container->get(MessageThreader::class),
            $container->get(MailRuleEngine::class),
            $container->get(StateManager::class),
            $this->em,
            $container->get(LoggerInterface::class),
            $steps,
        );
    }

    /** Persisted and flushed, which is the pipeline's stated precondition. */
    private function seedMessage(string $slug): Message
    {
        $message = new Message();
        $message
            ->setAccount($this->account)
            ->setMailbox($this->mailbox)
            ->setSubject('Post-ingest fixture ' . $slug)
            ->setFromAddress('sender@example.test')
            ->setReceivedAt(new \DateTimeImmutable('-1 hour'))
            ->setHasAttachments(false)
            ->setBodyHtml('<p>hello</p>')
            ->setMessageId(sprintf('<post-ingest-%s-%s@example.test>', $slug, uniqid('', true)));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'post-ingest-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Post';
        $user->nameLast = 'Ingest';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Post Ingest Fixture')
            ->setUsername('post-ingest-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account = $this->account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $mailbox->createdAt = new \DateTimeImmutable();
        $mailbox->updatedAt = new \DateTimeImmutable();

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
