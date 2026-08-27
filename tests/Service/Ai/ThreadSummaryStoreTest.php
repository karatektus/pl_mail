<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Ai\ThreadSummaryStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The three ways a stored summary stops being usable, and the one way it is.
 *
 * WHAT IS ACTUALLY AT RISK
 * ────────────────────────
 * Not the round trip — a summary that failed to come back is obvious the first
 * time anybody uses the feature. What is at risk is a summary that comes back
 * when it should not: written by a model this installation no longer runs,
 * written under a prompt that has since been rewritten, or describing a
 * conversation that has changed underneath it. All three render as a confident
 * paragraph of assertions about somebody's mail with nothing on the page to say
 * it is out of date, and none of them fails anywhere a person would look.
 *
 * The model and the prompt version make a row INVISIBLE; the hash makes it
 * STALE, which is a state the pane renders rather than hides. That split is the
 * subject of half this file.
 *
 * Everything happens inside one transaction that is rolled back.
 */
final class ThreadSummaryStoreTest extends KernelTestCase
{
    private const string MODEL = 'qwen3:30b';
    private const int VERSION  = 1;

    private Connection $connection;
    private EntityManagerInterface $em;
    private ThreadSummaryStore $store;
    private int $threadId;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->store      = new ThreadSummaryStore($this->connection, new NullLogger());

        $this->connection->beginTransaction();

        // Its own thread rather than whatever the database happens to hold: the
        // table has a foreign key to it and the cascade is part of what is being
        // relied on, so the row has to be real — but it must not be somebody
        // else's.
        $this->threadId = $this->seedThread();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAThreadWithNoSummaryHasNoSummary(): void
    {
        self::assertNull($this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'abc'));
    }

    public function testTheStoredTextComesBackWithItsFreshnessAndItsTimestamp(): void
    {
        $this->store->save($this->threadId, 'They agreed on Thursday.', 'hash-one', self::MODEL, self::VERSION);

        $stored = $this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-one');

        self::assertNotNull($stored);
        self::assertSame('They agreed on Thursday.', $stored->text);
        self::assertTrue($stored->isFresh);
        self::assertInstanceOf(DateTimeImmutable::class, $stored->writtenAt);
    }

    /**
     * A row written by another model is INVISIBLE, not stale.
     *
     * EmbeddingStore::alreadyStored()'s argument: an administrator who swaps
     * chatModel has changed what a summary IS, and a row the previous model
     * wrote must stop being shown rather than sit there looking current. There
     * is nothing useful to grey out — a paragraph from a model nobody runs any
     * more is not "mostly true", it is unaccountable.
     */
    public function testASummaryWrittenByAnotherModelIsNotOffered(): void
    {
        $this->store->save($this->threadId, 'written by the old model', 'hash-one', 'llama3.1:8b', self::VERSION);

        self::assertNull($this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-one'));
    }

    /** And so is one written under an older prompt. */
    public function testASummaryWrittenUnderAnOlderPromptIsNotOffered(): void
    {
        $this->store->save($this->threadId, 'written under prompt 1', 'hash-one', self::MODEL, 1);

        self::assertNull($this->store->forThread($this->threadId, self::MODEL, 2, 'hash-one'));
    }

    /**
     * A hash that no longer matches makes the row STALE and not absent — which
     * is the whole reason the hash is compared here rather than in the WHERE
     * clause.
     *
     * The pane greys the old text and offers to write another. Hiding it would
     * throw away half a minute somebody already waited over a thread that has
     * since gained one "thanks", and a summary of that thread is still mostly
     * true.
     */
    public function testAConversationThatHasChangedMakesItsSummaryStaleRatherThanAbsent(): void
    {
        $this->store->save($this->threadId, 'They agreed on Thursday.', 'hash-one', self::MODEL, self::VERSION);

        $stored = $this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-two');

        self::assertNotNull($stored, 'a stale summary must still be readable');
        self::assertSame('They agreed on Thursday.', $stored->text);
        self::assertFalse($stored->isFresh);
    }

    /**
     * The second write REPLACES rather than collides.
     *
     * EmbeddingStore's stated reason — "re-generation after a model change has
     * to replace rather than collide" — plus one this feature has of its own:
     * two tabs summarising the same thread at once is what happens when
     * somebody presses the button, gets bored, and presses it again in another
     * window. The loser of that race must overwrite, not raise a constraint
     * violation inside a response that is already half sent.
     */
    public function testRegeneratingReplacesTheRowRatherThanFailing(): void
    {
        self::assertTrue($this->store->save($this->threadId, 'first answer', 'hash-one', self::MODEL, self::VERSION));
        self::assertTrue($this->store->save($this->threadId, 'second answer', 'hash-two', self::MODEL, self::VERSION));

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM thread_summary WHERE thread_id = :id', ['id' => $this->threadId]),
        );

        $stored = $this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-two');

        self::assertNotNull($stored);
        self::assertSame('second answer', $stored->text);
        self::assertTrue($stored->isFresh);
    }

    /** A model change followed by a regeneration re-points the same row. */
    public function testARegenerationAfterAModelChangeTakesOverTheSameRow(): void
    {
        $this->store->save($this->threadId, 'old model', 'hash-one', 'llama3.1:8b', self::VERSION);
        $this->store->save($this->threadId, 'new model', 'hash-one', self::MODEL, self::VERSION);

        self::assertNull($this->store->forThread($this->threadId, 'llama3.1:8b', self::VERSION, 'hash-one'));

        $stored = $this->store->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-one');

        self::assertNotNull($stored);
        self::assertSame('new model', $stored->text);
    }

    /**
     * The thread going takes the summary with it, by cascade and by nothing
     * else.
     *
     * There is no cleanup subscriber and no console command — deletion of
     * per-thread data here is done purely by database constraint — so this is
     * the assertion that the constraint is actually CASCADE and not SET NULL
     * or RESTRICT. RESTRICT would make deleting a summarised conversation fail
     * outright.
     */
    public function testDeletingTheThreadTakesTheSummaryWithIt(): void
    {
        $this->store->save($this->threadId, 'about to be orphaned', 'hash-one', self::MODEL, self::VERSION);

        $this->connection->executeStatement('DELETE FROM message_thread WHERE id = :id', ['id' => $this->threadId]);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM thread_summary WHERE thread_id = :id', ['id' => $this->threadId]),
        );
    }

    /**
     * A database that will not answer is "no summary", not an exception.
     *
     * EmbeddingStore::alreadyStored()'s posture, and the trade is the same shape
     * with different stakes: answering "none" costs a person one button press
     * against a feature they were going to press anyway, while answering with
     * one we could not verify would put a paragraph of assertions about their
     * mail on the page with no idea whether it still describes it.
     */
    public function testADatabaseThatWillNotAnswerReadsAsNoSummary(): void
    {
        $broken = new ThreadSummaryStore($this->brokenConnection(), new NullLogger());

        self::assertNull($broken->forThread($this->threadId, self::MODEL, self::VERSION, 'hash-one'));
    }

    /**
     * And a write that fails is a caching miss rather than a 500.
     *
     * By the time save() runs, the summary has already been streamed to the
     * reader and is on their screen. Throwing here would turn an answer they
     * can read into an error on a response that is half on the wire.
     */
    public function testAWriteThatFailsIsReportedRatherThanThrown(): void
    {
        $broken = new ThreadSummaryStore($this->brokenConnection(), new NullLogger());

        self::assertFalse($broken->save($this->threadId, 'text', 'hash', self::MODEL, self::VERSION));
    }

    // ── Scaffolding ───────────────────────────────────────────────────────

    /**
     * A connection that refuses everything.
     *
     * createStub() rather than createMock(): nothing here asserts that a call
     * HAPPENED — the subject is what the store does when one fails — and a mock
     * with no expectations is a test double pretending to be an assertion.
     */
    private function brokenConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('no'));
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('no'));

        return $connection;
    }

    /** A user, an account and one thread, rolled back with the transaction. */
    private function seedThread(): int
    {
        $user = new User();
        $user->email     = 'summary-store-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Summary';
        $user->nameLast  = 'Store';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'summary-store-fixture@example.test';
        $account->username       = 'summary-store-fixture@example.test';
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
        $thread->subject           = 'Summary store fixture';
        $thread->normalizedSubject = 'summary store fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->messageCount      = 2;
        $this->em->persist($thread);

        $this->em->flush();

        return (int) $thread->id;
    }
}
