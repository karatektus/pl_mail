<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The thread reads that are not simply "give me rows".
 *
 * Two of these encode a decision that is invisible in the code and expensive to
 * get wrong. The account listing is newest-first, which is the one thing a mail
 * list may never lose. And the rethread backfill's carry-over is a COALESCE, not
 * an assignment — several old conversations can collapse into one rebuilt
 * thread, so a later snapshot that happens to be empty must not blank what an
 * earlier one restored. Written as an assignment it would still pass a
 * single-thread test and silently unstar people's mail on every real run.
 */
final class MessageThreadRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the account listing ──────────────────────────────────────────────────

    public function testTheAccountListingIsNewestFirst(): void
    {
        $this->thread('older', '2026-01-01 09:00');
        $this->thread('newest', '2026-03-01 09:00');
        $this->thread('middle', '2026-02-01 09:00');

        self::assertSame(
            ['newest', 'middle', 'older'],
            $this->subjectsOf($this->repository->findForAccount($this->account)),
        );
    }

    public function testPagingWalksThatOrderWithoutRepeatingOrSkipping(): void
    {
        $this->thread('one', '2026-03-01 09:00');
        $this->thread('two', '2026-02-01 09:00');
        $this->thread('three', '2026-01-01 09:00');

        self::assertSame(
            ['one', 'two'],
            $this->subjectsOf($this->repository->findForAccount($this->account, page: 1, perPage: 2)),
        );
        self::assertSame(
            ['three'],
            $this->subjectsOf($this->repository->findForAccount($this->account, page: 2, perPage: 2)),
        );
    }

    public function testTheCountIsScopedToTheAccount(): void
    {
        $other = $this->seedAccount($this->user, 'other@example.test');

        $this->thread('mine', '2026-03-01 09:00');
        $this->thread('theirs', '2026-03-01 09:00', account: $other);

        self::assertSame(1, $this->repository->countForAccount($this->account));
        self::assertSame(1, $this->repository->countForAccount($other));
    }

    // ── rethread carry-over ──────────────────────────────────────────────────

    /**
     * The snapshot anchors each thread to its earliest message, because that is
     * the only handle that survives the rebuild — the thread rows themselves
     * are about to be deleted.
     */
    public function testTheSnapshotAnchorsAThreadToItsEarliestMessage(): void
    {
        $thread = $this->thread('anchored', '2026-03-01 09:00');

        $earliest = $this->message($thread, '2026-02-01 09:00');
        $this->message($thread, '2026-03-01 09:00');

        $rows = $this->repository->findCarriedOverStateForAccount((int) $this->account->id);

        self::assertCount(1, $rows);
        self::assertSame((int) $earliest->id, (int) $rows[0]['anchor']);
    }

    /**
     * The reason this is COALESCE. Two old threads collapse into one rebuilt
     * one; the first carried a star, the second carried nothing. Restoring them
     * in that order must leave the star standing.
     */
    public function testCarryOverNeverBlanksAValueAnEarlierSnapshotRestored(): void
    {
        $thread = $this->thread('collapsed', '2026-03-01 09:00');

        $this->repository->restoreCarriedOverState(
            (int) $thread->id,
            '2026-02-14 08:00:00',
            null,
            MessageCategory::Primary->value,
        );

        $this->repository->restoreCarriedOverState((int) $thread->id, null, null, null);

        $row = $this->connection->fetchAssociative(
            'SELECT starred_at, category FROM message_thread WHERE id = :id',
            ['id' => $thread->id],
        );

        self::assertNotFalse($row);
        self::assertNotNull($row['starred_at'], 'the star survived the empty snapshot');
        self::assertSame(MessageCategory::Primary->value, $row['category']);
    }

    /** Collapsed threads restore the same label twice; the second is a no-op. */
    public function testRestoringALabelTwiceLeavesOneAssignment(): void
    {
        $thread = $this->thread('labelled', '2026-03-01 09:00');
        $label  = $this->seedLabel('Carried');

        $this->repository->addLabelIfAbsent((int) $thread->id, (int) $label->id);
        $this->repository->addLabelIfAbsent((int) $thread->id, (int) $label->id);

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM thread_label WHERE message_thread_id = :id',
            ['id' => $thread->id],
        ));
    }

    public function testLabelIdsComeBackKeyedByThread(): void
    {
        $thread = $this->thread('labelled', '2026-03-01 09:00');
        $label  = $this->seedLabel('Carried');

        $this->repository->addLabelIfAbsent((int) $thread->id, (int) $label->id);

        self::assertSame(
            [(int) $thread->id => [(int) $label->id]],
            $this->repository->findLabelIdsByThread([(int) $thread->id]),
        );
    }

    // ── derived counters ─────────────────────────────────────────────────────

    /**
     * The counter is rebuilt from the messages rather than patched, so it has
     * to be right whatever it started as — including too high.
     */
    public function testAttachmentCountsAreRebuiltFromTheMessagesThemselves(): void
    {
        $thread                  = $this->thread('with attachments', '2026-03-01 09:00');
        $thread->attachmentCount = 99;
        $this->em->flush();

        $this->message($thread, '2026-03-01 09:00', hasAttachments: true);
        $this->message($thread, '2026-03-01 10:00', hasAttachments: false);

        $this->repository->recomputeAttachmentCounts();

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT attachment_count FROM message_thread WHERE id = :id',
            ['id' => $thread->id],
        ));
    }

    // ── emptied threads ──────────────────────────────────────────────────────

    public function testAThreadThatStillHasMailIsNotEmpty(): void
    {
        $empty     = $this->thread('nothing left', '2026-03-01 09:00');
        $populated = $this->thread('still here', '2026-03-01 09:00');

        $this->message($populated, '2026-03-01 09:00');

        $found = array_map(
            static fn (MessageThread $thread): int => (int) $thread->id,
            $this->repository->findEmptyForUser($this->user),
        );

        self::assertContains((int) $empty->id, $found);
        self::assertNotContains((int) $populated->id, $found);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<MessageThread> $threads
     *
     * @return list<string>
     */
    // ── the bin is a place, not a label you also happen to carry ─────────────

    /**
     * The reported bug, end to end and through the real derivation.
     *
     * Trashing takes Inbox away and leaves every custom label alone, and thread
     * labels are the union of message labels — so the thread genuinely carries
     * both "Receipts" and Trash. Listing by label alone therefore showed
     * deleted mail under Receipts, which is where someone finds a conversation
     * they threw away a month ago and starts replying to it.
     */
    public function testATrashedThreadDropsOutOfItsOtherLabelViews(): void
    {
        $receipts = $this->seedLabel('Receipts');
        $trash    = $this->seedSystemLabel(LabelRole::Trash);

        $thread  = $this->thread('a receipt', '2026-02-01 09:00');
        $message = $this->message($thread, '2026-02-01 09:00');

        $message->addLabel($receipts);
        $this->syncThreadLabels($thread);

        self::assertSame(
            ['a receipt'],
            $this->subjectsOf($this->repository->findForLabel($receipts)),
            'precondition: it lists under its label before being trashed',
        );

        $message->addLabel($trash);
        $this->syncThreadLabels($thread);

        self::assertSame([], $this->subjectsOf($this->repository->findForLabel($receipts)));
        self::assertSame(0, $this->repository->countForLabel($receipts));
    }

    public function testTheTrashViewStillShowsIt(): void
    {
        $receipts = $this->seedLabel('Receipts');
        $trash    = $this->seedSystemLabel(LabelRole::Trash);

        $thread  = $this->thread('a receipt', '2026-02-01 09:00');
        $message = $this->message($thread, '2026-02-01 09:00');

        $message->addLabel($receipts);
        $message->addLabel($trash);
        $this->syncThreadLabels($thread);

        self::assertSame(
            ['a receipt'],
            $this->subjectsOf($this->repository->findForRole($this->user, LabelRole::Trash)),
        );
        self::assertSame(1, $this->repository->countForRole($this->user, LabelRole::Trash));
    }

    /**
     * The per-account folder row, which is the trap in this change: those rows
     * are built from the labels bound to an account and include its Trash
     * folder, and they go through findForLabel() like any other label. An
     * unconditional exclusion would render that folder permanently empty.
     */
    public function testTheAccountsOwnTrashFolderRowStillLists(): void
    {
        $trash = $this->seedSystemLabel(LabelRole::Trash);

        $thread  = $this->thread('binned', '2026-02-01 09:00');
        $message = $this->message($thread, '2026-02-01 09:00');

        $message->addLabel($trash);
        $this->syncThreadLabels($thread);

        self::assertSame(
            ['binned'],
            $this->subjectsOf($this->repository->findForLabel($trash)),
        );
    }

    public function testUntrashingPutsItBackUnderItsLabels(): void
    {
        $receipts = $this->seedLabel('Receipts');
        $trash    = $this->seedSystemLabel(LabelRole::Trash);

        $thread  = $this->thread('a receipt', '2026-02-01 09:00');
        $message = $this->message($thread, '2026-02-01 09:00');

        $message->addLabel($receipts);
        $message->addLabel($trash);
        $this->syncThreadLabels($thread);

        self::assertSame([], $this->subjectsOf($this->repository->findForLabel($receipts)));

        $message->removeLabel($trash);
        $this->syncThreadLabels($thread);

        self::assertSame(
            ['a receipt'],
            $this->subjectsOf($this->repository->findForLabel($receipts)),
        );
        self::assertSame(1, $this->repository->countForLabel($receipts));
    }

    /** The "everything in this account" row, where deleted mail piled up most. */
    public function testTheAccountListingHidesTrashedThreads(): void
    {
        $trash = $this->seedSystemLabel(LabelRole::Trash);

        $this->thread('kept', '2026-03-01 09:00');

        $binned  = $this->thread('binned', '2026-02-01 09:00');
        $message = $this->message($binned, '2026-02-01 09:00');
        $message->addLabel($trash);
        $this->syncThreadLabels($binned);

        self::assertSame(['kept'], $this->subjectsOf($this->repository->findForAccount($this->account)));
        self::assertSame(1, $this->repository->countForAccount($this->account));
    }

    public function testTheInboxHidesTrashedThreads(): void
    {
        $inbox = $this->seedSystemLabel(LabelRole::Inbox);
        $trash = $this->seedSystemLabel(LabelRole::Trash);

        $kept           = $this->thread('kept', '2026-03-01 09:00');
        $kept->category = MessageCategory::Primary;
        $keptMessage    = $this->message($kept, '2026-03-01 09:00');
        $keptMessage->addLabel($inbox);
        $this->syncThreadLabels($kept);

        // Trashing normally removes Inbox, but a provider that leaves both on
        // must not put the thread back in the inbox either.
        $binned           = $this->thread('binned', '2026-02-01 09:00');
        $binned->category = MessageCategory::Primary;
        $binnedMessage    = $this->message($binned, '2026-02-01 09:00');
        $binnedMessage->addLabel($inbox);
        $binnedMessage->addLabel($trash);
        $this->syncThreadLabels($binned);

        self::assertSame(
            ['kept'],
            $this->subjectsOf(
                $this->repository->findForUnifiedInbox($this->user, MessageCategory::Primary),
            ),
        );
    }

    public function testTheStarredListHidesTrashedThreads(): void
    {
        $trash = $this->seedSystemLabel(LabelRole::Trash);

        $kept            = $this->thread('kept', '2026-03-01 09:00');
        $kept->starredAt = new DateTimeImmutable('2026-03-01 09:00');

        $binned            = $this->thread('binned', '2026-02-01 09:00');
        $binned->starredAt = new DateTimeImmutable('2026-02-01 09:00');
        $this->em->flush();

        $message = $this->message($binned, '2026-02-01 09:00');
        $message->addLabel($trash);
        $this->syncThreadLabels($binned);

        self::assertSame(['kept'], $this->subjectsOf($this->repository->findForStarred($this->user)));
        self::assertSame(1, $this->repository->countForStarred($this->user));
    }

    /**
     * Runs the real derivation rather than writing thread_label by hand: the
     * whole point of the bug is that the thread's labels are DERIVED from its
     * messages, so a test that set them directly would be testing a state the
     * application never produces.
     */
    private function syncThreadLabels(MessageThread $thread): void
    {
        self::getContainer()->get(ThreadLabelSynchronizer::class)->sync($thread);

        // Flushed but deliberately not cleared: the tests keep hold of the
        // message and label objects to trash and untrash them, and the queries
        // under test read the database, which the flush has already caught up.
        $this->em->flush();
    }

    private function seedSystemLabel(LabelRole $role): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = ucfirst($role->value);
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function subjectsOf(array $threads): array
    {
        return array_map(
            static fn (MessageThread $thread): string => (string) $thread->subject,
            $threads,
        );
    }

    private function thread(string $subject, string $lastMessageAt, ?Account $account = null): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $account ?? $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($lastMessageAt);

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function message(MessageThread $thread, string $receivedAt, bool $hasAttachments = false): Message
    {
        $message                 = new Message();
        $message->account        = $thread->account ?? $this->account;
        $message->thread         = $thread;
        $message->subject        = $thread->subject;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = $hasAttachments;
        $message->flags          = [];

        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedLabel(string $name): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user, string $email = 'threads@example.test'): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Threads fixture';
        $account->email          = $email;
        $account->username       = uniqid('threads-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'threads-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Threads';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
