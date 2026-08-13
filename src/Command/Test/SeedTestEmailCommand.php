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
use App\Service\Mail\AccountCreator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds a deterministic inbox for the mail-UI end-to-end tests.
 *
 * Creates (find-or-create) a dedicated password account owned by the E2E
 * user, wipes its threads, then inserts one unread Inbox thread per action
 * the suite exercises. Each thread has a distinct subject so the specs can
 * act on independent rows.
 *
 * Messages are seeded Gmail-style (no mailbox, no imapUid), so archive/trash
 * are pure label mutations and need no IMAP folder. Thread/message assembly
 * mirrors MessageThreader: Inbox system label on both, tab = Primary,
 * ThreadingMethod::SubjectFallback.
 *
 * Idempotent and destructive within its own account only — safe to re-run.
 * Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:seed-mail',
    description: 'Seed a known inbox (star/archive/trash/read threads) for the mail-UI E2E tests',
)]
final class SeedTestEmailCommand extends Command
{
    use TargetsTestUser;

    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    /**
     * subject => whether the thread starts unread.
     *
     * @var array<string, bool>
     */
    private const array SEED_THREADS = [
        'E2E Star Me'    => true,
        'E2E Archive Me' => true,
        'E2E Trash Me'   => true,
        'E2E Read Me'    => true,
    ];

    public function __construct(
        private readonly EntityManagerInterface   $entityManager,
        private readonly UserRepository           $userRepository,
        private readonly AccountRepository        $accountRepository,
        private readonly MessageRepository        $messageRepository,
        private readonly MessageThreadRepository  $threadRepository,
        private readonly LabelResolver            $labelResolver,
        private readonly StateManager             $stateManager,
        private readonly AccountCreator           $accountCreator,
        #[Autowire('%kernel.environment%')]
        private readonly string                   $environment,
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
            $io->error('app:e2e:seed-mail must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:e2e:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null === $account) {
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
        }

        // isPrimary is a stored choice rather than "whichever account sorts
        // first", so a hand-built fixture has to state it — otherwise the
        // seeded mailbox has no primary, no PRIMARY badge in settings, and does
        // not resemble an account any real path would have produced.
        $this->accountCreator->ensurePrimary(
            $this->accountRepository->findForUserOrdered($user),
        );
        $this->entityManager->flush();

        $this->wipeThreads($account);

        $inboxLabel = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);

        $now      = new DateTimeImmutable();
        $offset   = 0;
        $seeded   = 0;
        $messages = [];

        foreach (self::SEED_THREADS as $subject => $unread) {
            $receivedAt = $now->modify(sprintf('-%d minutes', $offset));
            $offset++;

            $message = new Message();
            $message->account = $account;
            $message->subject = $subject;
            $message->fromName = 'E2E Sender';
            $message->fromAddress = 'sender@e2e.test';
            $message->toAddresses = [['name' => 'E2E Tester', 'address' => (string) $user->email]];
            $message->bodyText = sprintf('Seeded body for "%s".', $subject);
            // Real mail always carries headers, and two things read them: the
            // details popover renders them, and the categoriser decides from
            // them. A fixture with none exercises neither.
            $message->headers = [
                'Message-ID'   => sprintf('<%s@e2e.test>', md5($subject)),
                'From'         => 'E2E Sender <sender@e2e.test>',
                'To'           => (string) $user->email,
                'Subject'      => $subject,
                'Date'         => $receivedAt->format(DATE_RFC2822),
                'Content-Type' => 'text/plain; charset=utf-8',
            ];
            $message->receivedAt = $receivedAt;
            $message->sentAt = $receivedAt;
            $message->hasAttachments = false;
            $message->flags = [];
            $message->syncedAt = $now;
            $message->addLabel($inboxLabel);

            if (false === $unread) {
                $message->seenAt = $now;
            }

            $this->entityManager->persist($message);

            $thread = new MessageThread();
            $thread->account = $account;
            $thread->subject = $subject;
            $thread->normalizedSubject = mb_strtolower(trim($subject));
            $thread->threadingMethod = ThreadingMethod::SubjectFallback;
            $thread->messageCount = 1;
            $thread->unreadCount = true === $unread ? 1 : 0;
            $thread->category = MessageCategory::Primary;
            $thread->attachmentCount = 0;
            $thread->lastMessageAt = $receivedAt;
            $thread->addLabel($inboxLabel);

            $this->entityManager->persist($thread);

            $message->thread = $thread;

            $messages[] = $message;
            $seeded++;
        }

        $this->entityManager->flush();

        // Seeded mail is real mail as far as a JMAP client is concerned. Without
        // this the change log never moves, Email/changes reports nothing after a
        // reseed, and a delta sync that is working perfectly looks broken — which
        // is exactly how this cost an afternoon once already.
        //
        // After the flush, like PostIngestPipeline: record() only persists and
        // needs the ids the flush above just minted, so the log rows go out on
        // the second flush.
        $accountId = (int) $account->id;
        $threadIds = [];

        foreach ($messages as $message) {
            $this->stateManager->recordCreated(
                $accountId,
                JmapObjectType::Email,
                (string) $message->id,
            );

            $thread = $message->thread;

            if (null !== $thread) {
                $threadIds[] = (int) $thread->id;
            }
        }

        $this->stateManager->recordThreadsTouched($accountId, $threadIds);

        $this->entityManager->flush();

        $io->success(sprintf('Seeded %d inbox thread(s) for "%s".', $seeded, self::SEED_ACCOUNT_USERNAME));

        return Command::SUCCESS;
    }

    private function wipeThreads(Account $account): void
    {
        $threads = $this->threadRepository->findBy(['account' => $account]);

        if (0 === count($threads)) {
            return;
        }

        $accountId = (int) $account->id;
        $threadIds = array_map(
            static fn (MessageThread $thread): int => (int) $thread->id,
            $threads,
        );

        // A reseed is the one place in this app that really deletes mail, so
        // the ids have to be read while the rows still exist — a client told
        // nothing goes on holding ids for messages that are gone, and can only
        // find out by asking for each of them and being handed notFound.
        //
        // As scalars, not by walking $thread->messages: hydrating the
        // messages only to delete them leaves the unit of work in a state where
        // the reseed's own flush, moments later, insists the threads it has
        // just persisted were never persisted ("A new entity was found through
        // the relationship Message#thread"). Nothing here wants the objects.
        $messageIds = $this->messageRepository->findIdsForThreads($threadIds);

        foreach ($messageIds as $messageId) {
            $this->stateManager->recordDestroyed($accountId, JmapObjectType::Email, (string) $messageId);
        }

        foreach ($threads as $thread) {
            $this->stateManager->recordDestroyed($accountId, JmapObjectType::Thread, (string) $thread->id);

            // Thread remove cascades to its messages (orphanRemoval); the
            // thread_label / message_label join rows drop via ON DELETE CASCADE.
            $this->entityManager->remove($thread);
        }

        $this->entityManager->flush();
    }
}
