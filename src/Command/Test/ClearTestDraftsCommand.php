<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Repository\User\UserRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Discards every draft the E2E user owns, on every account.
 *
 * The compose specs write their drafts through the UI, which files them on
 * the user's *default* account — not necessarily the one `app:test:seed-mail`
 * owns and wipes. Anything the account spec left behind can therefore be the
 * default, and its drafts then survive every reseed: a second run of
 * drafts.spec / compose.spec finds two "Listed Draft" rows, a third finds
 * three, and the strict-mode locators fail.
 *
 * Drafts are selected the way the Drafts list selects them (Drafts-role
 * label, so Gmail-style drafts with no mailbox are covered too), and removed
 * the way ComposeController::discard removes one: stored attachments off
 * disk, message out of its thread, thread label state resynced, and a thread
 * that just lost its last message dropped as well.
 *
 * Idempotent. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:clear-drafts',
    description: 'Delete every draft owned by the E2E user, on every account',
)]
final class ClearTestDraftsCommand extends Command
{
    use TargetsTestUser;

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly AttachmentStorageHelper $attachmentStorage,
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
            $io->error('app:test:clear-drafts must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $drafts = $this->messageRepository->findDraftsForUser($user);

        /** @var array<int, MessageThread> $touchedThreads */
        $touchedThreads = [];

        foreach ($drafts as $draft) {
            // Parts are removed explicitly: the association carries no
            // cascade, so leaving them to the database's ON DELETE CASCADE
            // strands managed MessagePart entities pointing at a message that
            // is already gone, and the flush fails on them.
            foreach ($draft->messageParts as $part) {
                $this->attachmentStorage->delete($part->storagePath);
                $draft->removeMessagePart($part);
                $this->entityManager->remove($part);
            }

            $thread = $draft->thread;

            if (null !== $thread) {
                // Off the collection as well as out of the database: the
                // synchronizer derives a thread's labels from the messages it
                // still holds.
                $thread->removeMessage($draft);
                $touchedThreads[(int) $thread->id] = $thread;
            }

            $this->entityManager->remove($draft);
        }

        $this->entityManager->flush();

        foreach ($touchedThreads as $thread) {
            $remaining = $thread->messages->count();

            if (0 === $remaining) {
                $this->entityManager->remove($thread);

                continue;
            }

            $thread->messageCount = $remaining;
            $this->threadLabelSynchronizer->sync($thread);
        }

        $this->entityManager->flush();

        // Discarding a draft in the app deliberately leaves the emptied thread
        // standing ("harmless, and the sync layer reuses it"), but it keeps its
        // Drafts label, so it keeps its row in the Drafts list — residue that
        // outlives the messages and is exactly what makes the subject filters
        // ambiguous on a rerun. A thread with no messages left is dead weight
        // in a test database, so those go too.
        $emptyThreads = $this->threadRepository->findEmptyForUser($user);

        foreach ($emptyThreads as $emptyThread) {
            $this->entityManager->remove($emptyThread);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Cleared %d draft(s) for "%s".', count($drafts), $userEmail));

        return Command::SUCCESS;
    }
}
