<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ProcessReadReceiptsHandler;
use App\Infrastructure\Messaging\Message\ProcessReadReceiptsMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\BounceCorrelator;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\ReadReceiptCorrelator;
use App\Service\Mail\ReadReceiptPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bounces, and the four ways a delivery status notification must NOT be read
 * as one.
 *
 * The refusals are the point of this file, exactly as they are next door in
 * ReadReceiptTest. A bounce that never surfaces leaves someone believing they
 * have written to a person they have not — but a bounce shown for a message
 * that is merely being retried, or for someone else's mail, is worse: it
 * teaches the user that the warning means nothing, which costs them the one
 * time it is real.
 *
 * So: only `failed`, never `delayed`, never `delivered`, and only against a
 * message this account actually sent.
 */
final class BounceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private ReadReceiptPolicy $policy;

    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->policy        = $container->get(ReadReceiptPolicy::class);

        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->inboxMailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The whole feature in one test: a hard bounce arrives, and the sent
     * message stops claiming it was sent.
     *
     * Every stamped field is asserted, because each one answers a different
     * question the user has at that moment — who it failed for, what the
     * server said, and whether this is final.
     */
    public function testAHardBounceStampsTheMessageThatFailed(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-doomed@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Reporting-MTA: dns; mx.example.test

            Final-Recipient: rfc822; nobody@example.test
            Original-Message-ID: <outgoing-doomed@example.test>
            Action: failed
            Status: 5.1.1
            Diagnostic-Code: smtp; 550 5.1.1 <nobody@example.test>: Recipient
             address rejected: User unknown in local recipient table
            DSN);

        $this->em->flush();
        $this->runIngestHandler([$dsn]);

        self::assertNotNull($sent->bouncedAt, 'the sent message is where "not delivered" comes from');
        self::assertSame('5.1.1', $sent->bounceStatus);
        self::assertSame('nobody@example.test', $sent->bounceRecipient);
        self::assertNotNull($sent->bounceDiagnostic);
        self::assertStringContainsString(
            'User unknown in local recipient table',
            $sent->bounceDiagnostic,
            'the folded continuation line carries half the sentence and must be joined, not dropped',
        );
    }

    /**
     * A bounce is left in the Inbox, unread — the opposite of what happens to
     * a read receipt.
     *
     * Its body is the SMTP transcript, which is routinely the only readable
     * statement of what went wrong, and a failure the user may have to act on
     * is not something to file away on their behalf.
     */
    public function testABounceIsNotFiledAwayTheWayAReceiptIs(): void
    {
        $thread = $this->thread();
        $this->sentMessage($thread, 'outgoing-keep@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; nobody@example.test
            Original-Message-ID: <outgoing-keep@example.test>
            Action: failed
            Status: 5.2.2
            Diagnostic-Code: smtp; 552 Mailbox full
            DSN);

        $dsn->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));
        $this->em->flush();

        $this->runIngestHandler([$dsn]);

        self::assertNull($dsn->seenAt, 'a bounce is unread mail the person still has to read');
        self::assertFalse($dsn->hasFlag(MessageFlag::SEEN));
        self::assertTrue(
            $dsn->hasLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account)),
            'the transcript stays where it landed',
        );
    }

    /**
     * `Action: delayed` is a message STILL IN FLIGHT.
     *
     * Postfix emits one of these after four hours and keeps trying for five
     * days. Stamping it as undelivered would tell the user their mail failed
     * at the exact moment it is being retried — and nothing would ever clear
     * the stamp when it went through.
     */
    public function testADelayNoticeIsNotABounce(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-slow@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; slow@example.test
            Original-Message-ID: <outgoing-slow@example.test>
            Action: delayed
            Status: 4.4.7
            Diagnostic-Code: smtp; 451 Temporary failure, will retry
            DSN);

        $this->em->flush();
        $this->runIngestHandler([$dsn]);

        self::assertNull($sent->bouncedAt, 'still being retried is not the same as failed');
        self::assertNull($sent->bounceStatus);
    }

    /**
     * A success DSN — some MTAs send them on request — must stamp nothing.
     */
    public function testADeliveryConfirmationIsNotABounce(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-fine@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; fine@example.test
            Original-Message-ID: <outgoing-fine@example.test>
            Action: delivered
            Status: 2.0.0
            DSN);

        $this->em->flush();
        $this->runIngestHandler([$dsn]);

        self::assertNull($sent->bouncedAt);
    }

    /**
     * A bounce about a message we never sent stamps nothing.
     *
     * Backscatter — a bounce for mail forged in this account's name — arrives
     * at real mailboxes constantly, and the Original-Message-ID in one names
     * a message that does not exist here. The lookup is what refuses it.
     */
    public function testABounceForAMessageWeNeverSentIsIgnored(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-ours@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; victim@elsewhere.test
            Original-Message-ID: <never-came-from-here@spammer.test>
            Action: failed
            Status: 5.1.1
            DSN);

        $this->em->flush();
        $this->runIngestHandler([$dsn]);

        self::assertNull($sent->bouncedAt, 'backscatter names a message id this mailbox does not hold');
    }

    /**
     * An ordinary reply quoting the words "action" and "final-recipient" is
     * not a DSN. Detection must come from the report structure, not from a
     * word appearing in prose.
     */
    public function testAnOrdinaryReplyIsNotMistakenForABounce(): void
    {
        $reply = new Message();
        $reply->account        = $this->account;
        $reply->subject        = 'Re: your mail';
        $reply->fromAddress    = 'them@example.test';
        $reply->messageId      = 'a-real-reply@example.test';
        $reply->receivedAt     = new \DateTimeImmutable();
        $reply->hasAttachments = false;
        $reply->bodyText       = 'No action needed on my side, thanks.';
        $reply->headers        = ['content-type' => 'text/plain; charset=utf-8'];
        $reply->mailbox        = $this->inboxMailbox;
        $reply->imapUid        = 6300;

        $this->em->persist($reply);
        $this->em->flush();

        $correlator = self::getContainer()->get(BounceCorrelator::class);

        self::assertFalse($correlator->isDeliveryStatusNotification($reply));
    }

    /**
     * The first bounce wins.
     *
     * A message to several recipients comes back once per failed address, and
     * without this the recipient shown would walk forward to whoever bounced
     * most recently — turning a stable statement into a changing one.
     */
    public function testTheFirstBounceWinsWhenSeveralRecipientsFail(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-many@example.test');

        $first = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; first@example.test
            Original-Message-ID: <outgoing-many@example.test>
            Action: failed
            Status: 5.1.1
            DSN, uid: 6401);

        $second = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; second@example.test
            Original-Message-ID: <outgoing-many@example.test>
            Action: failed
            Status: 5.2.2
            DSN, uid: 6402);

        $this->em->flush();

        $this->runIngestHandler([$first]);
        $this->runIngestHandler([$second]);

        self::assertSame('first@example.test', $sent->bounceRecipient);
        self::assertSame('5.1.1', $sent->bounceStatus);
    }

    /**
     * The returned copy of the original is a legitimate second source for the
     * id, and plenty of MTAs send no Original-Message-ID field at all.
     *
     * Safe because a DSN's own Message-ID is in its header bag, never its
     * body: an id in the text can only have come from the attached original.
     */
    public function testTheIdIsFoundInTheReturnedOriginalWhenTheFieldIsMissing(): void
    {
        $thread = $this->thread();
        $sent   = $this->sentMessage($thread, 'outgoing-returned@example.test');

        $dsn = $this->dsn($thread, <<<'DSN'
            Final-Recipient: rfc822; nobody@example.test
            Action: failed
            Status: 5.1.1

            --boundary
            Content-Type: text/rfc822-headers

            From: sender-fixture@example.test
            To: nobody@example.test
            Message-ID: <outgoing-returned@example.test>
            Subject: The one that failed
            DSN);

        $this->em->flush();
        $this->runIngestHandler([$dsn]);

        self::assertNotNull($sent->bouncedAt);
        self::assertSame('nobody@example.test', $sent->bounceRecipient);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function sentMessage(MessageThread $thread, string $messageId): Message
    {
        $sent = new Message();
        $sent->account        = $this->account;
        $sent->subject        = 'The one that failed';
        $sent->fromAddress    = 'sender-fixture@example.test';
        $sent->messageId      = $messageId;
        $sent->sentAt         = new \DateTimeImmutable('-2 hours');
        $sent->hasAttachments = false;
        $sent->mailbox        = $this->inboxMailbox;
        $sent->imapUid        = 6100 + \count($thread->messages);
        $thread->addMessage($sent);

        $this->em->persist($sent);

        return $sent;
    }

    private function dsn(MessageThread $thread, string $body, int $uid = 6200): Message
    {
        $dsn = new Message();
        $dsn->account        = $this->account;
        $dsn->subject        = 'Undelivered Mail Returned to Sender';
        $dsn->fromAddress    = 'MAILER-DAEMON@mx.example.test';
        $dsn->messageId      = 'the-bounce-' . $uid . '@mx.example.test';
        $dsn->receivedAt     = new \DateTimeImmutable('-5 minutes');
        $dsn->hasAttachments = false;
        $dsn->mailbox        = $this->inboxMailbox;
        $dsn->imapUid        = $uid;
        $dsn->headers        = [
            'content-type' => 'multipart/report; report-type=delivery-status; boundary="boundary"',
        ];
        // Heredoc indentation is stripped by PHP, but the folded continuation
        // line must keep its leading space or the unfolding under test has
        // nothing to unfold.
        $dsn->bodyText = str_replace("\n", "\r\n", $body);
        $thread->addMessage($dsn);

        $this->em->persist($dsn);

        return $dsn;
    }

    private function thread(): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Bounce fixture';
        $thread->normalizedSubject = 'bounce fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function runIngestHandler(array $messages): void
    {
        $ids       = array_map(static fn (Message $m): int => (int) $m->id, $messages);
        $container = self::getContainer();

        $handler = new ProcessReadReceiptsHandler(
            $container->get(MessageRepository::class),
            $container->get(ReadReceiptCorrelator::class),
            $container->get(BounceCorrelator::class),
            $this->policy,
            $container->get(MailChangeRecorder::class),
            $this->em,
        );

        $handler(new ProcessReadReceiptsMessage($ids));
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email     = 'bounce-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Bounce';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'sender-fixture@example.test';
        $account->username       = 'sender-fixture@example.test';
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

    private function seedMailbox(string $name = 'INBOX'): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
