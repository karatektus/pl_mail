<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Imap\VanishedMessageReconciler;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Flags the server changed, and which of them plMail is allowed to believe.
 *
 * plMail never re-read the flags of a message it had already stored: they were
 * captured once at ingest and never again, because incremental sync asks for
 * `lastSeenUid+1:*` and never looks at an old UID twice. Mail read on a phone
 * stayed unread here forever, and a star set in another client never arrived.
 *
 * These tests state what the server said — the same way RemoteDeletionSyncTest
 * states what a listing produced — and assert what plMail concluded. The
 * important half is the echo race, and it is pinned from BOTH sides: a local
 * change must survive a server read that predates it, and a genuine remote
 * change must still land. A guard that passes only the first of those is a
 * guard that has switched inbound flag sync off.
 */
final class InboundFlagSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private VanishedMessageReconciler $vanished;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Mailbox $inbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->vanished   = $container->get(VanishedMessageReconciler::class);
        $this->labels     = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the gap ───────────────────────────────────────────────────────────

    /**
     * The reported behaviour. A message stored unread, read afterwards in
     * another client, is read here once the folder is listed again.
     */
    public function testAMessageReadOnTheServerBecomesReadHere(): void
    {
        $message = $this->incoming('Rechnung', 6);

        self::assertNull($message->seenAt, 'it arrived unread');

        $this->sweep([6 => ['\\Seen']], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->seenAt, 'the server says it has been read, and that is news');
        self::assertContains(MessageFlag::SEEN->value, $message->flags);
    }

    /**
     * And the other direction, which is the one a badge notices: a message
     * marked unread elsewhere goes back to unread here.
     */
    public function testAMessageMarkedUnreadOnTheServerBecomesUnreadHere(): void
    {
        $message = $this->incoming('Rechnung', 6, seen: true);

        $this->sweep([6 => []], now: '2026-08-11 09:00:00');

        self::assertNull($message->seenAt, 'the server no longer has \\Seen on it');
        self::assertNotContains(MessageFlag::SEEN->value, $message->flags);
    }

    /**
     * A star set in another client arrives, on the message and on the thread —
     * the thread is what the list renders, which is the rule star() works to
     * from the outbound side.
     */
    public function testAStarSetOnTheServerArrivesOnTheMessageAndTheThread(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $this->sweep([6 => ['\\Flagged']], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->starredAt);
        self::assertNotNull($message->thread->starredAt, 'a thread is starred as a whole');
    }

    public function testAStarRemovedOnTheServerComesOffHere(): void
    {
        $message = $this->incoming('Rechnung', 6, flagged: true);

        $this->sweep([6 => []], now: '2026-08-11 09:00:00');

        self::assertNull($message->starredAt);
        self::assertNull($message->thread->starredAt);
    }

    /**
     * The unread badge is a thread counter, and it is recounted from the
     * thread's own messages rather than adjusted — a listing can change several
     * messages of one thread at once and in both directions.
     */
    public function testTheThreadsUnreadCountIsRecountedFromItsMessages(): void
    {
        $thread = $this->thread('Rechnung');

        $first  = $this->rowIn(MessageIdHelper::mint('kunde@example.test'), 6, 'Rechnung', $thread);
        $second = $this->rowIn(MessageIdHelper::mint('kunde@example.test'), 7, 'Rechnung', $thread);

        $thread->unreadCount = 2;
        $this->em->flush();

        // One read on the server, one still unread.
        $this->sweep([6 => ['\\Seen'], 7 => []], now: '2026-08-11 09:00:00');

        self::assertNotNull($first->seenAt);
        self::assertNull($second->seenAt);
        self::assertSame(1, $thread->unreadCount, 'recounted, not decremented');
    }

    /**
     * \Answered and \Draft have no column of their own — they live in the
     * flags mirror, which EmailMapper reads to answer the JMAP $answered and
     * $draft keywords. A refresh that only wrote the two with columns would
     * leave those saying what was true at ingest.
     */
    public function testFlagsWithoutAColumnStillReachTheMirror(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $this->sweep([6 => ['\\Seen', '\\Answered']], now: '2026-08-11 09:00:00');

        self::assertContains(MessageFlag::ANSWERED->value, $message->flags);
        self::assertContains(MessageFlag::SEEN->value, $message->flags);
    }

    /**
     * Servers disagree about whether the backslash on a system flag survives
     * being parsed — webklex hands back `Seen` from some and `\Seen` from
     * others — and a mirror captured from one must not read as changed against
     * a listing from the other on every pass forever.
     *
     * The row is left exactly as it is, which is the assertion: a difference
     * that is only punctuation is not a change, so there is no write, no JMAP
     * change-log row, and no thread recount. Without this, the first pass over
     * a fifty-thousand-message folder would rewrite every row in it and wake
     * every connected client to say nothing.
     */
    public function testTheSpellingOfASystemFlagIsNotAChange(): void
    {
        $message = $this->incoming('Rechnung', 6, seen: true);

        $message->flags = ['Seen'];
        $this->em->flush();

        $this->sweep([6 => ['\\Seen']], now: '2026-08-11 09:00:00');

        self::assertSame(['Seen'], $message->flags, 'not rewritten, because nothing about it differs');
        self::assertNotNull($message->seenAt, 'and nothing about the read state moved');
    }

    /**
     * The same rule the other way: a real change is still a change when the
     * spelling differs too, and it is written in the canonical form.
     */
    public function testARealChangeIsWrittenCanonically(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $message->flags = ['Answered'];
        $this->em->flush();

        $this->sweep([6 => ['Seen', 'Answered']], now: '2026-08-11 09:00:00');

        self::assertSame(
            [MessageFlag::ANSWERED->value, MessageFlag::SEEN->value],
            $message->flags,
            'canonical and sorted, so the next comparison is cheap',
        );
        self::assertNotNull($message->seenAt);
    }

    /**
     * A UID the listing did not describe is not a message with no flags. The
     * sweep is what draws conclusions from absence, with the safety rails.
     */
    public function testAUidTheListingDidNotDescribeIsLeftAlone(): void
    {
        $message = $this->incoming('Rechnung', 6, seen: true);

        // The listing produces the UID — so the sweep does not mark it gone —
        // but says nothing about its flags.
        $this->vanished->apply(
            $this->inbox,
            ['uidValidity' => 1, 'uids' => [6 => true], 'flags' => [], 'readAt' => new DateTimeImmutable('2026-08-11 09:00:00')],
            new DateTimeImmutable('2026-08-11 09:00:00'),
        );

        self::assertNotNull($message->seenAt, 'silence is not the answer "unread"');
    }

    // ── the echo race, from both sides ────────────────────────────────────

    /**
     * THE RACE. The user marks a message read; the outbound job is queued and
     * has not run; the flag pass reads the server's still-old answer.
     *
     * Believing it would clear seenAt — which the propagator would see as a
     * fresh local change and queue a job for, which the next pass would revert
     * again. The local change has to survive.
     */
    public function testALocalChangeSurvivesAServerReadThatPredatesIt(): void
    {
        $message = $this->incoming('Rechnung', 6);

        // The user marks it read. The row changes first; the job is queued.
        $message->seenAt         = new DateTimeImmutable('2026-08-11 08:59:00');
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable('2026-08-11 08:59:00');
        $this->em->flush();

        // The server has not been told yet, so it still says unread.
        $this->sweep([6 => []], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->seenAt, 'the user read it; a stale answer does not undo that');
        self::assertNotNull($message->flagsTouchedAt, 'and it is still waiting to be confirmed');
    }

    /**
     * The other side of the same guard, and the one that proves it is a guard
     * rather than an off switch: with nothing in flight, the server's answer is
     * the truth and lands.
     */
    public function testAGenuineRemoteChangeStillLandsWhenNothingIsInFlight(): void
    {
        $message = $this->incoming('Rechnung', 6);

        self::assertNull($message->flagsTouchedAt, 'nothing queued');

        $this->sweep([6 => ['\\Seen']], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->seenAt, 'no local change to protect, so the server wins');
    }

    /**
     * The guard is released by confirmation, not by time: once the outbound job
     * has told the server, the next listing is authoritative again — including
     * when it reports something new.
     */
    public function testOnceConfirmedTheServerIsAuthoritativeAgain(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $message->seenAt         = new DateTimeImmutable('2026-08-11 08:59:00');
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable('2026-08-11 08:59:00');
        $this->em->flush();

        // ApplyImapFlagsHandler applied it and cleared the mark.
        $message->flagsTouchedAt = null;
        $this->em->flush();

        // Now somebody marks it unread on another client.
        $this->sweep([6 => []], now: '2026-08-11 09:00:00');

        self::assertNull($message->seenAt, 'a confirmed row takes the server\'s word again');
    }

    /**
     * The guard has to expire, or it is the same bug in a smaller box: a job
     * lost for good would freeze that row's flags against the server for the
     * rest of its life.
     */
    public function testAnUnconfirmedChangeStopsWinningOnceItIsPlainlyLost(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $message->seenAt = new DateTimeImmutable('2026-08-11 06:00:00');
        $message->flags  = [MessageFlag::SEEN->value];

        // Queued hours ago and never confirmed — well past the grace window.
        $message->flagsTouchedAt = new DateTimeImmutable('2026-08-11 06:00:00');
        $this->em->flush();

        $this->sweep([6 => []], now: '2026-08-11 09:00:00');

        self::assertNull($message->seenAt, 'nothing is carrying that change any more');
        self::assertNull($message->flagsTouchedAt, 'and the mark goes, or it would expire again forever');
    }

    /**
     * A row with a change in flight is left alone ENTIRELY rather than merged
     * flag by flag. A message whose \Seen is queued may have had its \Flagged
     * changed remotely meanwhile, and a flag list cannot tell the two apart —
     * so the pass does not guess.
     */
    public function testARowInFlightIsSkippedWholeRatherThanMerged(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $message->seenAt         = new DateTimeImmutable('2026-08-11 08:59:00');
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable('2026-08-11 08:59:00');
        $this->em->flush();

        // The server says unread AND starred. The star is genuinely new.
        $this->sweep([6 => ['\\Flagged']], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->seenAt, 'the local read state is protected');
        self::assertNull($message->starredAt, 'and the star waits for the next pass rather than being guessed at');
    }

    // ── the guard is one rule, not one per provider ───────────────────────

    /**
     * The guard lives in ThreadStatusUpdater rather than in each provider's
     * reconciler, so Gmail's labels and Graph's isRead are covered by the same
     * rule. Asserted directly, because the alternative is discovering it is
     * missing on the provider nobody wrote a test for.
     */
    public function testTheGuardIsEnforcedWhereEveryProviderLands(): void
    {
        $message = $this->incoming('Rechnung', 6);

        $message->seenAt         = new DateTimeImmutable('2026-08-11 08:59:00');
        $message->flagsTouchedAt = new DateTimeImmutable('2026-08-11 08:59:00');
        $this->em->flush();

        $status = self::getContainer()->get(ThreadStatusUpdater::class);

        $changed = $status->applyRemoteFlags(
            [new \App\Domain\DTO\Mail\RemoteFlagState($message, false, false)],
            new DateTimeImmutable('2026-08-11 09:00:00'),
        );

        self::assertSame(0, $changed, 'nothing applied while a change is in flight');
        self::assertNotNull($message->seenAt);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Run one folder listing that names these UIDs and their flags — the shape
     * ImapFolderListing::read() hands back from a single `UID FETCH 1:*
     * (FLAGS)`, which is where presence and flags become one answer.
     *
     * @param array<int,list<string>> $flagsByUid
     */
    private function sweep(array $flagsByUid, string $now): void
    {
        $uids = [];

        foreach (array_keys($flagsByUid) as $uid) {
            $uids[$uid] = true;
        }

        $this->vanished->apply(
            $this->inbox,
            [
                'uidValidity' => 1,
                'uids'        => $uids,
                'flags'       => $flagsByUid,
                'readAt'      => new DateTimeImmutable($now),
            ],
            new DateTimeImmutable($now),
        );
    }

    private function incoming(string $subject, int $uid, bool $seen = false, bool $flagged = false): Message
    {
        $thread  = $this->thread($subject);
        $message = $this->rowIn(MessageIdHelper::mint('kunde@example.test'), $uid, $subject, $thread);

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable('2026-08-10 12:00:00');
            $message->flags  = [MessageFlag::SEEN->value];
        }

        if (true === $flagged) {
            $message->starredAt = new DateTimeImmutable('2026-08-10 12:00:00');
            $thread->starredAt  = new DateTimeImmutable('2026-08-10 12:00:00');
            $message->flags     = MessageFlag::canonicalList(
                array_merge($message->flags, [MessageFlag::FLAGGED->value]),
            );
        }

        $thread->unreadCount = null === $message->seenAt ? 1 : 0;

        $this->em->flush();

        return $message;
    }

    private function thread(string $subject): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 0;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;

        $this->em->persist($thread);

        return $thread;
    }

    private function rowIn(string $messageId, int $uid, string $subject, MessageThread $thread): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = $uid;
        $message->messageId      = $messageId;
        $message->subject        = $subject;
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = $subject;
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->thread         = $thread;
        $message->flags          = [];

        $thread->addMessage($message);

        $this->em->persist($message);

        return $message;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'flags-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Inbound';
        $this->user->nameLast = 'Flags';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'flags-fixture@example.test';
        $this->account->username = 'flags-fixture@example.test';
        $this->account->imapHost = 'localhost';
        $this->account->imapPort = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost = 'localhost';
        $this->account->smtpPort = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password = 'x';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);

        $this->inbox = new Mailbox();
        $this->inbox->account       = $this->account;
        $this->inbox->name          = 'INBOX';
        $this->inbox->fullPath      = 'INBOX';
        $this->inbox->specialUse    = MailboxSpecialUse::INBOX;
        $this->inbox->isSyncEnabled = true;
        $this->inbox->isIdleEnabled = false;
        $this->em->persist($this->inbox);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Inbox, $this->account), $this->inbox);

        $this->em->flush();
    }
}
