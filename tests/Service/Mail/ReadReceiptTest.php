<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\MessagePriority;
use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Interface\MailSenderInterface;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Infrastructure\Messaging\Handler\ProcessReadReceiptsHandler;
use App\Infrastructure\Messaging\Handler\SendReadReceiptHandler;
use App\Infrastructure\Messaging\Message\ProcessReadReceiptsMessage;
use App\Infrastructure\Messaging\Message\SendReadReceiptMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\BounceCorrelator;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\ReadReceiptCorrelator;
use App\Service\Mail\ReadReceiptPolicy;
use App\Service\Mail\ReadReceiptSender;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Part\AbstractMultipartPart;

/**
 * Read receipts, both directions, and the three ways they must refuse.
 *
 * The refusals are what most of this file is about, because they are what can
 * go wrong quietly. A receipt that fails to send is a missing line in someone's
 * sent mail; a receipt that sends when it should not have is a confirmation to
 * a stranger that an address is live, monitored, and was being read at a
 * particular minute — and the user never sees it happen. So: the default sends
 * nothing, a sync-discovered read sends nothing, and a request pointing
 * somewhere other than the sender is never answered automatically however the
 * mailbox is configured.
 *
 * Against a real container and database, like ThreadStatusUpdaterTest next
 * door, and for the same reason: the behaviour worth pinning here emerges from
 * a policy, an updater, a bus and a MIME builder acting together, and each of
 * them in isolation would pass a test that the combination fails.
 */
final class ReadReceiptTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadStatusUpdater $updater;
    private LabelResolver $labelResolver;
    private ReadReceiptPolicy $policy;

    private Account $account;
    private Mailbox $inboxMailbox;

    /** MIME captured from the stand-in transport. */
    private ?SymfonyEmail $lastOutgoing = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->updater       = $container->get(ThreadStatusUpdater::class);
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

    // ── Outgoing: asking for one ─────────────────────────────────────────────

    /**
     * The flag on the row has to become headers on the wire, or the whole
     * outgoing half is a column nobody reads.
     *
     * Both headers, because clients disagree about which one to honour, and
     * both naming the sending identity — a receipt addressed anywhere else is
     * the shape this codebase refuses to auto-answer on the way in, and plMail
     * must not emit mail that trips its own guard.
     */
    public function testRequestingAReceiptPutsBothHeadersOnTheOutgoingMail(): void
    {
        $draft = $this->draft();
        $draft->readReceiptRequested = true;

        $this->em->flush();

        self::assertTrue($this->sendService()->send($draft));

        $mime = $this->outgoing()->toString();

        self::assertMatchesRegularExpression(
            '/^Disposition-Notification-To:\s*.*sender-fixture@example\.test/mi',
            $mime,
            'the RFC 8098 request header must name the sending identity',
        );
        self::assertMatchesRegularExpression(
            '/^Return-Receipt-To:\s*.*sender-fixture@example\.test/mi',
            $mime,
            'the older convention travels alongside it, for clients that only read that one',
        );
    }

    /**
     * Priority is the other half of the same migration and the same menu, and
     * it takes two headers for the same reason: setting only one marks the mail
     * urgent in half the world.
     */
    public function testPriorityPutsBothConventionsOnTheOutgoingMail(): void
    {
        $draft = $this->draft();
        $draft->priority = MessagePriority::High;

        $this->em->flush();

        self::assertTrue($this->sendService()->send($draft));

        $mime = $this->outgoing()->toString();

        self::assertMatchesRegularExpression('/^X-Priority:\s*1\b/mi', $mime);
        self::assertMatchesRegularExpression('/^Importance:\s*High\b/mi', $mime);
    }

    /**
     * A message nobody asked anything about carries neither, so ordinary mail
     * does not grow headers to express the default.
     */
    public function testAnOrdinaryMessageCarriesNeitherHeader(): void
    {
        $draft = $this->draft();

        self::assertTrue($this->sendService()->send($draft));

        $mime = $this->outgoing()->toString();

        self::assertDoesNotMatchRegularExpression('/^Disposition-Notification-To:/mi', $mime);
        self::assertDoesNotMatchRegularExpression('/^X-Priority:/mi', $mime);
        self::assertDoesNotMatchRegularExpression('/^Importance:/mi', $mime);
    }

    // ── Incoming: the default must be silent ─────────────────────────────────

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * A mailbox nobody has configured receives a message asking for a receipt,
     * the user opens it, and nothing whatsoever leaves. Not a queued job that
     * the handler later declines — nothing, because the setting was never
     * turned on and a user who has not opened this panel must be unable to
     * emit a confirmation that their address is live.
     */
    public function testTheDefaultSettingSendsNothingWhenAMessageIsRead(): void
    {
        $message = $this->receiptRequestedMessage();

        self::assertSame(
            ReadReceiptMode::Never,
            $this->policy->configuredMode($this->account, $message),
            'an untouched account must resolve to Never before anything else is asserted',
        );

        $this->updater->markRead([$message], true);

        self::assertCount(
            0,
            $this->queuedReceipts(),
            'reading a message in an unconfigured mailbox must queue no receipt at all',
        );
        self::assertTrue(
            $message->wantsReadReceipt(),
            'the request stays on the row — it was ignored, not answered',
        );
    }

    /**
     * The second silent case, and the one the plan called the main correctness
     * risk: a sync pass learning that a message was read on another device
     * weeks ago cannot claim a person just displayed it here.
     *
     * applyRemoteFlags() is the inbound twin of markRead() and takes the
     * provider's word for the flag. It must never reach the receipt path, even
     * with the mailbox set to answer automatically — so this is asserted under
     * "always", where a leak would actually send.
     */
    public function testASyncDiscoveredSeenFlagFiresNoReceipt(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage();

        $this->applyRemoteSeen($message);

        self::assertNotNull($message->seenAt, 'the fixture must actually mark the row read');
        self::assertCount(
            0,
            $this->queuedReceipts(),
            'a read discovered by sync is not a read a receipt may be claimed for',
        );
    }

    /**
     * Re-marking an already-read message read is what the bulk toolbar does
     * routinely over a mixed selection. Only the transition may fire.
     */
    public function testMarkingAnAlreadyReadMessageReadAgainFiresNothing(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage();
        $message->seenAt = new \DateTimeImmutable('-1 day');
        $this->em->flush();

        $this->updater->markRead([$message], true);

        self::assertCount(0, $this->queuedReceipts());
    }

    // ── Incoming: always ─────────────────────────────────────────────────────

    /**
     * With the mailbox set to answer automatically, a genuine first read queues
     * exactly one send.
     */
    public function testAlwaysQueuesOneReceiptOnTheFirstRead(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage();

        $this->updater->markRead([$message], true);

        $queued = $this->queuedReceipts();

        self::assertCount(1, $queued);
        self::assertSame((int) $message->id, $queued[0]->messageId);
    }

    /**
     * The MDN itself, asserted as a structure rather than as "something was
     * sent". A receipt that is not a well-formed multipart/report arrives at
     * the other end as an empty message with a mystery attachment, which is
     * indistinguishable from the feature not working.
     */
    public function testAlwaysProducesAWellFormedMultipartReport(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage();

        $this->runSendHandler($message);

        $email = $this->outgoing();

        // ── The container ────────────────────────────────────────────────
        $body = $email->getBody();

        self::assertInstanceOf(AbstractMultipartPart::class, $body);
        self::assertSame('multipart', $body->getMediaType());
        self::assertSame('report', $body->getMediaSubtype());

        $mime = $email->toString();

        self::assertMatchesRegularExpression(
            '/Content-Type:\s*multipart\/report;.*report-type=disposition-notification/is',
            $mime,
            'without report-type the container is a bounce as far as the recipient is concerned',
        );

        // ── The two halves ───────────────────────────────────────────────
        $parts = $body->getParts();

        self::assertCount(2, $parts, 'prose for a person, fields for software — both are mandatory');
        self::assertSame('text', $parts[0]->getMediaType());
        self::assertSame('plain', $parts[0]->getMediaSubtype());
        self::assertSame('message', $parts[1]->getMediaType());
        self::assertSame('disposition-notification', $parts[1]->getMediaSubtype());

        // ── The fields ───────────────────────────────────────────────────
        self::assertMatchesRegularExpression(
            '/^Final-Recipient:\s*rfc822;\s*receiver-fixture@example\.test\s*$/mi',
            $mime,
            'Final-Recipient is defined as address-type then address; a bare address is unparseable',
        );
        self::assertMatchesRegularExpression(
            '/^Original-Message-ID:\s*<incoming-asked@example\.test>\s*$/mi',
            $mime,
        );
        self::assertMatchesRegularExpression(
            '/^Disposition:\s*automatic-action\/MDN-sent-automatically;\s*displayed\s*$/mi',
            $mime,
            'the software decided, and the receipt has to say so',
        );

        // ── The envelope ─────────────────────────────────────────────────
        self::assertSame('asker@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('receiver-fixture@example.test', $email->getFrom()[0]->getAddress());
        self::assertMatchesRegularExpression(
            '/^Auto-Submitted:\s*auto-replied\s*$/mi',
            $mime,
            'without this an MDN and a vacation responder can answer each other forever',
        );

        // ── And it cannot happen twice ───────────────────────────────────
        self::assertFalse(
            $message->wantsReadReceipt(),
            'the request has been answered; a replay or a re-read must not answer it again',
        );
    }

    /**
     * An explicit confirmation reports itself as manual, because the difference
     * between "a person displayed this" and "a rule fired" is the only thing
     * the receipt asserts.
     */
    public function testAnAskModeConfirmationReportsItselfAsManual(): void
    {
        $this->setMode(ReadReceiptMode::Ask);

        $message  = $this->receiptRequestedMessage();
        $decision = $this->policy->decide($message);

        self::assertTrue($decision->needsPrompt(), 'ask mode is what draws the prompt');

        $this->readReceiptSender()->send($message, $decision);

        self::assertMatchesRegularExpression(
            '/^Disposition:\s*manual-action\/MDN-sent-manually;\s*displayed\s*$/mi',
            $this->outgoing()->toString(),
        );
    }

    // ── Incoming: the mismatch downgrade ─────────────────────────────────────

    /**
     * A Disposition-Notification-To that is not the sender is the exfiltration
     * shape: mail arrives looking like it is from someone you know, and the
     * read confirmation goes somewhere else entirely.
     *
     * Downgraded to Ask and not to Never — the request may be a legitimate
     * bulk sender collecting at its bounce address — but never answered
     * without a human, however the mailbox is configured.
     */
    public function testAMismatchedNotifyAddressIsDowngradedToAskEvenUnderAlways(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage(notifyTo: 'collector@elsewhere.test');

        $decision = $this->policy->decide($message);

        self::assertSame(ReadReceiptMode::Ask, $decision->mode);
        self::assertTrue($decision->downgraded, 'the view has to be able to explain why it is asking');
        self::assertTrue($decision->needsPrompt());

        // And the automatic path stays shut, at both gates.
        $this->updater->markRead([$message], true);

        self::assertCount(
            0,
            $this->queuedReceipts(),
            'the read transition must not queue a send for a downgraded decision',
        );

        $this->runSendHandler($message);

        self::assertNull(
            $this->lastOutgoing,
            'and the handler must refuse it too, in case anything ever queues one',
        );
    }

    /**
     * The legitimate version of the same shape: a sender whose Return-Path
     * collects the receipt. It agrees, so it is not downgraded — otherwise
     * every newsletter that asks would put a prompt in front of the reader.
     */
    public function testAReturnPathThatCollectsTheReceiptCountsAsAgreement(): void
    {
        $this->setMode(ReadReceiptMode::Always);

        $message = $this->receiptRequestedMessage(
            notifyTo: 'bounces@example.test',
            extraHeaders: ['return-path' => '<bounces@example.test>'],
        );

        $decision = $this->policy->decide($message);

        self::assertSame(ReadReceiptMode::Always, $decision->mode);
        self::assertFalse($decision->downgraded);
    }

    // ── The per-alias setting ────────────────────────────────────────────────

    /**
     * The setting is per alias because a work address that answers and a
     * personal one that never does are the same mailbox here.
     */
    public function testAnAliasOverrideBeatsTheAccountDefault(): void
    {
        $alias = $this->seedAlias('receiver-fixture@example.test');

        $this->setMode(ReadReceiptMode::Never);
        $this->account->setSetting(
            Account::readReceiptAliasSetting((int) $alias->id),
            ReadReceiptMode::Always->value,
        );
        $this->em->flush();

        $message = $this->receiptRequestedMessage();

        self::assertSame(ReadReceiptMode::Always, $this->policy->configuredMode($this->account, $message));
    }

    /**
     * An alias with no answer of its own follows the account, rather than
     * falling to Never — otherwise setting the default once would reach
     * nothing.
     */
    public function testAnAliasWithNoOverrideFollowsTheAccountDefault(): void
    {
        $this->seedAlias('receiver-fixture@example.test');
        $this->setMode(ReadReceiptMode::Ask);

        $message = $this->receiptRequestedMessage();

        self::assertSame(ReadReceiptMode::Ask, $this->policy->configuredMode($this->account, $message));
    }

    // ── The return leg ───────────────────────────────────────────────────────

    /**
     * A receipt comes back for something we sent: the sent message learns when
     * it was read, and the report itself stops being unread inbox mail.
     *
     * Both halves matter. Without the first, requesting a receipt tells the
     * user nothing. Without the second, the answer arrives as a message titled
     * "Read: …" whose body is MIME jargon, sitting unread in the inbox.
     */
    public function testAnInboundReceiptStampsTheSentMessageAndLeavesTheInbox(): void
    {
        $thread = $this->thread();

        $sent = new Message();
        $sent->account        = $this->account;
        $sent->subject        = 'Please confirm';
        $sent->fromAddress    = 'sender-fixture@example.test';
        $sent->messageId      = 'outgoing-asked@example.test';
        $sent->sentAt         = new \DateTimeImmutable('-2 hours');
        $sent->hasAttachments = false;
        $sent->mailbox        = $this->inboxMailbox;
        $sent->imapUid        = 5100;
        $thread->addMessage($sent);
        $this->em->persist($sent);

        $mdn = new Message();
        $mdn->account        = $this->account;
        $mdn->subject        = 'Read: Please confirm';
        $mdn->fromAddress    = 'them@example.test';
        $mdn->messageId      = 'the-receipt@example.test';
        $mdn->receivedAt     = new \DateTimeImmutable('-10 minutes');
        $mdn->hasAttachments = false;
        $mdn->mailbox        = $this->inboxMailbox;
        $mdn->imapUid        = 5101;
        $mdn->headers        = [
            'content-type' => 'multipart/report; report-type=disposition-notification; boundary="x"',
        ];
        $mdn->bodyText = "Final-Recipient: rfc822;them@example.test\r\n"
            . "Original-Message-ID: <outgoing-asked@example.test>\r\n"
            . "Disposition: manual-action/MDN-sent-manually; displayed\r\n";
        $mdn->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));
        $thread->addMessage($mdn);
        $this->em->persist($mdn);

        $thread->unreadCount = 1;
        $this->em->flush();

        $this->runIngestHandler([$mdn]);

        self::assertNotNull($sent->readReceiptAt, 'the sent message is where "Read at …" comes from');
        self::assertNotNull($mdn->seenAt, 'the report must not sit in the unread count');
        self::assertTrue($mdn->hasFlag(MessageFlag::SEEN));
        self::assertSame(0, $thread->unreadCount);

        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);

        self::assertFalse(
            $mdn->hasLabel($inbox),
            'filed rather than deleted — findable in Archive and search, absent from the inbox list',
        );
    }

    /**
     * An ordinary reply is not a disposition notification, however much it
     * threads like one. The In-Reply-To fallback that finds the original
     * message must not be what decides that something IS a report.
     */
    public function testAnOrdinaryReplyIsNotMistakenForAReceipt(): void
    {
        $reply = new Message();
        $reply->account        = $this->account;
        $reply->subject        = 'Re: Please confirm';
        $reply->fromAddress    = 'them@example.test';
        $reply->messageId      = 'a-real-reply@example.test';
        $reply->receivedAt     = new \DateTimeImmutable();
        $reply->hasAttachments = false;
        $reply->inReplyTo      = ['outgoing-asked@example.test'];
        $reply->bodyText       = 'Yes, got it, thanks.';
        $reply->headers        = ['content-type' => 'text/plain; charset=utf-8'];
        $reply->mailbox        = $this->inboxMailbox;
        $reply->imapUid        = 5200;

        $this->em->persist($reply);
        $this->em->flush();

        $correlator = self::getContainer()->get(ReadReceiptCorrelator::class);

        self::assertFalse($correlator->isDispositionNotification($reply));
    }

    /**
     * The other direction of the ingest scan: an inbound request is recorded on
     * the row so the read path never re-parses headers, and it is recorded
     * regardless of the setting — "they asked" is true whatever the answer is.
     */
    public function testIngestFlagsAnInboundRequestWhateverTheSettingSays(): void
    {
        $message = $this->receiptRequestedMessage(preFlagged: false);

        self::assertNull($message->readReceiptRequested, 'the fixture must start unexamined');

        $this->runIngestHandler([$message]);

        self::assertTrue($message->wantsReadReceipt());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * An inbound message whose sender asked for a receipt.
     *
     * @param array<string, string> $extraHeaders
     */
    private function receiptRequestedMessage(
        string $notifyTo = 'asker@example.test',
        array  $extraHeaders = [],
        bool   $preFlagged = true,
    ): Message {
        $thread = $this->thread();

        $message = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Would you confirm?';
        $message->fromAddress    = 'asker@example.test';
        $message->messageId      = 'incoming-asked@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->mailbox        = $this->inboxMailbox;
        $message->imapUid        = 5300;
        $message->toAddresses    = [['name' => null, 'address' => 'receiver-fixture@example.test']];
        $message->headers        = array_merge(
            ['disposition-notification-to' => $notifyTo],
            $extraHeaders,
        );

        if (true === $preFlagged) {
            $message->readReceiptRequested = true;
        }

        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));
        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function draft(): Message
    {
        $draft = new Message();
        $draft->account        = $this->account;
        $draft->subject        = 'A message with opinions';
        $draft->bodyText       = 'Body.';
        $draft->fromAddress    = 'sender-fixture@example.test';
        $draft->toAddresses    = [['name' => null, 'address' => 'them@example.test']];
        $draft->hasAttachments = false;
        $draft->thread         = $this->thread();

        $this->em->persist($draft);
        $this->em->flush();

        return $draft;
    }

    private function thread(): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Receipt fixture';
        $thread->normalizedSubject = 'receipt fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 1;

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function setMode(ReadReceiptMode $mode): void
    {
        $this->account->setSetting(Account::SETTING_READ_RECEIPT_DEFAULT, $mode->value);
        $this->em->flush();
    }

    private function seedAlias(string $address): EmailAlias
    {
        $alias = new EmailAlias(
            account: $this->account,
            address: $address,
            source: EmailAliasSource::Manual,
            status: EmailAliasStatus::Active,
        );

        $this->account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }

    /**
     * The inbound flag path, driven the way a sync pass drives it.
     */
    private function applyRemoteSeen(Message $message): void
    {
        $state = new \App\Domain\DTO\Mail\RemoteFlagState(
            message: $message,
            seen: true,
            flagged: false,
            flags: [MessageFlag::SEEN->value],
        );

        $this->updater->applyRemoteFlags([$state]);
    }

    private function runIngestHandler(array $messages): void
    {
        $ids = array_map(static fn (Message $m): int => (int) $m->id, $messages);

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

    private function runSendHandler(Message $message): void
    {
        $handler = new SendReadReceiptHandler(
            self::getContainer()->get(MessageRepository::class),
            $this->policy,
            $this->readReceiptSender(),
        );

        $handler(new SendReadReceiptMessage((int) $message->id));
    }

    /**
     * The real builder wired to a stand-in transport, because the real one
     * would dial localhost:587 and swallow the failure into `false` — which is
     * indistinguishable from the send being refused.
     */
    private function readReceiptSender(): ReadReceiptSender
    {
        $container = self::getContainer();

        return new ReadReceiptSender(
            new MailSenderRegistry([$this->capturingSender()]),
            $container->get('translator'),
            $this->em,
            $container->get('logger'),
        );
    }

    private function sendService(): MessageSendService
    {
        $container = self::getContainer();

        return new MessageSendService(
            $container->get(MailboxRepository::class),
            $this->em,
            new MailSenderRegistry([$this->capturingSender()]),
            $container->get(ImapConnectionFactory::class),
            $container->get(AttachmentResolver::class),
            $this->labelResolver,
            $container->get(LabelRepository::class),
            $container->get(MailChangeRecorder::class),
            $container->get(MessageThreader::class),
            $container->get(ThreadLabelSynchronizer::class),
        );
    }

    private function capturingSender(): MailSenderInterface
    {
        $test = $this;

        return new class ($test) implements MailSenderInterface {
            public function __construct(private readonly ReadReceiptTest $test)
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

            /** True so the manual IMAP APPEND is skipped — there is no server here. */
            public function filesSentCopy(): bool
            {
                return true;
            }
        };
    }

    /** @internal called by the stand-in sender above */
    public function captureOutgoing(SymfonyEmail $email): void
    {
        $this->lastOutgoing = $email;
    }

    private function outgoing(): SymfonyEmail
    {
        self::assertNotNull($this->lastOutgoing, 'nothing was handed to a transport');

        return $this->lastOutgoing;
    }

    /**
     * By name, not whichever transport happens to exist: a receipt is mail
     * leaving plMail and belongs on the send queue. Routing it onto ingest
     * would put it behind a mailbox sync.
     */
    private function exportQueue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Only the receipts on the send queue.
     *
     * Filtered rather than counted whole, because marking a message read also
     * queues the outbound flag op that carries \Seen to the provider — every
     * read puts an ApplyImapFlagsMessage on this transport whatever the receipt
     * setting is. A bare count would pass the "sends nothing" assertions for
     * the wrong reason, and fail them for the wrong reason too.
     *
     * @return list<SendReadReceiptMessage>
     */
    private function queuedReceipts(): array
    {
        $receipts = [];

        foreach ($this->exportQueue()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendReadReceiptMessage) {
                $receipts[] = $message;
            }
        }

        return $receipts;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email     = 'receipt-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Receipt';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'receiver-fixture@example.test';
        $account->username       = 'receiver-fixture@example.test';
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
