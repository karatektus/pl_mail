<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\Ai\ThreadSummariser;
use App\Service\Ai\ThreadTranscript;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which end of a long conversation survives, and what the hash notices.
 *
 * THE TRUNCATION IS THE OPPOSITE OF ReplyContextReader'S, ON PURPOSE
 * ─────────────────────────────────────────────────────────────────
 * That class keeps the newest turns because a reply is shaped by what it
 * answers. A summary is shaped by what the thread is FOR, which is stated at
 * the top and never again — so this keeps the head, and keeps the newest turn
 * as well because where a conversation has got to is the other half of what a
 * reader wants. Getting that backwards produces a summary that describes three
 * replies to a question it never states, and nothing errors.
 *
 * THE HASH IS THE OTHER SUBJECT, AND IT IS THE REASON THE FEATURE WORKS
 * ────────────────────────────────────────────────────────────────────
 * Every timestamp and counter candidate fails silently — see
 * Version20260902100100, which lists them. The highest-value assertion in this
 * file is the one that says marking a thread READ leaves the hash alone, since
 * opening a thread is what marks it read and a key that moved there would make
 * every summary stale on the very next open.
 *
 * Everything happens inside one transaction that is rolled back, so the shared
 * test database is left as it was found.
 */
final class ThreadTranscriptTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private ThreadTranscript $transcript;
    private MessageRepository $messages;

    /** @var list<Message> oldest first */
    private array $turns = [];

    private MessageThread $thread;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->messages   = $container->get(MessageRepository::class);
        $this->transcript = new ThreadTranscript($this->messages);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Oldest first, each turn named by its sender. */
    public function testTheConversationArrivesOldestFirst(): void
    {
        $this->seedThread(['what time do you open', 'nine, most days', 'and on Sunday?']);

        $text = $this->transcript->forThread($this->thread);

        self::assertLessThan(
            mb_strpos($text, 'and on Sunday?'),
            mb_strpos($text, 'what time do you open'),
            'the transcript arrived newest-first, which is not how a conversation reads',
        );

        self::assertSame(3, substr_count($text, 'From: '));
    }

    /**
     * THE ONE THAT MATTERS. A thread past the budget keeps its opening AND its
     * newest turn, and drops the middle.
     *
     * The opening is the request the thread is about; the newest turn is where
     * it has got to. Everything in between is the "as discussed" and the
     * scheduling, which is what a summary can afford to lose.
     */
    public function testAnOversizedThreadKeepsTheOpeningAndTheNewestTurn(): void
    {
        $long = str_repeat('x', (int) (ThreadSummariser::TRANSCRIPT_BUDGET * 0.4));

        $this->seedThread([
            'THE-OPENING-TURN ' . $long,
            'A-MIDDLE-TURN ' . $long,
            'ANOTHER-MIDDLE-TURN ' . $long,
            'THE-NEWEST-TURN ' . $long,
        ]);

        $text = $this->transcript->forThread($this->thread);

        self::assertStringContainsString('THE-OPENING-TURN', $text, 'the request the thread is about was dropped');
        self::assertStringContainsString('THE-NEWEST-TURN', $text, 'where the thread has got to was dropped');
        self::assertStringNotContainsString('ANOTHER-MIDDLE-TURN', $text);
    }

    /**
     * And the gap is announced, with a count.
     *
     * A model told a conversation was cut says so; a model handed a silently
     * truncated one invents the middle — and an invented middle is
     * indistinguishable from a real one to somebody who asked for a summary
     * precisely so they would not have to read the messages.
     */
    public function testTheDroppedMiddleIsAnnouncedRatherThanSilentlyClosed(): void
    {
        $long = str_repeat('x', (int) (ThreadSummariser::TRANSCRIPT_BUDGET * 0.4));

        $this->seedThread([
            'first ' . $long,
            'second ' . $long,
            'third ' . $long,
            'fourth ' . $long,
        ]);

        $text = $this->transcript->forThread($this->thread);

        self::assertMatchesRegularExpression('/\[… \d+ messages omitted here …\]/u', $text);
    }

    /** A conversation that fits carries no marker, because there is no gap to announce. */
    public function testAThreadThatFitsCarriesNoElisionMarker(): void
    {
        $this->seedThread(['short one', 'short two', 'short three']);

        self::assertStringNotContainsString('omitted here', $this->transcript->forThread($this->thread));
    }

    /**
     * A single turn past the budget on its own is kept anyway.
     *
     * An empty transcript makes ThreadSummariser refuse outright, which is a
     * worse answer than a long one — and a one-message thread is refused for
     * being one message, not for being long.
     */
    public function testASingleOversizedTurnIsStillHandedOver(): void
    {
        $this->seedThread(['ONLY-TURN ' . str_repeat('y', ThreadSummariser::TRANSCRIPT_BUDGET * 2)]);

        self::assertStringContainsString('ONLY-TURN', $this->transcript->forThread($this->thread));
    }

    /**
     * A turn with no text part still appears, as its sender line.
     *
     * ReplyContextReader's reason, unchanged: dropping it would silently close
     * a gap, and "somebody replied here and we cannot show you what they said"
     * is more use to a model than a turn that never appears at all.
     */
    public function testATurnWithNoBodyStillAppearsAsItsSenderLine(): void
    {
        $this->seedThread([null, 'the newest turn']);

        self::assertSame(2, substr_count($this->transcript->forThread($this->thread), 'From: '));
    }

    /** The list the pane already holds and a fresh read produce the same string. */
    public function testTheHydratedListAndTheQueryAgreeExactly(): void
    {
        $this->seedThread(['one', 'two', 'three']);

        self::assertSame(
            $this->transcript->forThread($this->thread),
            $this->transcript->forMessages($this->messages->forThreadInConversationOrder($this->thread)),
            'the pane and the endpoint would hash different strings',
        );
    }

    // ── The staleness key ─────────────────────────────────────────────────

    /**
     * THE HIGHEST-VALUE ASSERTION IN THIS FILE.
     *
     * Marking a thread read does not move the key. This is the case
     * MAX(message.updated_at) would have failed: ThreadStatusUpdater writes
     * `seenAt` through the ORM, PreUpdate fires, and every message in the thread
     * gets a new updated_at — so a summary would be stale the moment somebody
     * opened the thread to read it, which is the only way anybody sees it.
     */
    public function testMarkingTheConversationReadLeavesTheKeyAlone(): void
    {
        $this->seedThread(['one', 'two']);

        $before = ThreadTranscript::hash($this->transcript->forThread($this->thread));

        foreach ($this->turns as $turn) {
            $turn->seenAt = new DateTimeImmutable();
        }

        $this->thread->unreadCount = 0;
        $this->thread->starredAt   = new DateTimeImmutable();
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            $before,
            ThreadTranscript::hash($this->transcript->forThread($this->reloadThread())),
            'reading or starring a conversation made its summary look out of date',
        );
    }

    /** A message arriving moves it. */
    public function testAMessageArrivingMovesTheKey(): void
    {
        $this->seedThread(['one', 'two']);

        $before = ThreadTranscript::hash($this->transcript->forThread($this->thread));

        $this->appendTurn('three');

        self::assertNotSame($before, ThreadTranscript::hash($this->transcript->forThread($this->thread)));
    }

    /**
     * And so does a message LEAVING, which is the one every timestamp candidate
     * misses: lastMessageAt only ever moves forward, and no deletion path moves
     * it back.
     */
    public function testDeletingTheNewestMessageMovesTheKey(): void
    {
        $this->seedThread(['one', 'two', 'three']);

        $before = ThreadTranscript::hash($this->transcript->forThread($this->thread));

        $this->em->remove($this->turns[2]);
        $this->em->flush();
        $this->em->clear();

        self::assertNotSame(
            $before,
            ThreadTranscript::hash($this->transcript->forThread($this->reloadThread())),
            'a summary survived the deletion of the message it described',
        );
    }

    /**
     * And a draft rewritten in place, which is the other one: recordActivity()
     * returns early for an unsent draft, so lastMessageAt never moves for it.
     */
    public function testEditingAMessageBodyMovesTheKey(): void
    {
        $this->seedThread(['one', 'two']);

        $before = ThreadTranscript::hash($this->transcript->forThread($this->thread));

        $this->turns[1]->bodyText = 'two, rewritten';
        $this->em->flush();
        $this->em->clear();

        self::assertNotSame($before, ThreadTranscript::hash($this->transcript->forThread($this->reloadThread())));
    }

    // ── Scaffolding ───────────────────────────────────────────────────────

    private function reloadThread(): MessageThread
    {
        $thread = $this->em->find(MessageThread::class, $this->thread->id);

        self::assertInstanceOf(MessageThread::class, $thread);

        return $thread;
    }

    /** @param list<string|null> $bodies oldest first */
    private function seedThread(array $bodies): void
    {
        $user = new User();
        $user->email     = 'transcript-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Thread';
        $user->nameLast  = 'Transcript';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'transcript-fixture@example.test';
        $account->username       = 'transcript-fixture@example.test';
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
        $thread->subject           = 'Opening hours';
        $thread->normalizedSubject = 'opening hours';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->messageCount      = count($bodies);
        $this->em->persist($thread);

        $this->thread = $thread;

        foreach ($bodies as $index => $body) {
            $this->turns[] = $this->message($account, $thread, $body, $index);
        }

        $this->em->flush();
    }

    private function appendTurn(?string $body): void
    {
        $account = $this->thread->account;

        self::assertInstanceOf(Account::class, $account);

        $this->turns[] = $this->message($account, $this->thread, $body, count($this->turns));
        $this->em->flush();
    }

    private function message(Account $account, MessageThread $thread, ?string $body, int $index): Message
    {
        $message = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = 'Opening hours';
        $message->bodyText       = $body;
        $message->fromName       = sprintf('Sender %d', $index);
        $message->fromAddress    = sprintf('sender%d@example.test', $index);
        $message->messageId      = sprintf('transcript-%s-%d@example.test', uniqid('', true), $index);
        $message->receivedAt     = (new DateTimeImmutable('2026-03-01 09:00:00'))->modify(sprintf('+%d minutes', $index));
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $this->em->persist($message);

        return $message;
    }
}
