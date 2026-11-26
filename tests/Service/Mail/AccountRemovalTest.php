<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Handler\DeleteAccountHandler;
use App\Infrastructure\Messaging\Message\DeleteAccountMessage;
use App\Service\Mail\AccountRemoval;
use App\Service\Mail\AccountRemovalState;
use App\Tests\Command\MailFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Removing an account happens on a worker, never in the request (#672).
 *
 * The request half has to leave the account quiet at once — every service off,
 * the primary moved — and queue exactly one job however often Remove is
 * pressed. The worker half has to take the mail, then the account, count along
 * on the job, and leave the rest of the list in order. A removal that kept
 * syncing would refill what it empties; a second job would race the first; a
 * list left with a gap or no primary would send mail from nowhere.
 *
 * Against the real container and database, rolled back, as the rest of the
 * suite is. The handler is invoked on the job the way the bulk worker would.
 */
final class AccountRemovalTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AccountRemoval $removal;
    private AccountRemovalState $state;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->removal    = $container->get(AccountRemoval::class);
        $this->state      = $container->get(AccountRemovalState::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testPressingRemoveSwitchesTheAccountOffAndQueuesOneJob(): void
    {
        $user    = MailFixtures::user($this->em, 'rm');
        $account = MailFixtures::account($this->em, $user);
        $other   = MailFixtures::account($this->em, $user);

        $account->isPrimary = true;
        $account->sortOrder = 0;
        $other->sortOrder   = 1;
        $this->em->flush();

        $job = $this->removal->start($account, $user);

        self::assertSame(JobKind::DeleteAccount, $job->kind);
        self::assertSame($account->id, $job->view['accountId'] ?? null);
        self::assertSame([], $account->services, 'nothing syncs or sends while the account is emptied');
        self::assertFalse($account->isPrimary, 'the primary moves straight away, not when the job ends');
        self::assertTrue($other->isPrimary);
        self::assertTrue($this->state->isRemoving($account));
        self::assertNotNull($this->em->find(Account::class, $account->id), 'the account itself is the worker\'s to remove');

        $again = $this->removal->start($account, $user);

        self::assertSame($job->id, $again->id, 'pressed twice, or from two tabs: the same job');
        self::assertCount(1, $this->state->activeFor($user), 'and no second one beside it');
    }

    /**
     * Asked of the routing rather than of the queue, for BulkStatusOffloadTest's
     * reason: `bulk` is a real, consumed queue in the test stack, so there is
     * no in-memory transport to read. Unrouted, the message would be handled
     * inline — in the request this exists to get out of.
     */
    public function testTheRemovalIsHandedToTheBulkQueue(): void
    {
        $senders = self::getContainer()
            ->get('messenger.senders_locator')
            ->getSenders(new Envelope(new DeleteAccountMessage(1)));

        self::assertSame(['bulk'], array_keys(iterator_to_array($senders)));
    }

    public function testTheWorkerRemovesTheMailThenTheAccountAndCountsAlong(): void
    {
        $user    = MailFixtures::user($this->em, 'rm');
        $first   = MailFixtures::account($this->em, $user);
        $account = MailFixtures::account($this->em, $user);
        $last    = MailFixtures::account($this->em, $user);

        $first->sortOrder   = 0;
        $account->sortOrder = 1;
        $last->sortOrder    = 2;
        $account->isPrimary = true;
        $this->em->flush();

        $this->thread($account, 2);
        $this->thread($account, 1);

        // Caught between ingest and threading: no thread batch reaches it.
        MailFixtures::message($this->em, $account);

        $kept = MailFixtures::message($this->em, $first);

        $accountId = (int) $account->id;
        $job       = $this->removal->start($account, $user);
        $jobId     = (int) $job->id;

        $this->work($jobId);

        $this->em->clear();

        self::assertNull($this->em->find(Account::class, $accountId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message WHERE account_id = ?', [$accountId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_thread WHERE account_id = ?', [$accountId]));
        self::assertNotNull($this->em->find(Message::class, $kept->id), 'another account\'s mail is not touched');

        $finished = $this->em->find(BackgroundJob::class, $jobId);

        self::assertSame(JobState::Done, $finished->state);
        self::assertSame(4, $finished->total, 'measured against the mail there was');
        self::assertSame(4, $finished->processed);

        // No gap where the account was, and still exactly one primary.
        $remaining = [$this->em->find(Account::class, $first->id), $this->em->find(Account::class, $last->id)];

        self::assertSame([0, 1], array_map(static fn (Account $a): int => $a->sortOrder, $remaining));
        self::assertSame(1, count(array_filter($remaining, static fn (Account $a): bool => $a->isPrimary)));
    }

    public function testAnAccountAlreadyGoneEndsTheJobAsDone(): void
    {
        $user    = MailFixtures::user($this->em, 'rm');
        $account = MailFixtures::account($this->em, $user);
        $job     = $this->removal->start($account, $user);
        $jobId   = (int) $job->id;

        $this->em->remove($account);
        $this->em->flush();

        $this->work($jobId);

        $this->em->clear();

        self::assertSame(JobState::Done, $this->em->find(BackgroundJob::class, $jobId)?->state);
        self::assertSame([], $this->state->activeFor($user), 'nothing left greyed out');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** What the bulk worker does with the message the job was queued under. */
    private function work(int $jobId): void
    {
        (self::getContainer()->get(DeleteAccountHandler::class))(new DeleteAccountMessage($jobId));
    }

    private function thread(Account $account, int $messages): MessageThread
    {
        $thread = new MessageThread();

        $thread->account           = $account;
        $thread->subject           = 'On its way out';
        $thread->normalizedSubject = 'on its way out';
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $thread->messageCount      = $messages;
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;

        $this->em->persist($thread);

        for ($i = 0; $i < $messages; ++$i) {
            $message         = MailFixtures::message($this->em, $account);
            $message->thread = $thread;
        }

        $this->em->flush();

        return $thread;
    }
}
