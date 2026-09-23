<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SendMessageHandler;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\SendOutcomeNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Once the provider has accepted a mail, nothing that fails afterwards may
 * send it again.
 *
 * The Sent-folder APPEND ran after the send with nothing around it, so an IMAP
 * server that refused the connection threw into SendMessageHandler, which
 * released the claim and rethrew — and Messenger's retry sent the mail a
 * second time, and a third, up to six.
 */
final class SendMessageHandlerNoDuplicateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private int $sends = 0;

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

    public function testAFailedSentAppendDoesNotLeadToASecondSend(): void
    {
        $id      = $this->seedDraft();
        $handler = $this->handler();

        $handler(new SendMessageMessage($id));

        // What Messenger's retry would do had the first attempt thrown.
        $this->em->clear();
        $handler(new SendMessageMessage($id));

        self::assertSame(1, $this->sends, 'the mail left exactly once');

        $this->em->clear();
        self::assertNotNull($this->em->find(Message::class, $id)?->sentAt, 'the send is recorded');
    }

    private function handler(): SendMessageHandler
    {
        $container = self::getContainer();

        $sender = new class ($this->sends) implements MailSenderInterface {
            public function __construct(private int &$sends)
            {
            }

            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(SymfonyEmail $email, Account $account): bool
            {
                ++$this->sends;

                return true;
            }

            // SMTP-shaped: plMail appends the Sent copy itself.
            public function filesSentCopy(): bool
            {
                return false;
            }
        };

        $imap = $this->createStub(ImapConnectionFactory::class);
        $imap->method('connect')->willThrowException(new RuntimeException('IMAP server unreachable'));

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
        );

        return new SendMessageHandler(
            $container->get(MessageRepository::class),
            $service,
            $this->em,
            $container->get(SendOutcomeNotifier::class),
        );
    }

    private function seedDraft(): int
    {
        $user = new User();
        $user->email = 'no-dup-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'No';
        $user->nameLast = 'Duplicate';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'no-dup@example.test';
        $account->username = 'no-dup@example.test';
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
        $message->subject        = 'Once only';
        $message->fromAddress    = 'no-dup@example.test';
        $message->toAddresses    = [['address' => 'rike@example.test']];
        $message->bodyText       = 'Once only';
        $message->hasAttachments = false;
        $message->flags          = [];
        $this->em->persist($message);

        $this->em->flush();
        $this->em->clear();

        return (int) $message->id;
    }
}
