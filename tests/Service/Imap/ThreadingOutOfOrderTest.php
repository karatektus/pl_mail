<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\State\ChangeType;
use App\Jmap\State\JmapObjectType;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageThreader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A conversation is one thread whatever order its messages are synced in.
 *
 * Threading used to look one way only, from a reply to the message it answers.
 * Mail synced before the message it answers — the replies sit in INBOX, the
 * answered mail in Sent Items, and the folders are synced one after the other —
 * found nothing, opened threads of its own, and stayed there when the parent
 * arrived: a conversation of four messages was reported in three threads
 * (#172). The first test is that report, message for message.
 *
 * Against a real container and database, for the reasons ThreadMoveTest gives,
 * and one more: the bug lives between two sync batches, and in what a batch
 * holds only in memory until its single flush. Each batch here is shaped like
 * PostIngestPipeline's — rows flushed first, then threaded one by one, then one
 * flush — and what is asserted is what reached the database.
 */
final class ThreadingOutOfOrderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreader $threader;
    private MessageRepository $messages;

    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->threader   = $container->get(MessageThreader::class);
        $this->messages   = $container->get(MessageRepository::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The reported conversation: the replies arrive with the INBOX, what they
     * answer with Sent Items afterwards.
     *
     * The thread kept is the one the first reply opened, because that is the
     * conversation that has been on screen: its id is what JMAP clients hold.
     * The thread the last reply opened is gone, and clients are told so — a
     * client keeping the id would show a conversation it can never open.
     */
    public function testRepliesSyncedBeforeWhatTheyAnswerJoinItsThread(): void
    {
        $root   = $this->message('root', null, 'DKIM failures', '-4 hours', seen: true);
        $reply  = $this->message('reply', 'root', 'Re: DKIM failures', '-3 hours');
        $answer = $this->message('answer', 'reply', 'Re: DKIM failures', '-2 hours', seen: true);
        $last   = $this->message('last', 'answer', 'Re: DKIM failures', '-1 hour');

        $this->syncBatch([$reply, $last]);

        $listed   = $this->storedThreadOf($reply);
        $orphaned = $this->storedThreadOf($last);

        self::assertNotSame($listed, $orphaned, 'the fixture has to start from the split the issue reports');

        $this->syncBatch([$root, $answer]);

        foreach ([$root, $reply, $answer, $last] as $message) {
            self::assertSame($listed, $this->storedThreadOf($message), sprintf('"%s" is in a thread of its own', $message->subject));
        }

        $this->em->clear();

        $thread = $this->em->find(MessageThread::class, $listed);

        self::assertNotNull($thread);
        self::assertSame(4, $thread->messageCount);
        self::assertSame(2, $thread->unreadCount, 'the two replies are unread, the two sent messages are not');
        self::assertSame('DKIM failures', $thread->subject, 'a conversation is named by the message that opened it');
        self::assertNull($this->em->find(MessageThread::class, $orphaned), 'the emptied thread was left standing');
        self::assertTrue(
            $this->wasAnnounced(JmapObjectType::Thread, (string) $orphaned, ChangeType::Destroyed),
            'JMAP clients were not told the emptied thread is gone',
        );
        self::assertTrue(
            $this->wasAnnounced(JmapObjectType::Email, (string) $last->id, ChangeType::Updated),
            'JMAP clients were not told the moved message has a new threadId',
        );
    }

    /**
     * Each message names only its direct parent, and they arrive child first.
     *
     * Nothing in the newest message points at the oldest, so the chain can only
     * close up link by link — and in one batch, where none of the threads
     * involved has reached the database yet, as well as across batches.
     *
     * @param list<list<string>> $batches message keys, in sync order
     */
    #[DataProvider('reverseArrivals')]
    public function testAChainSyncedNewestFirstEndsUpInOneThread(array $batches): void
    {
        $chain = [
            'first'  => $this->message('first', null, 'Quarterly numbers', '-3 hours'),
            'second' => $this->message('second', 'first', 'Re: Quarterly numbers', '-2 hours'),
            'third'  => $this->message('third', 'second', 'Re: Quarterly numbers', '-1 hour'),
        ];

        foreach ($batches as $keys) {
            $this->syncBatch(array_map(static fn (string $key): Message => $chain[$key], $keys));
        }

        $threads = array_unique(array_map($this->storedThreadOf(...), array_values($chain)));

        self::assertCount(1, $threads, 'the chain is split over several threads');

        $this->em->clear();

        $thread = $this->em->find(MessageThread::class, reset($threads));

        self::assertNotNull($thread);
        self::assertSame(3, $thread->messageCount);
        self::assertSame('Quarterly numbers', $thread->subject);
    }

    /**
     * @return iterable<string, array{list<list<string>>}>
     */
    public static function reverseArrivals(): iterable
    {
        yield 'a batch per message' => [[['third'], ['second'], ['first']]];
        yield 'all in one batch'    => [[['third', 'second', 'first']]];
    }

    /**
     * What the conversation already had is kept: the star somebody gave it and
     * the tab somebody dragged it to belong to the thread that survives, and
     * the late message's fresh thread has nothing to set against them.
     */
    public function testTheConversationKeepsItsStarAndItsChosenTab(): void
    {
        $root  = $this->message('kept-root', null, 'Invoice 42', '-2 hours', seen: true);
        $reply = $this->message('kept-reply', 'kept-root', 'Re: Invoice 42', '-1 hour');

        $this->syncBatch([$reply]);

        $thread                   = $reply->thread;
        // Whole seconds: the column stores no fraction, and the claim is the
        // value, not its precision.
        $starred                  = new \DateTimeImmutable('2026-10-15 09:00:00');
        $thread->starredAt        = $starred;
        $thread->category         = MessageCategory::Updates;
        $thread->categoryPinnedAt = $starred;
        $this->em->flush();

        $root->category = MessageCategory::Primary;
        $this->syncBatch([$root]);

        $this->em->clear();

        $kept = $this->em->find(MessageThread::class, $this->storedThreadOf($root));

        self::assertNotNull($kept);
        self::assertSame($thread->id, $kept->id);
        self::assertEquals($starred, $kept->starredAt);
        self::assertSame(MessageCategory::Updates, $kept->category);
    }

    /**
     * Headers that point in a circle, or at their own message, must neither
     * loop nor take anything with them.
     *
     * Two messages that name each other are one conversation, and stay one; a
     * message that names itself is already in its own thread, so there is
     * nothing to merge and nothing must be deleted from under it.
     */
    public function testReferencesInACircleOrToItselfAreHarmless(): void
    {
        $one  = $this->message('circle-one', 'circle-two', 'Re: Loop', '-3 hours');
        $two  = $this->message('circle-two', 'circle-one', 'Re: Loop', '-2 hours');
        $self = $this->message('mirror', 'mirror', 'Mirror', '-1 hour');

        $this->syncBatch([$one]);
        $this->syncBatch([$two, $self]);

        self::assertSame($this->storedThreadOf($one), $this->storedThreadOf($two));
        self::assertNotSame($this->storedThreadOf($one), $this->storedThreadOf($self));

        $this->em->clear();

        self::assertSame(1, $this->em->find(MessageThread::class, $this->storedThreadOf($self))?->messageCount);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * One sync batch, shaped like PostIngestPipeline::run(): the rows are
     * flushed before threading, because threading queries them, and the
     * threads are flushed once, after every message has one.
     *
     * @param list<Message> $batch
     */
    private function syncBatch(array $batch): void
    {
        foreach ($batch as $message) {
            $this->em->persist($message);
        }

        $this->em->flush();

        foreach ($batch as $message) {
            $this->threader->assignThread($message, $this->account);
        }

        $this->em->flush();
    }

    /** Read from the table, not the entity: what a batch left in memory is not the claim. */
    private function storedThreadOf(Message $message): int
    {
        $threadId = $this->messages->findThreadIdFor((int) $message->id);

        self::assertNotNull($threadId, sprintf('"%s" has no thread', $message->subject));

        return $threadId;
    }

    private function wasAnnounced(JmapObjectType $type, string $entityId, ChangeType $change): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                  FROM jmap_change_log
                 WHERE account_id = :accountId
                   AND object_type = :type
                   AND entity_id = :entityId
                   AND change_type = :change
                SQL,
            [
                'accountId' => $this->account->id,
                'type'      => $type->value,
                'entityId'  => $entityId,
                'change'    => $change->value,
            ],
        );
    }

    /**
     * A message as a sync builds it, with ids made unique per run so a parent
     * can only ever be found inside this test's own fixture.
     */
    private function message(string $key, ?string $inReplyTo, string $subject, string $receivedAt, bool $seen = false): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = 'correspondent@example.test';
        $message->receivedAt     = new \DateTimeImmutable($receivedAt);
        $message->seenAt         = true === $seen ? new \DateTimeImmutable($receivedAt) : null;
        $message->hasAttachments = false;
        $message->messageId      = $this->messageId($key);
        $message->inReplyTo      = null === $inReplyTo ? [] : [$this->messageId($inReplyTo)];
        $message->references     = $message->inReplyTo;

        return $message;
    }

    private function messageId(string $key): string
    {
        return sprintf('%s-%d@example.test', $key, (int) $this->account->id);
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'out-of-order-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Out';
        $user->nameLast  = 'Of Order';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'out-of-order-fixture-' . uniqid('', true) . '@example.test';

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $email;
        $account->username       = $email;
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

        return $account;
    }
}
