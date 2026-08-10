<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Answering a conversation moves it to the top of the list.
 *
 * Every thread list in this app orders on message_thread.last_message_at, and
 * until now only the threader wrote that column — which meant only *incoming*
 * mail could move a conversation. You answered something and it stayed exactly
 * where the last mail you received had left it, sinking under everything that
 * arrived afterwards, which is not how any mail client has behaved in fifteen
 * years. The rule wanted here is Gmail's: order by the newest message in the
 * conversation whichever direction it went.
 *
 * The assertions go through MessageThreadRepository rather than reading the
 * column, because the column is not the feature — the order the list comes back
 * in is.
 */
final class ThreadOrderingOnSendTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DraftPersister $drafts;
    private MessageThreader $threader;
    private MessageThreadRepository $threads;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Mailbox $inbox;
    private Mailbox $sent;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->drafts     = $container->get(DraftPersister::class);
        $this->threader   = $container->get(MessageThreader::class);
        $this->threads    = $container->get(MessageThreadRepository::class);
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

    // ── the feature ───────────────────────────────────────────────────────

    public function testAnsweringAThreadMovesItToTheTopOfTheInbox(): void
    {
        $older  = $this->conversation('Rechnung', new DateTimeImmutable('2026-08-10 09:00:00'));
        $newer  = $this->conversation('Angebot', new DateTimeImmutable('2026-08-10 10:00:00'));

        self::assertSame(
            [$newer->id, $older->id],
            $this->inboxOrder(),
            'baseline: the list is ordered by what last arrived',
        );

        $this->reply($older);

        self::assertSame(
            [$older->id, $newer->id],
            $this->inboxOrder(),
            'the conversation you just answered belongs at the top',
        );
    }

    /**
     * The sort key has to actually be the reply's own send time, not merely
     * "now" — a distinction that only shows once something else has happened
     * since.
     */
    public function testTheThreadIsRankedByTheTimeTheReplyWasSent(): void
    {
        $thread = $this->conversation('Nachfrage', new DateTimeImmutable('2026-08-10 09:00:00'));

        $before = new DateTimeImmutable();
        $reply  = $this->reply($thread);
        $after  = new DateTimeImmutable();

        self::assertNotNull($reply->sentAt);
        self::assertSame(
            $reply->sentAt->format('Y-m-d H:i:s'),
            $thread->lastMessageAt?->format('Y-m-d H:i:s'),
        );
        self::assertGreaterThanOrEqual($before->getTimestamp(), $thread->lastMessageAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $thread->lastMessageAt->getTimestamp());
    }

    // ── the edge cases ────────────────────────────────────────────────────

    /**
     * A half-written reply is not activity. Autosave runs every few seconds, so
     * a draft that bumped would reorder the inbox under the user's hands while
     * they were still typing.
     */
    public function testADraftDoesNotMoveTheThread(): void
    {
        $older = $this->conversation('Rechnung', new DateTimeImmutable('2026-08-10 09:00:00'));
        $newer = $this->conversation('Angebot', new DateTimeImmutable('2026-08-10 10:00:00'));

        $this->replyDraft($older);

        self::assertSame([$newer->id, $older->id], $this->inboxOrder(), 'writing is not sending');

        // And still not after an autosave of the same draft.
        $this->replyDraft($older);

        self::assertSame([$newer->id, $older->id], $this->inboxOrder());
    }

    /**
     * A draft that opens its own conversation is the only thing that
     * conversation has, so it does have to set the key — otherwise the thread
     * sorts by NULL, which Postgres puts *first* on a DESC order and would pin
     * every abandoned compose to the top of every list.
     */
    public function testADraftThatOpensItsOwnThreadStillGetsASortKey(): void
    {
        $draft = new Message();
        $draft->subject        = 'Etwas Neues';
        $draft->bodyHtml       = '<p>…</p>';
        $draft->hasAttachments = false;

        $this->drafts->save($draft, $this->account);

        self::assertNotNull($draft->thread?->lastMessageAt);
    }

    public function testAnIncomingMessageAfterTheReplyMovesTheThreadAgain(): void
    {
        $answered = $this->conversation('Rechnung', new DateTimeImmutable('2026-08-10 09:00:00'));
        $other    = $this->conversation('Angebot', new DateTimeImmutable('2026-08-10 10:00:00'));

        $this->reply($answered);

        // The customer writes back to the *other* conversation.
        $this->arrive($other, new DateTimeImmutable('+1 hour'));

        self::assertSame(
            [$other->id, $answered->id],
            $this->inboxOrder(),
            'a newer incoming message outranks an older reply, same as any other message',
        );
    }

    /**
     * Backfilled folders, delayed relays and senders with wrong clocks all
     * deliver mail dated in the past. None of them may drag a live conversation
     * back down the list.
     */
    public function testABackdatedIncomingMessageDoesNotJumpAboveNewerActivity(): void
    {
        $answered = $this->conversation('Rechnung', new DateTimeImmutable('2026-08-10 09:00:00'));
        $other    = $this->conversation('Angebot', new DateTimeImmutable('2026-08-10 10:00:00'));

        $this->reply($answered);

        $wasAt = $other->lastMessageAt;

        $this->arrive($other, new DateTimeImmutable('2026-08-01 08:00:00'));

        self::assertEquals($wasAt, $other->lastMessageAt, 'the sort key only ever moves forward');
        self::assertSame([$answered->id, $other->id], $this->inboxOrder());
    }

    /**
     * The Sent list is ordered by the same column, so it has to keep sorting by
     * the user's own send dates rather than by whatever arrived last.
     */
    public function testTheSentListIsOrderedByTheUsersOwnSendDates(): void
    {
        $first  = $this->conversation('Rechnung', new DateTimeImmutable('2026-08-10 09:00:00'));
        $second = $this->conversation('Angebot', new DateTimeImmutable('2026-08-10 10:00:00'));

        $this->reply($second);
        $this->reply($first);

        $order = array_map(
            static fn (MessageThread $thread): ?int => $thread->id,
            $this->threads->findForRole($this->user, LabelRole::Sent),
        );

        self::assertSame([$first->id, $second->id], $order, 'most recently answered first');
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /** @return list<int|null> thread ids, in the order the inbox renders them */
    private function inboxOrder(): array
    {
        $this->em->flush();

        return array_map(
            static fn (MessageThread $thread): ?int => $thread->id,
            $this->threads->findForUnifiedInbox($this->user, MessageCategory::Primary),
        );
    }

    /** Write and send a reply the way the frontend does. */
    private function reply(MessageThread $thread): Message
    {
        $draft = $this->replyDraft($thread);

        self::assertTrue($this->sendService()->send($draft));

        $this->em->flush();

        return $draft;
    }

    /**
     * Save a reply draft, reusing the same row on a second call so this doubles
     * as an autosave.
     */
    private function replyDraft(MessageThread $thread): Message
    {
        $draft = $this->openDraftIn($thread) ?? new Message();

        $draft->subject        = 'Re: ' . $thread->subject;
        $draft->bodyHtml       = '<p>Hallo, habt ihr mich vergessen?</p>';
        $draft->thread         = $thread;
        $draft->hasAttachments = false;
        $draft->toAddresses    = [['name' => null, 'address' => 'kunde@example.test']];

        $this->drafts->save($draft, $this->account);

        return $draft;
    }

    private function openDraftIn(MessageThread $thread): ?Message
    {
        foreach ($thread->messages as $message) {
            if (true === $message->isDraft() && null === $message->sentAt) {
                return $message;
            }
        }

        return null;
    }

    /** A new message arriving from outside, threaded the way sync threads it. */
    private function arrive(MessageThread $thread, DateTimeImmutable $at): Message
    {
        $message = $this->row('Re: ' . $thread->subject, 'kunde@example.test');
        $message->messageId  = MessageIdHelper::mint('example.test');
        $message->mailbox    = $this->inbox;
        $message->imapUid    = random_int(1000, 9999);
        $message->receivedAt = $at;
        $message->sentAt     = $at;
        $message->inReplyTo  = [];
        $message->references = [];
        $message->category   = MessageCategory::Primary;

        $this->em->flush();

        $this->threader->assignThread($message, $this->account);
        $this->em->flush();

        return $message;
    }

    private function conversation(string $subject, DateTimeImmutable $receivedAt): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $thread->category          = MessageCategory::Primary;
        $thread->lastMessageAt     = $receivedAt;
        $thread->addLabel($this->labels->systemLabel(LabelRole::Inbox, $this->account));
        $this->em->persist($thread);

        $message = $this->row($subject, 'kunde@example.test');
        $message->messageId  = MessageIdHelper::mint('example.test');
        $message->mailbox    = $this->inbox;
        $message->imapUid    = random_int(1, 999);
        $message->receivedAt = $receivedAt;
        $message->sentAt     = $receivedAt;
        $message->seenAt     = $receivedAt;
        $thread->addMessage($message);

        $this->em->flush();

        return $thread;
    }

    /**
     * The Inbox label goes on the message, not just on the thread. The syncer
     * labels every message with its mailbox's label and
     * ThreadLabelSynchronizer derives the thread's labels back off the messages
     * it holds — so a fixture that labelled only the thread would have its
     * Inbox label stripped the first time a draft was saved, and drop out of
     * the very list under test.
     */
    private function row(string $subject, string $from): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = $from;
        $message->bodyText       = $subject;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->addLabel($this->labels->systemLabel(LabelRole::Inbox, $this->account));

        $this->em->persist($message);

        return $message;
    }

    /** See SentCopyReconcilerTest::sendService() for why the sender is a stand-in. */
    private function sendService(): MessageSendService
    {
        $container = self::getContainer();

        $sender = new class implements MailSenderInterface {
            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(SymfonyEmail $email, Account $account): bool
            {
                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }
        };

        return new MessageSendService(
            $container->get(MailboxRepository::class),
            $this->em,
            new MailSenderRegistry([$sender]),
            $container->get(ImapConnectionFactory::class),
            $container->get(AttachmentResolver::class),
            $container->get(LabelResolver::class),
            $container->get(LabelRepository::class),
            $container->get(MailChangeRecorder::class),
            $container->get(MessageThreader::class),
            $container->get(ThreadLabelSynchronizer::class),
        );
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'ordering-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Thread';
        $this->user->nameLast = 'Ordering';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'ordering-fixture@example.test';
        $this->account->username = 'ordering-fixture@example.test';
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

        $this->inbox = $this->mailbox('INBOX', MailboxSpecialUse::INBOX);
        $this->sent  = $this->mailbox('Sent', MailboxSpecialUse::SENT);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->labels->bindMailbox(
            $this->labels->systemLabel(LabelRole::Inbox, $this->account),
            $this->inbox,
        );
        $this->labels->bindMailbox(
            $this->labels->systemLabel(LabelRole::Sent, $this->account),
            $this->sent,
        );

        $this->em->flush();
    }

    private function mailbox(string $name, MailboxSpecialUse $specialUse): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);

        return $mailbox;
    }
}
