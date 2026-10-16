<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\State\ChangeType;
use App\Jmap\State\JmapObjectType;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Imap\ThreadMerger;
use App\Service\Label\ThreadLabelSynchronizer;
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

    /**
     * What the conversation that is folded in had comes along, and stays
     * there through the rest of the batch.
     *
     * The late message here is not a root: it answers mail already in the
     * older thread and is answered by mail in a newer one, so the thread it
     * joins survives and the newer one, labelled and snoozed, is absorbed.
     * A label rule runs after threading and before the flush, and re-derives
     * the thread's labels from its messages; the moved mail has to be among
     * them by then, or the label it brought is taken straight off again.
     */
    public function testAnAbsorbedThreadsLabelAndSnoozeOutliveALabelRuleInTheSameBatch(): void
    {
        $label = $this->seedLabel('Budget review');

        $root   = $this->message('absorbed-root', null, 'Budget 2027', '-3 hours', seen: true);
        $middle = $this->message('absorbed-middle', 'absorbed-root', 'Re: Budget 2027', '-2 hours', seen: true);
        $last   = $this->message('absorbed-last', 'absorbed-middle', 'Re: Budget 2027', '-1 hour');
        $last->addLabel($label);

        $this->syncBatch([$root]);
        $this->syncBatch([$last]);

        $snoozedUntil           = new \DateTimeImmutable('2026-10-20 08:00:00');
        $absorbed               = $last->thread;
        $absorbed->snoozedUntil = $snoozedUntil;
        $absorbedId             = $absorbed->id;
        $this->em->flush();

        $this->syncBatch([$middle], function () use ($middle): void {
            (new ThreadLabelSynchronizer())->sync($middle->thread);
        });

        $this->em->clear();

        $kept = $this->em->find(MessageThread::class, $this->storedThreadOf($last));

        self::assertNotNull($kept);
        self::assertSame($this->storedThreadOf($root), $kept->id, 'the older conversation is the one kept');
        self::assertNull($this->em->find(MessageThread::class, $absorbedId));
        self::assertSame(3, $kept->messageCount);
        self::assertSame(
            [$label->id],
            array_values(array_map(static fn (Label $carried): ?int => $carried->id, $kept->labels->toArray())),
            'the label the moved reply carries was taken off the conversation',
        );
        self::assertEquals($snoozedUntil, $kept->snoozedUntil, 'the snooze went with the absorbed thread, and nothing would wake its mail');
    }

    /**
     * Rows stored before Message-IDs were normalised still name their parent
     * as "<x@host>" until the rethread backfill has run, and `@>` compares
     * exactly — so the lookup asks for both spellings.
     */
    public function testAReplyStoredWithABracketedReferenceIsFoundToo(): void
    {
        $root  = $this->message('bracketed-root', null, 'Old mail', '-2 hours', seen: true);
        $reply = $this->message('bracketed-reply', null, 'Re: Old mail', '-1 hour');

        $reply->inReplyTo  = [sprintf('<%s>', $this->messageId('bracketed-root'))];
        $reply->references = $reply->inReplyTo;

        $this->syncBatch([$reply]);
        $this->syncBatch([$root]);

        self::assertSame($this->storedThreadOf($reply), $this->storedThreadOf($root));
    }

    /**
     * A provider-keyed thread is the one kept, even when it is the newer of the
     * two: the key is how step 1 finds a Gmail or Graph conversation again,
     * and a thread that lost it would be opened anew by its next message.
     */
    public function testAProviderKeyedThreadIsNeverTheOneAbsorbed(): void
    {
        $plain = $this->thread('Plain', null);
        $keyed = $this->thread('Keyed', 'provider-conversation-' . uniqid('', true));

        self::assertLessThan($keyed->id, $plain->id, 'the fixture needs the keyed thread to be the newer one');

        $inPlain = $this->message('keyed-upstream', null, 'Plain', '-2 hours', seen: true);
        $inKeyed = $this->message('keyed-downstream', 'keyed-upstream', 'Re: Plain', '-1 hour');

        $inPlain->thread = $plain;
        $inKeyed->thread = $keyed;
        $this->em->persist($inPlain);
        $this->em->persist($inKeyed);
        $this->em->flush();

        $plainId  = $plain->id;
        $survivor = self::getContainer()->get(ThreadMerger::class)->merge($plain, $keyed);
        $this->em->flush();

        self::assertSame($keyed, $survivor);
        self::assertSame($keyed->id, $this->storedThreadOf($inPlain));
        self::assertSame($keyed->id, $this->storedThreadOf($inKeyed));

        $this->em->clear();

        self::assertNull($this->em->find(MessageThread::class, $plainId));
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * One sync batch, shaped like PostIngestPipeline::run(): the rows are
     * flushed before threading, because threading queries them, and the
     * threads are flushed once, after every message has one.
     *
     * $beforeFlush stands in for what the pipeline runs between threading and
     * that flush — the rules.
     *
     * @param list<Message> $batch
     */
    private function syncBatch(array $batch, ?\Closure $beforeFlush = null): void
    {
        foreach ($batch as $message) {
            $this->em->persist($message);
        }

        $this->em->flush();

        foreach ($batch as $message) {
            $this->threader->assignThread($message, $this->account);
        }

        if (null !== $beforeFlush) {
            $beforeFlush();
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

    /** A thread made directly, for the merger's own rules. */
    private function thread(string $subject, ?string $providerThreadKey): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = null === $providerThreadKey ? ThreadingMethod::References : ThreadingMethod::Provider;
        $thread->providerThreadKey = $providerThreadKey;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->category          = MessageCategory::Primary;
        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function seedLabel(string $name): Label
    {
        $label            = new Label();
        $label->usr       = $this->account->usr;
        $label->name      = $name;
        $label->isVisible = true;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
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
