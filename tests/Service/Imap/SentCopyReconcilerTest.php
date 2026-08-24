<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Tests\Support\Imap\ImapUidSequence;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageFlag;
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
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Imap\SentCopyReconciler;
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
 * A message you sent appears once, however many stores hold a copy of it.
 *
 * The bug this pins was reported from the web frontend on an IMAP account: a
 * reply written in the browser showed up twice in its own conversation, once
 * collapsed and once expanded, same sender and same body and same minute, and
 * the thread header counted one message more than the thread held.
 *
 * The mechanism was an identity gap on both sides at once. The row the composer
 * wrote had message_id NULL — nothing on the send path ever set it — and the
 * MIME was left for Symfony to label, which it does inside getPreparedHeaders()
 * on a *clone*, so serialising the same Email for the SMTP transport and again
 * for the Sent-folder APPEND produced two different Message-IDs. Rows were
 * keyed on mailbox+UID, the local row had no UID, and the only reconcile hook
 * in the syncer required a gmailId the row did not have. So the Sent copy came
 * back as an unrecognised UID, was inserted as a second row, and the threader
 * filed it beside the original.
 *
 * These assertions therefore start at a real send through the real service, and
 * hand the resulting Message-ID to the real claim path exactly as the syncer
 * reads it off an IMAP message. A test that called the reconciler with an id it
 * made up would pass just as happily with the send path still writing NULL.
 */
final class SentCopyReconcilerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DraftPersister $drafts;
    private SentCopyReconciler $reconciler;
    private MessageRepository $messages;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Mailbox $inbox;
    private Mailbox $sent;

    /** The MIME the sender was handed, so the wire format can be inspected. */
    private ?SymfonyEmail $lastOutgoing = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->drafts     = $container->get(DraftPersister::class);
        $this->reconciler = $container->get(SentCopyReconciler::class);
        $this->messages   = $container->get(MessageRepository::class);
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

    // ── the reported bug ──────────────────────────────────────────────────

    /**
     * The whole report, in one test: reply from the frontend, sync the Sent
     * folder, count the conversation.
     */
    public function testTheSentCopyComingBackFromTheServerIsNotASecondRow(): void
    {
        $original = $this->incoming('Rechnung', 'kunde@example.test');
        $reply    = $this->sendReplyTo($original);

        self::assertSame(
            $original->thread,
            $reply->thread,
            'the reply has to be in the conversation it answers, or this test proves nothing',
        );

        // What the syncer does with the appended copy on the next poll: it
        // reads the Message-ID off the IMAP message and offers it up.
        $claimed = $this->reconciler->claim($this->sent, (string) $reply->messageId, 4321);

        self::assertNotNull($claimed, 'the Sent copy must be recognised as the message already stored');
        self::assertSame($reply->id, $claimed->id, 'and recognised as that row, not merely as some row');

        $this->em->flush();

        self::assertSame(2, $this->countRowsIn($reply->thread), 'one original plus one reply');
        self::assertSame(2, $reply->thread->messageCount, 'and the header has to say so too');
    }

    /**
     * Claiming is not a no-op: the row takes on the server location it was
     * missing, which is what makes flags, moves and the next sync work on it.
     */
    public function testTheClaimedRowTakesOnTheServerLocation(): void
    {
        $reply = $this->sendReplyTo($this->incoming('Angebot', 'kunde@example.test'));

        $this->reconciler->claim($this->sent, (string) $reply->messageId, 991);
        $this->em->flush();

        self::assertSame(991, $reply->imapUid);
        self::assertSame($this->sent->id, $reply->mailbox?->id);
    }

    // ── the identity that makes reconciliation possible ───────────────────

    public function testASentMessageIsStoredWithItsRfcMessageId(): void
    {
        $reply = $this->sendReplyTo($this->incoming('Nachfrage', 'kunde@example.test'));

        self::assertNotNull($reply->messageId);
        self::assertNotSame('', $reply->messageId, 'a sent row without a Message-ID has no durable identity at all');
    }

    /**
     * The defect underneath the duplicate. Symfony mints a Message-ID into the
     * clone getPreparedHeaders() returns, so before this fix every
     * serialisation of the same Email invented a fresh one: the recipient's
     * copy and the copy APPENDed to Sent were, by their own headers, different
     * messages.
     */
    public function testTheOutgoingMailKeepsOneMessageIdHoweverOftenItIsSerialised(): void
    {
        $reply = $this->sendReplyTo($this->incoming('Termin', 'kunde@example.test'));

        self::assertNotNull($this->lastOutgoing);

        $first  = $this->messageIdOf($this->lastOutgoing->toString());
        $second = $this->messageIdOf($this->lastOutgoing->toString());

        self::assertSame($first, $second, 'the SMTP copy and the Sent APPEND must agree on one id');
        self::assertSame(
            $reply->messageId,
            $first,
            'and the row has to carry the same id, or nothing can reconcile the two',
        );
    }

    // ── the other stores ──────────────────────────────────────────────────

    /**
     * Providers that file their own Sent copy of everything they relay leave
     * two UIDs in the folder for one message. Both carry the Message-ID we
     * stamped, because both descend from the mail we handed to SMTP.
     */
    public function testAProviderSAutoSavedSentCopyIsNotAThirdRow(): void
    {
        $reply = $this->sendReplyTo($this->incoming('Lieferung', 'kunde@example.test'));

        $ourAppend = $this->reconciler->claim($this->sent, (string) $reply->messageId, 5001);
        $this->em->flush();

        $serverCopy = $this->reconciler->claim($this->sent, (string) $reply->messageId, 5002);
        $this->em->flush();

        self::assertNotNull($ourAppend);
        self::assertNotNull($serverCopy, 'the second copy has to be recognised, not inserted');
        self::assertSame($reply->id, $serverCopy->id);
        self::assertSame(2, $this->countRowsIn($reply->thread));
    }

    /**
     * The scoping rule. Two mails that read identically are still two mails,
     * and nothing here may fold them together — which is why the match is on
     * the Message-ID and never on subject, sender or body.
     */
    public function testTwoIdenticalLookingSendsKeepTheirOwnRows(): void
    {
        $original = $this->incoming('Statusfrage', 'kunde@example.test');

        $first  = $this->sendReplyTo($original, 'Noch da?');
        $second = $this->sendReplyTo($original, 'Noch da?');

        self::assertNotSame(
            $first->messageId,
            $second->messageId,
            'two sends are two messages even when they read the same',
        );

        $this->reconciler->claim($this->sent, (string) $first->messageId, 6001);
        $this->reconciler->claim($this->sent, (string) $second->messageId, 6002);
        $this->em->flush();

        self::assertSame(3, $this->countRowsIn($original->thread), 'one original and two genuinely distinct replies');
    }

    /**
     * A folder that is not Sent keeps its own semantics: a repeated Message-ID
     * there is a list resending or a server redelivering, and deciding those
     * are one message is not this class's call.
     */
    public function testARepeatedMessageIdOutsideSentIsLeftForTheSyncerToInsert(): void
    {
        $arrived = $this->incoming('Newsletter', 'liste@example.test');
        $arrived->mailbox = $this->inbox;
        $arrived->imapUid = 12;
        $this->em->flush();

        self::assertNull(
            $this->reconciler->claim($this->inbox, (string) $arrived->messageId, 13),
            'INBOX dedup is not this class to decide',
        );
    }

    // ── self-repair of what the old send path left behind ─────────────────

    /**
     * Accounts that already have the duplicate fix themselves on the next sync
     * of the Sent folder, with no migration and nothing for the user to do.
     */
    public function testDuplicatesFromTheOldSendPathAreRepairedOnTheNextSentSync(): void
    {
        [$ghost, $imported] = $this->legacyDuplicate();
        $thread = $ghost->thread;

        // Read before the repair: removing an entity clears its id.
        $ghostId    = (int) $ghost->id;
        $importedId = (int) $imported->id;

        self::assertSame(3, $this->countRowsIn($thread), 'the reported state: two originals worth of rows plus a doubled reply');

        $removed = $this->reconciler->repair($this->sent);

        self::assertSame(1, $removed);
        self::assertSame(2, $this->countRowsIn($thread), 'the conversation as it should always have been');
        self::assertSame(2, $thread->messageCount, 'and a header that agrees with it');

        self::assertNull(
            $this->messages->find($ghostId),
            'the row that could never be reconciled is the one that goes',
        );
        self::assertNotNull(
            $this->messages->find($importedId),
            'the row with the UID, the headers and the Message-ID is the one that stays',
        );
    }

    /**
     * The counter-case that stops the repair from eating real mail: a send
     * whose copy never came back from the server has no twin, so it is not a
     * duplicate of anything.
     */
    public function testASentRowWithNoServerCopyIsNotTreatedAsADuplicate(): void
    {
        $reply = $this->sendReplyTo($this->incoming('Einzelfall', 'kunde@example.test'));

        // Put it back in the pre-fix shape: sent, filed in Sent, no identity.
        $reply->messageId = null;
        $this->em->flush();

        self::assertSame(0, $this->reconciler->repair($this->sent));
        self::assertNotNull($this->messages->find($reply->id));
    }

    /**
     * Two sends, one server copy: the pairing is one-to-one, so exactly one
     * ghost goes. Matching loosely would have removed both and lost a message
     * the user really did send.
     */
    public function testPairingIsOneToOneWhenAConversationHasSeveralGhosts(): void
    {
        [$ghost, ] = $this->legacyDuplicate();

        $secondGhost = $this->row('Re: Rechnung', $this->account->email, seenAt: new DateTimeImmutable());
        $secondGhost->sentAt    = new DateTimeImmutable('2026-08-10 11:20:00');
        $secondGhost->mailbox   = $this->sent;
        $secondGhost->messageId = null;
        $secondGhost->imapUid   = null;
        $this->attach($secondGhost, $ghost->thread);
        $this->em->flush();

        $survivorIds = [(int) $ghost->id, (int) $secondGhost->id];

        self::assertSame(1, $this->reconciler->repair($this->sent), 'one imported copy can only account for one ghost');

        $survivors = array_filter(
            $survivorIds,
            fn (int $id): bool => null !== $this->messages->find($id),
        );

        self::assertCount(1, $survivors, 'the send with no server copy of its own has to keep its row');
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * A reply written and sent the way the frontend does it: a draft through
     * DraftPersister, then the real MessageSendService.
     *
     * The sender is a stand-in only because the real one would dial
     * localhost:587 and swallow the failure into `false`, which is
     * indistinguishable from the send being refused. filesSentCopy() is true so
     * the manual APPEND is skipped — there is no IMAP server here — and the
     * MIME it is handed is kept so the wire format can be asserted on.
     */
    private function sendReplyTo(Message $original, string $body = 'Habt ihr mich vergessen?'): Message
    {
        $draft = new Message();
        $draft->subject  = 'Re: ' . $original->subject;
        $draft->bodyHtml = '<p>' . $body . '</p>';
        $draft->toAddresses = [['name' => null, 'address' => (string) $original->fromAddress]];
        $draft->inReplyTo   = [(string) $original->messageId];
        $draft->references  = [(string) $original->messageId];
        $draft->thread      = $original->thread;
        $draft->hasAttachments = false;

        $this->drafts->save($draft, $this->account);

        $sent = $this->sendService()->send($draft);

        self::assertTrue($sent, 'the fixture send must succeed or every assertion below is vacuous');

        return $draft;
    }

    private function sendService(): MessageSendService
    {
        $container = self::getContainer();
        $test      = $this;

        $sender = new class ($test) implements MailSenderInterface {
            public function __construct(private readonly SentCopyReconcilerTest $test)
            {
            }

            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(SymfonyEmail $email, Account $account): bool
            {
                $this->test->captureOutgoing($email);

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

    /** @internal called by the stand-in sender above */
    public function captureOutgoing(SymfonyEmail $email): void
    {
        $this->lastOutgoing = $email;
    }

    private function messageIdOf(string $mime): string
    {
        self::assertSame(1, preg_match('/^Message-ID:\s*(.+)$/mi', $mime, $matches), 'outgoing mail must carry a Message-ID');

        return MessageIdHelper::normalise($matches[1]);
    }

    /**
     * The exact pair the old send path left in the database: a locally written
     * row with neither UID nor Message-ID, and the copy the syncer imported
     * from the server beside it in the same conversation.
     *
     * Built by hand rather than by sending, because the send path no longer
     * produces it — which is the point of the fix.
     *
     * @return array{Message, Message}
     */
    private function legacyDuplicate(): array
    {
        $original = $this->incoming('Rechnung', 'kunde@example.test');
        $thread   = $original->thread;

        $ghost = $this->row('Re: Rechnung', $this->account->email, seenAt: new DateTimeImmutable());
        $ghost->sentAt    = new DateTimeImmutable('2026-08-10 11:08:00');
        $ghost->mailbox   = $this->sent;
        $ghost->messageId = null;
        $ghost->imapUid   = null;
        $this->attach($ghost, $thread);

        $imported = $this->row('Re: Rechnung', $this->account->email, seenAt: new DateTimeImmutable());
        $imported->sentAt     = new DateTimeImmutable('2026-08-10 11:08:00');
        $imported->receivedAt = new DateTimeImmutable('2026-08-10 11:08:00');
        $imported->mailbox    = $this->sent;
        $imported->messageId  = MessageIdHelper::mint((string) $this->account->email);
        $imported->imapUid    = 77;
        $this->attach($imported, $thread);

        $this->em->flush();

        return [$ghost, $imported];
    }

    /** A message that arrived from outside, already threaded. */
    private function incoming(string $subject, string $from): Message
    {
        $message = $this->row($subject, $from);
        $message->messageId  = MessageIdHelper::mint($from);
        $message->mailbox    = $this->inbox;
        $message->imapUid    = ImapUidSequence::next();
        $message->receivedAt = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt     = $message->receivedAt;

        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 0;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $this->em->persist($thread);

        $this->attach($message, $thread);
        $this->em->flush();

        return $message;
    }

    private function row(string $subject, ?string $from, ?DateTimeImmutable $seenAt = null): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = $from;
        $message->bodyText       = $subject;
        $message->hasAttachments = false;
        $message->seenAt         = $seenAt;
        $message->flags          = [];

        $this->em->persist($message);

        return $message;
    }

    private function attach(Message $message, MessageThread $thread): void
    {
        $thread->addMessage($message);
        $thread->messageCount = $thread->messageCount + 1;

        if (null === $message->seenAt) {
            $thread->unreadCount = $thread->unreadCount + 1;
        }
    }

    private function countRowsIn(?MessageThread $thread): int
    {
        self::assertNotNull($thread);

        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.thread = :thread',
        )->setParameter('thread', $thread)->getSingleScalarResult();
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'sentcopy-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Sent';
        $this->user->nameLast = 'Copy';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'sentcopy-fixture@example.test';
        $this->account->username = 'sentcopy-fixture@example.test';
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

        // The send path files the message into whatever folder the Sent label
        // is bound to, so the binding has to exist for the fixture to reproduce
        // what the frontend does.
        $this->labels->bindMailbox(
            $this->labels->systemLabel(LabelRole::Sent, $this->account),
            $this->sent,
        );
        $this->labels->bindMailbox(
            $this->labels->systemLabel(LabelRole::Inbox, $this->account),
            $this->inbox,
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
