<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Repository\User\UserRepository;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds one inbox thread carrying a real attachment, for the specs that act on
 * attachment chips.
 *
 * Separate from app:test:seed-mail rather than a fifth entry in its table:
 * that seeder's thread count is asserted on by other specs, so growing it
 * would break them for a reason that has nothing to do with what they test.
 *
 * It does however seed into the *same* account, which is what keeps the two
 * from colliding. seed-mail wipes that account and rebuilds its four threads,
 * so whichever spec file runs second gets the inbox it expects — an extra
 * account would instead leave this thread in the inbox permanently and break
 * every spec that counts rows. This command only ever adds its own thread, and
 * removes a previous copy of it first so re-running is a no-op.
 *
 * The file is written to disk through AttachmentStorageHelper rather than
 * faked, so download and save-to both exercise the real storage path.
 *
 * Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:seed-attachment',
    description: 'Seed an inbox thread with a real attachment for the attachment-UI E2E tests',
)]
final class SeedTestAttachmentCommand extends Command
{
    /** Shared with app:test:seed-mail — see the class docblock. */
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';
    private const string SUBJECT = 'E2E Attachment';
    private const string FILENAME = 'e2e-attachment.txt';
    private const string CONTENTS = "Seeded attachment body.\n";

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly AttachmentStorageHelper $attachmentStorage,
        #[Autowire('%kernel.environment%')]
        private readonly string                  $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-attachment must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $_SERVER['APP_DEV_USER_EMAIL'] ?? 'e2e@plmail.test';
        $user = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->account($user);
        $this->removePreviousSeed($account);

        $inboxLabel = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $now = new DateTimeImmutable();

        $message = new Message()
            ->setAccount($account)
            ->setSubject(self::SUBJECT)
            ->setFromName('E2E Sender')
            ->setFromAddress('sender@e2e.test')
            ->setToAddresses([['name' => 'E2E Tester', 'address' => (string) $user->getEmail()]])
            ->setBodyText('Seeded message with one attachment.')
            ->setReceivedAt($now)
            ->setSentAt($now)
            ->setHasAttachments(true)
            ->setFlags([])
            ->setSyncedAt($now)
            ->setUpdatedAt($now)
            ->addLabel($inboxLabel);

        $this->entityManager->persist($message);

        $thread = new MessageThread();
        $thread
            ->setAccount($account)
            ->setSubject(self::SUBJECT)
            ->setNormalizedSubject(mb_strtolower(self::SUBJECT))
            ->setThreadingMethod(ThreadingMethod::SubjectFallback)
            ->setMessageCount(1)
            ->setUnreadCount(1)
            ->setCategory(MessageCategory::Primary)
            ->setAttachmentCount(1)
            ->setLastMessageAt($now)
            ->addLabel($inboxLabel);

        $this->entityManager->persist($thread);
        $message->setThread($thread);

        // The message id is part of the storage path, so the row has to exist
        // before the file can be written.
        $this->entityManager->flush();

        $storagePath = $this->attachmentStorage->store(
            (int) $account->getId(),
            0,
            (int) $message->getId(),
            self::FILENAME,
            self::CONTENTS,
        );

        $part = new MessagePart()
            ->setMessage($message)
            ->setContentType('text/plain')
            ->setFilename(self::FILENAME)
            ->setDisposition('attachment')
            ->setSize(strlen(self::CONTENTS))
            ->setStoragePath($storagePath)
            ->setIsInline(false);

        $message->addMessagePart($part);
        $this->entityManager->persist($part);
        $this->entityManager->flush();

        $io->success(sprintf('Seeded 1 thread with attachment "%s".', self::FILENAME));

        return Command::SUCCESS;
    }

    private function account(object $user): Account
    {
        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null !== $account) {
            return $account;
        }

        // Normally seed-mail has already made this; creating it here only
        // matters when this command runs on its own.
        $account = new Account();
        $account
            ->setUsr($user)
            ->setName('E2E Mailbox')
            ->setEmail('E2E Mailbox')
            ->setUsername(self::SEED_ACCOUNT_USERNAME)
            ->setImapHost('imap.e2e.test')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setAuthType('password')
            ->setIsActive(true);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Drop only this command's own thread, by subject. The account is shared
     * with seed-mail, so wiping it wholesale would delete the four threads the
     * mail specs are built on.
     */
    private function removePreviousSeed(Account $account): void
    {
        $threads = $this->threadRepository->findBy([
            'account' => $account,
            'subject' => self::SUBJECT,
        ]);

        foreach ($threads as $thread) {
            $this->entityManager->remove($thread);
        }

        if (count($threads) > 0) {
            $this->entityManager->flush();
        }
    }
}
