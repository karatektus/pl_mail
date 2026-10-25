<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Event\Subscriber\SendGaveUpSubscriber;
use App\Infrastructure\Messaging\Handler\SendMessageHandler;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Crypto\AutocryptHeader;
use App\Service\Crypto\SealedDrafts;
use App\Service\Crypto\SealGuard;
use App\Service\Crypto\SendingKey;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\RawMessageResolver;
use App\Service\Mail\SendOutcomeNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * A send that fails is retried when that can help, and said once when it ends
 * (#125).
 *
 * The real handler, send service and database, with the sender scripted (see
 * ScriptedDeliverySender) and the hub captured: what the senders say is what
 * this decides on, and what reaches the browser and the row is what it decides.
 * Messenger itself is not here — a test of "the handler threw something
 * recoverable, with this delay" is the handler's half of a retry, and the
 * transport's half is Symfony's.
 *
 * Inside a transaction, which also fixes the database clock: LOCALTIMESTAMP is
 * the transaction's start, so a retry's elapsed time is zero unless a test
 * moves the clock back itself.
 */
final class SendRetryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARefusalIsSaidAtOnceAndMarksTheDraft(): void
    {
        $id = $this->seedDraft();

        $this->handler(new ScriptedDeliverySender([
            new SendRefusedException('550 no such user', SendFailure::Refused),
        ]))(new SendMessageMessage($id));

        $row = $this->row($id);
        self::assertNotNull($row['send_failed_at'], 'the draft is marked');
        self::assertSame('refused', $row['send_failure']);
        self::assertNull($row['send_claimed_at'], 'and can be sent again');
        self::assertSame([false], $this->outcomes(), 'one failure toast');
    }

    public function testAPassingFailureIsHandedBackToBeRetriedAndSaysNothing(): void
    {
        $id = $this->seedDraft();

        try {
            $this->handler(new ScriptedDeliverySender([
                new SendDeferredException('503', SendFailure::Unavailable),
            ]))(new SendMessageMessage($id));
            self::fail('a passing failure is retried, so it is thrown back to Messenger');
        } catch (SendDeferredException $retry) {
            self::assertSame(5000, $retry->getRetryDelay(), 'quickly, the first time');
        }

        $row = $this->row($id);
        self::assertNull($row['send_failed_at'], 'no warning while it is being retried');
        self::assertNotNull($row['send_retrying_since'], 'the two minutes have started');
        self::assertNull($row['send_claimed_at'], 'the retry can claim it');
        self::assertSame([], $this->outcomes(), 'and no toast');
    }

    public function testTheProvidersRetryAfterIsTheWait(): void
    {
        $id = $this->seedDraft();

        $this->expectExceptionObject(new SendDeferredException('429', SendFailure::RateLimited, 60));

        try {
            $this->handler(new ScriptedDeliverySender([
                new SendDeferredException('429', SendFailure::RateLimited, 60),
            ]))(new SendMessageMessage($id));
        } catch (SendDeferredException $retry) {
            self::assertSame(60000, $retry->getRetryDelay());

            throw $retry;
        }
    }

    public function testTwoMinutesOfRetriesEndInAWarning(): void
    {
        $id = $this->seedDraft();
        $this->connection->executeStatement(
            "UPDATE message SET send_retrying_since = LOCALTIMESTAMP - INTERVAL '121 seconds' WHERE id = :id",
            ['id' => $id],
        );

        $this->handler(new ScriptedDeliverySender([
            new SendDeferredException('429', SendFailure::RateLimited, 5),
        ]))(new SendMessageMessage($id));

        $row = $this->row($id);
        self::assertSame('rate_limited', $row['send_failure']);
        self::assertNull($row['send_retrying_since'], 'the clock is stopped for the next send');
        self::assertSame([false], $this->outcomes());
    }

    public function testAWaitThatWouldOutlastTheTwoMinutesEndsNow(): void
    {
        $id = $this->seedDraft();

        $this->handler(new ScriptedDeliverySender([
            new SendDeferredException('429', SendFailure::RateLimited, 300),
        ]))(new SendMessageMessage($id));

        self::assertSame('rate_limited', $this->row($id)['send_failure']);
        self::assertSame([false], $this->outcomes());
    }

    /**
     * The Message-ID is on the row before the first attempt, so a retry sends
     * — and a lost answer is looked for — under the same one.
     */
    public function testARetrySendsUnderTheSameMessageId(): void
    {
        $id     = $this->seedDraft();
        $sender = new ScriptedDeliverySender([new SendDeferredException('503', SendFailure::Unavailable), null]);

        try {
            $this->handler($sender)(new SendMessageMessage($id));
        } catch (SendDeferredException) {
        }

        $this->em->clear();
        $this->handler($sender)(new SendMessageMessage($id));

        self::assertCount(2, $sender->delivered);
        self::assertSame($sender->delivered[0], $sender->delivered[1]);
        self::assertNotNull($this->row($id)['sent_at']);
    }

    public function testALostAnswerIsLookedForInSentAndNotSentAgainWhenFound(): void
    {
        $id     = $this->seedDraft();
        $sender = new ScriptedDeliverySender([new SendOutcomeUnknownException('timed out')], inSent: true);

        try {
            $this->handler($sender)(new SendMessageMessage($id));
            self::fail('a lost answer on a provider that can be asked is checked on the next attempt');
        } catch (SendDeferredException $retry) {
            self::assertSame(1000 * SendMessageHandler::CHECK_SENT_AFTER_SECONDS, $retry->getRetryDelay());
        }

        self::assertNotNull($this->row($id)['send_unconfirmed_at']);
        self::assertSame([], $this->outcomes(), 'nothing said yet');

        $this->em->clear();
        $this->handler($sender)(new SendMessageMessage($id));

        self::assertCount(1, $sender->delivered, 'found in Sent, so not sent again');
        self::assertCount(1, $sender->lookedFor);
        $row = $this->row($id);
        self::assertNotNull($row['sent_at'], 'recorded as the send it was');
        self::assertNull($row['send_unconfirmed_at']);
        self::assertSame([true], $this->outcomes());
    }

    public function testALostAnswerThatIsNotInSentIsSentAgain(): void
    {
        $id     = $this->seedDraft();
        $sender = new ScriptedDeliverySender([new SendOutcomeUnknownException('connection reset'), null], inSent: false);

        try {
            $this->handler($sender)(new SendMessageMessage($id));
        } catch (SendDeferredException) {
        }

        $this->em->clear();
        $this->handler($sender)(new SendMessageMessage($id));

        self::assertCount(2, $sender->delivered);
        self::assertNotNull($this->row($id)['sent_at']);
        self::assertSame([true], $this->outcomes());
    }

    public function testALostAnswerThatCannotBeLookedForIsSaidAsPossiblySent(): void
    {
        $id     = $this->seedDraft();
        $sender = new ScriptedDeliverySender([new SendOutcomeUnknownException('timed out')], inSent: new \RuntimeException('Gmail is down'));

        try {
            $this->handler($sender)(new SendMessageMessage($id));
        } catch (SendDeferredException) {
        }

        $this->em->clear();
        $this->handler($sender)(new SendMessageMessage($id));

        self::assertCount(1, $sender->delivered, 'never sent again blind');
        self::assertSame('may_have_sent', $this->row($id)['send_failure']);
        self::assertSame([false], $this->outcomes());
    }

    /** SMTP: a lost answer cannot be checked at all, so it is said at once. */
    public function testALostAnswerOnASenderThatCannotBeAskedIsSaidAtOnce(): void
    {
        $id = $this->seedDraft();

        $smtp = new class implements MailDeliveryInterface {
            public int $attempts = 0;

            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(Email $email, Account $account): bool
            {
                return false;
            }

            public function deliver(Email $email, Account $account): void
            {
                ++$this->attempts;

                throw new SendOutcomeUnknownException('Connection to "smtp" has been closed unexpectedly.');
            }

            public function filesSentCopy(): bool
            {
                return true;
            }
        };

        $this->handler($smtp)(new SendMessageMessage($id));

        self::assertSame(1, $smtp->attempts);
        self::assertSame('may_have_sent', $this->row($id)['send_failure']);
        self::assertSame([false], $this->outcomes());
    }

    public function testASuccessfulSendLiftsAnEarlierWarning(): void
    {
        $id = $this->seedDraft();
        $this->connection->executeStatement(
            "UPDATE message SET send_failed_at = LOCALTIMESTAMP, send_failure = 'refused' WHERE id = :id",
            ['id' => $id],
        );

        $this->handler(new ScriptedDeliverySender([null]))(new SendMessageMessage($id));

        $row = $this->row($id);
        self::assertNotNull($row['sent_at']);
        self::assertNull($row['send_failed_at']);
        self::assertNull($row['send_failure']);
    }

    /** A sender that only says yes or no is taken at its word, as before. */
    public function testAPlainFalseIsARefusal(): void
    {
        $id = $this->seedDraft();

        $plain = new class implements MailSenderInterface {
            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(Email $email, Account $account): bool
            {
                return false;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }
        };

        $this->handler($plain)(new SendMessageMessage($id));

        self::assertSame('refused', $this->row($id)['send_failure']);
    }

    /**
     * Messenger's last retry running out is the one end the handler does not
     * see. The subscriber marks the draft with what the sender last said.
     */
    public function testTheLastRetryRunningOutMarksTheDraft(): void
    {
        $id = $this->seedDraft();

        $this->subscriber()->onFailed($this->failedEvent($id, willRetry: false, thrown: new SendDeferredException('429', SendFailure::RateLimited, 5)));

        self::assertSame('rate_limited', $this->row($id)['send_failure']);
        self::assertSame([false], $this->outcomes());
    }

    public function testAFailureThatWillBeRetriedIsNotTheEnd(): void
    {
        $id = $this->seedDraft();

        $this->subscriber()->onFailed($this->failedEvent($id, willRetry: true, thrown: new SendDeferredException('503', SendFailure::Unavailable)));

        self::assertNull($this->row($id)['send_failed_at']);
        self::assertSame([], $this->outcomes());
    }

    public function testAnUnclassifiedFailureIsMarkedAsFailed(): void
    {
        $id = $this->seedDraft();

        $this->subscriber()->onFailed($this->failedEvent($id, willRetry: false, thrown: new \RuntimeException('token refresh failed')));

        self::assertSame('failed', $this->row($id)['send_failure']);
    }

    public function testTheDraftsWarningCountsMarkedDraftsOnly(): void
    {
        $id         = $this->seedDraft();
        $repository = self::getContainer()->get(MessageRepository::class);
        $message    = $this->em->find(Message::class, $id);
        self::assertNotNull($message);
        $user = $message->account->usr;
        self::assertNotNull($user);

        $repository->markSendFailed($id, SendFailure::Unavailable);
        self::assertSame(0, $repository->countUnsentDraftsForUser($user), 'not filed as a draft, not counted');

        // Filed under Drafts, as the Drafts list selects it.
        $message->labels->add(self::getContainer()->get(LabelResolver::class)->systemLabel(LabelRole::Drafts, $message->account));
        $this->em->flush();

        self::assertSame(1, $repository->countUnsentDraftsForUser($user));
    }

    private function handler(MailSenderInterface $sender): SendMessageHandler
    {
        $container = self::getContainer();
        $imap      = $this->createStub(ImapConnectionFactory::class);

        $service = new MessageSendService(
            $container->get(MailboxRepository::class),
            $this->em,
            new MailSenderRegistry([$sender]),
            $imap,
            $container->get(AttachmentResolver::class),
            $container->get(LabelResolver::class),
            $container->get(LabelRepository::class),
            $container->get(MailChangeRecorder::class),
            $container->get(MessageThreader::class),
            $container->get(ThreadLabelSynchronizer::class),
            $container->get(SealGuard::class),
            $container->get(SealedDrafts::class),
            $container->get(AutocryptHeader::class),
            $container->get(SendingKey::class),
            $container->get(RawMessageResolver::class),
            $container->get(DraftAttachmentService::class),
        );

        return new SendMessageHandler(
            $container->get(MessageRepository::class),
            $service,
            $this->em,
            $this->notifier(),
        );
    }

    private function subscriber(): SendGaveUpSubscriber
    {
        return new SendGaveUpSubscriber(
            self::getContainer()->get(MessageRepository::class),
            $this->em,
            $this->notifier(),
        );
    }

    private function notifier(): SendOutcomeNotifier
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });

        return new SendOutcomeNotifier($hub, self::getContainer()->get(Environment::class), new NullLogger());
    }

    private function failedEvent(int $id, bool $willRetry, \Throwable $thrown): WorkerMessageFailedEvent
    {
        $envelope = new Envelope(new SendMessageMessage($id));
        $event    = new WorkerMessageFailedEvent($envelope, 'export', new HandlerFailedException($envelope, [$thrown]));

        if (true === $willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    /** @return list<bool> what each published send outcome said: sent or not */
    private function outcomes(): array
    {
        return array_map(
            static fn (Update $update): bool => (bool) json_decode($update->getData(), true)['sent'],
            $this->published,
        );
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT sent_at, send_claimed_at, send_failed_at, send_failure, send_retrying_since, send_unconfirmed_at, message_id
               FROM message WHERE id = :id',
            ['id' => $id],
        );

        self::assertIsArray($row);

        return $row;
    }

    private function seedDraft(): int
    {
        $user = new User();
        $user->email = 'retry-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Re';
        $user->nameLast = 'Try';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'retry@example.test';
        $account->username = 'retry@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $sent = new Mailbox();
        $sent->account       = $account;
        $sent->name          = 'Sent';
        $sent->fullPath      = 'Sent';
        $sent->specialUse    = MailboxSpecialUse::SENT;
        $sent->isSyncEnabled = true;
        $sent->isIdleEnabled = false;
        $this->em->persist($sent);

        $message = new Message();
        $message->account        = $account;
        $message->subject        = 'Try again';
        $message->fromAddress    = 'retry@example.test';
        $message->toAddresses    = [['address' => 'rike@example.test']];
        $message->bodyText       = 'Try again';
        $message->hasAttachments = false;
        $message->flags          = [];
        $this->em->persist($message);

        $this->em->flush();
        $this->em->clear();

        return (int) $message->id;
    }
}
