<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\RawMessageStorage;
use App\Domain\Helper\SamplePdf;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * An inbox thread whose PDF was stored the way the sync stored it before
 * AttachmentName: named by webklex's hash, because the mailer gave the name
 * only in Content-Type. The original is stored beside it, as the sync stores
 * every original, so AttachmentNameBackfillTask can repair it.
 */
#[AsCommand(
    name: 'app:test:seed-statement-attachment',
    description: 'Seed a thread whose attachment was stored under its hash, for the attachment-name E2E test',
)]
final class SeedStatementAttachmentCommand extends Command
{
    use TargetsTestUser;

    public const string SUBJECT  = 'E2E Account Statement';
    public const string FILENAME = 'TNNTST02_10561121.PDF';

    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly RawMessageStorage       $rawStorage,
        #[Autowire('%kernel.environment%')]
        private readonly string                  $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-statement-attachment must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $this->resolveUserEmail($input)]);

        if (null === $user) {
            $io->error('E2E user not found - run app:test:seed-user first.');

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy(['usr' => $user, 'username' => self::SEED_ACCOUNT_USERNAME]);

        if (!$account instanceof Account) {
            $io->error('E2E mailbox not found - run app:test:seed-mail first.');

            return Command::FAILURE;
        }

        foreach ($this->threadRepository->findBy(['account' => $account, 'subject' => self::SUBJECT]) as $old) {
            $this->entityManager->remove($old);
        }

        $this->entityManager->flush();

        $pdf = SamplePdf::awkward();
        $raw = "From: The Arch Company <NoReply@e2e.test>\r\n"
            . "To: {$user->email}\r\n"
            . 'Subject: ' . self::SUBJECT . "\r\n"
            . "Date: Tue, 17 Nov 2026 03:15:55 +0000\r\n"
            . sprintf("Message-ID: <%s@e2e.test>\r\n", uniqid('statement-', true))
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=us-ascii\r\n\r\n"
            . "<p>Please find your account statement attached.</p>\r\n"
            . "--B\r\n"
            . 'Content-Type: application/octet-stream; name=' . self::FILENAME . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-ID: <10557746>\r\n\r\n"
            . chunk_split(base64_encode($pdf), 76, "\r\n")
            . "--B--\r\n";

        // The name the sync used to give it.
        $hash = '';

        foreach (ImapMessage::fromString($raw, MessageParser::config())->getAttachments() as $attachment) {
            $hash = (string) $attachment->getHash();
        }

        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $now   = new DateTimeImmutable();

        $message = new Message();
        $message->account        = $account;
        $message->subject        = self::SUBJECT;
        $message->fromName       = 'The Arch Company';
        $message->fromAddress    = 'NoReply@e2e.test';
        $message->toAddresses    = [['name' => 'E2E Tester', 'address' => (string) $user->email]];
        $message->bodyText       = 'Please find your account statement attached.';
        $message->receivedAt     = $now;
        $message->sentAt         = $now;
        $message->hasAttachments = true;
        $message->flags          = [];
        $message->syncedAt       = $now;
        $message->addLabel($inbox);
        $this->entityManager->persist($message);

        $thread = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = self::SUBJECT;
        $thread->normalizedSubject = mb_strtolower(self::SUBJECT);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 1;
        $thread->category          = MessageCategory::Primary;
        $thread->attachmentCount   = 1;
        $thread->lastMessageAt     = $now;
        $thread->addLabel($inbox);
        $this->entityManager->persist($thread);
        $message->thread = $thread;

        $this->entityManager->flush();

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'application/octet-stream';
        $part->filename    = $hash;
        $part->disposition = 'attachment';
        $part->size        = strlen($pdf);
        $part->storagePath = $this->attachmentStorage->store((int) $account->id, 0, (int) $message->id, $hash, $pdf);
        $part->isInline    = false;
        $message->addMessagePart($part);
        $this->entityManager->persist($part);

        $message->rawPath = $this->rawStorage->store((int) $account->id, (int) $message->id, $raw);

        $this->entityManager->flush();

        $io->success(sprintf('Seeded "%s" with its attachment stored as "%s".', self::SUBJECT, $hash));

        return Command::SUCCESS;
    }
}
