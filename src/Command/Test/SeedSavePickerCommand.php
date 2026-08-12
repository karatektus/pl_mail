<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
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
 * Seeds the fixtures the attachment save-picker specs act on: one inbox thread
 * carrying two attachments of different types, plus a connected file store and
 * a connected photo library for the e2e user.
 *
 * Both halves exist to exercise the mime gate. A photo library must appear in
 * "Save to…" for the image and not for the PDF; a file store must appear for
 * both. That needs exactly this shape — two connections of different kinds and
 * two attachments of different types — which no other seeder provides.
 *
 * The connections are written straight to the database rather than clicked
 * through the connect form, which keeps the spec deterministic and off the
 * network: the picker's own reachability is covered elsewhere, and here the
 * only thing under test is which menu each attachment offers. Their base URLs
 * are deliberately unreachable, so a save that does open the picker fails
 * cleanly rather than depending on a live server the test stack does not have.
 *
 * Idempotent: it removes its own thread and its own two connections first, so a
 * re-run against a dirty database lands on the same state. Refuses to run in
 * prod.
 */
#[AsCommand(
    name: 'app:test:seed-save-picker',
    description: 'Seed a two-attachment thread and two integrations for the save-picker E2E tests',
)]
final class SeedSavePickerCommand extends Command
{
    use TargetsTestUser;

    /** Shared with app:test:seed-mail — see SeedTestAttachmentCommand. */
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';
    private const string SUBJECT = 'E2E Save Picker';

    private const string IMAGE_FILENAME = 'e2e-photo.png';
    private const string PDF_FILENAME = 'e2e-document.pdf';

    /** Connection names, also the marker used to find and replace them. */
    private const string FILE_STORE_NAME = 'E2E SavePicker Cloud';
    private const string PHOTO_LIBRARY_NAME = 'E2E SavePicker Photos';

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly IntegrationRepository   $integrationRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly StateManager            $stateManager,
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
            $io->error('app:test:seed-save-picker must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->account($user);
        $this->removePreviousSeed($account);
        $this->seedConnections($user);

        $inboxLabel = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $now = new DateTimeImmutable();

        $message = new Message();
        $message->account = $account;
        $message->subject = self::SUBJECT;
        $message->fromName = 'E2E Sender';
        $message->fromAddress = 'sender@e2e.test';
        $message->toAddresses = [['name' => 'E2E Tester', 'address' => (string) $user->email]];
        $message->bodyText = 'Seeded message with a photo and a document.';
        $message->receivedAt = $now;
        $message->sentAt = $now;
        $message->hasAttachments = true;
        $message->flags = [];
        $message->syncedAt = $now;
        $message->addLabel($inboxLabel);

        $this->entityManager->persist($message);

        $thread = new MessageThread();
        $thread->account = $account;
        $thread->subject = self::SUBJECT;
        $thread->normalizedSubject = mb_strtolower(self::SUBJECT);
        $thread->threadingMethod = ThreadingMethod::SubjectFallback;
        $thread->messageCount = 1;
        $thread->unreadCount = 1;
        $thread->category = MessageCategory::Primary;
        $thread->attachmentCount = 2;
        $thread->lastMessageAt = $now;
        $thread->addLabel($inboxLabel);

        $this->entityManager->persist($thread);
        $message->thread = $thread;

        // The message id is part of the storage path, so the row has to exist
        // before the files can be written.
        $this->entityManager->flush();

        $accountId = (int) $account->id;
        $this->stateManager->recordCreated($accountId, JmapObjectType::Email, (string) $message->id);
        $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);

        // A 1x1 PNG so a preview request has real bytes to decode; the PDF is a
        // minimal but valid header. Neither matters to the gate, which reads the
        // declared type, but real bytes keep the download path honest.
        $this->storePart($message, self::IMAGE_FILENAME, 'image/png', $this->pngBytes());
        $this->storePart($message, self::PDF_FILENAME, 'application/pdf', "%PDF-1.4\n%%EOF\n");

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded 1 thread with "%s" and "%s", plus a file store and a photo library.',
            self::IMAGE_FILENAME,
            self::PDF_FILENAME,
        ));

        return Command::SUCCESS;
    }

    private function storePart(Message $message, string $filename, string $mime, string $contents): void
    {
        $storagePath = $this->attachmentStorage->store(
            (int) $message->account->id,
            0,
            (int) $message->id,
            $filename,
            $contents,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = $mime;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($contents);
        $part->storagePath = $storagePath;
        $part->isInline    = false;

        $message->addMessagePart($part);
        $this->entityManager->persist($part);
    }

    /**
     * A file store (Nextcloud) and a photo library (Immich), both active and
     * both pointed at an unreachable host on purpose.
     */
    private function seedConnections(object $user): void
    {
        $nextcloud = new Integration($user, Provider::Nextcloud, self::FILE_STORE_NAME);
        $nextcloud->baseUrl = 'https://cloud.invalid.test';
        $nextcloud->username = 'e2e';
        $nextcloud->secret = 'app-password';
        $this->entityManager->persist($nextcloud);

        $immich = new Integration($user, Provider::Immich, self::PHOTO_LIBRARY_NAME);
        $immich->baseUrl = 'https://photos.invalid.test';
        $immich->secret = 'api-key';
        $this->entityManager->persist($immich);

        $this->entityManager->flush();
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

        $account = new Account();
        $account->usr = $user;
        $account->name = 'E2E Mailbox';
        $account->email = 'E2E Mailbox';
        $account->username = self::SEED_ACCOUNT_USERNAME;
        $account->imapHost = 'imap.e2e.test';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->authType = 'password';
        $account->isActive = true;

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /** Drop this command's own thread and its own two connections, and nothing else. */
    private function removePreviousSeed(Account $account): void
    {
        foreach ([self::FILE_STORE_NAME, self::PHOTO_LIBRARY_NAME] as $name) {
            foreach ($this->integrationRepository->findBy(['usr' => $account->usr, 'name' => $name]) as $integration) {
                $this->entityManager->remove($integration);
            }
        }

        $threads = $this->threadRepository->findBy([
            'account' => $account,
            'subject' => self::SUBJECT,
        ]);

        if (0 !== count($threads)) {
            $accountId = (int) $account->id;
            $threadIds = array_map(static fn (MessageThread $thread): int => (int) $thread->id, $threads);
            $messageIds = $this->messageRepository->findIdsForThreads($threadIds);

            foreach ($messageIds as $messageId) {
                $this->stateManager->recordDestroyed($accountId, JmapObjectType::Email, (string) $messageId);
            }

            foreach ($threads as $thread) {
                $this->stateManager->recordDestroyed($accountId, JmapObjectType::Thread, (string) $thread->id);
                $this->entityManager->remove($thread);
            }
        }

        $this->entityManager->flush();
    }

    /** The smallest valid PNG: a 1x1 transparent pixel. */
    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );
    }
}
