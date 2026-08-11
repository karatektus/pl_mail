<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
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
 * Seeds the two recipient shapes the message header has to tell apart.
 *
 *   "E2E Undisclosed"  — no recipients and no To: header. A bcc delivery or a
 *                        list that hides its members. The header must say so
 *                        out loud rather than leave the line out, because an
 *                        absent line reads as a bug.
 *   "E2E Header Only"  — no recipient columns, but a To: header in the stored
 *                        headers bag. This is the shape every message synced
 *                        over IMAP before MessageSyncer::addressesOf() was
 *                        fixed is in, and the header falls back to the bag
 *                        rather than claiming the message went to nobody.
 *
 * Separate from app:test:seed-mail for the same reason seed-attachment is: that
 * seeder's thread count is asserted on elsewhere. Seeds into the SAME account,
 * and removes its own two threads first, so re-running is a no-op.
 *
 * Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:seed-header-cases',
    description: 'Seed messages with missing / header-only recipients for the message-header E2E tests',
)]
final class SeedHeaderCasesCommand extends Command
{
    use TargetsTestUser;

    /** Shared with app:test:seed-mail — see the class docblock. */
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    private const string UNDISCLOSED_SUBJECT = 'E2E Undisclosed';
    private const string HEADER_ONLY_SUBJECT = 'E2E Header Only';

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelResolver           $labelResolver,
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
            $io->error('app:test:seed-header-cases must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->account($user);

        foreach ([self::UNDISCLOSED_SUBJECT, self::HEADER_ONLY_SUBJECT] as $subject) {
            $this->removePreviousSeed($account, $subject);
        }

        // No To: anywhere. Delivered by bcc, as far as this row knows.
        $this->seed(
            $account,
            self::UNDISCLOSED_SUBJECT,
            'Seeded message with no recipients at all.',
            ['Return-Path' => '<sender@e2e.test>'],
        );

        // Columns empty, header intact: an IMAP row from before the fix. The
        // underscore spelling is webklex's, and is exactly what the header
        // lookup used to miss.
        $this->seed(
            $account,
            self::HEADER_ONLY_SUBJECT,
            'Seeded message whose recipients live only in the header bag.',
            [
                'to'      => 'Header Only <header-only@e2e.test>, Second Reader <second@e2e.test>',
                'cc'      => 'Copied In <copied@e2e.test>',
                'reply_to' => 'Reply Here <reply@e2e.test>',
            ],
        );

        $this->entityManager->flush();

        $io->success('Seeded 2 threads: undisclosed recipients, and header-only recipients.');

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $headers
     */
    private function seed(Account $account, string $subject, string $body, array $headers): void
    {
        $inboxLabel = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $now        = new DateTimeImmutable();

        $message = new Message();
        $message->account     = $account;
        $message->subject     = $subject;
        $message->fromName    = 'E2E Sender';
        $message->fromAddress = 'sender@e2e.test';
        // The point of the fixture: empty, not null-and-therefore-unknown.
        $message->toAddresses = [];
        $message->ccAddresses = [];
        $message->headers     = $headers;
        $message->bodyText    = $body;
        $message->receivedAt  = $now;
        $message->sentAt      = $now;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->syncedAt       = $now;
        $message->addLabel($inboxLabel);

        $this->entityManager->persist($message);

        $thread = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 1;
        $thread->category          = MessageCategory::Primary;
        $thread->lastMessageAt     = $now;
        $thread->addLabel($inboxLabel);

        $this->entityManager->persist($thread);
        $message->thread = $thread;

        $this->entityManager->flush();

        $accountId = (int) $account->id;

        $this->stateManager->recordCreated($accountId, JmapObjectType::Email, (string) $message->id);
        $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
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
        $account->usr            = $user;
        $account->name           = 'E2E Mailbox';
        $account->email          = 'E2E Mailbox';
        $account->username       = self::SEED_ACCOUNT_USERNAME;
        $account->imapHost       = 'imap.e2e.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Drop only this command's own threads, by subject — the account is shared
     * with seed-mail, whose four threads the mail specs are built on.
     */
    private function removePreviousSeed(Account $account, string $subject): void
    {
        $threads = $this->threadRepository->findBy([
            'account' => $account,
            'subject' => $subject,
        ]);

        if (0 === count($threads)) {
            return;
        }

        $accountId = (int) $account->id;
        $threadIds = array_map(
            static fn (MessageThread $thread): int => (int) $thread->id,
            $threads,
        );

        // Ids read before the delete, as scalars: a JMAP client that is not
        // told keeps asking for rows that no longer exist, and hydrating the
        // messages to find them out breaks the reseed's own flush.
        $messageIds = $this->messageRepository->findIdsForThreads($threadIds);

        foreach ($messageIds as $messageId) {
            $this->stateManager->recordDestroyed($accountId, JmapObjectType::Email, (string) $messageId);
        }

        foreach ($threads as $thread) {
            $this->stateManager->recordDestroyed($accountId, JmapObjectType::Thread, (string) $thread->id);

            $this->entityManager->remove($thread);
        }

        $this->entityManager->flush();
    }
}
