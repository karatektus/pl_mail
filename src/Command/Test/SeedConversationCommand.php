<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
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
 * One conversation of several messages, on the account the suite already uses.
 *
 * WHY THIS EXISTS RATHER THAN app:test:seed-demo
 *
 * Some things can only be tested on a thread with a message BELOW the one you
 * are looking at — a sticky header that covers the next message, a collapsed
 * turn, a quote that folds. The only fixture that had one was the demo mailbox,
 * and reaching for it was a mistake: app:test:seed-demo is a screenshot tool.
 * It DELETES every other account on the user, because "E2E Mailbox" in a readme
 * screenshot gives the game away, and it leaves a `you@example.com` behind.
 *
 * The damage that did was not local. The next spec's seed-mail put E2E Mailbox
 * back, so the user then had three accounts where the suite expects two, and
 * six unrelated specs failed on counts that were correct before this one ran —
 * failing in a full suite and passing alone, which is the shape that costs an
 * afternoon. SeedBulkMailCommand's --clear option carries a note about the same
 * class of bug; this is the second time it has been paid for.
 *
 * So: additive, one thread, its own subject, and it removes only the thread it
 * made. Nothing else on the account moves.
 */
#[AsCommand(
    name: 'app:test:seed-conversation',
    description: 'Seed one multi-message conversation on the E2E account, without touching anything else',
)]
final class SeedConversationCommand extends Command
{
    use TargetsTestUser;

    /** Distinct enough that no other fixture's subject can match it. */
    public const string SUBJECT = 'E2E Conversation';

    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    /**
     * Four turns, alternating sender, oldest first.
     *
     * Four because the interesting case is a message with another one after it
     * AND before it — a header that covers what follows behaves differently
     * from one that covers what precedes.
     *
     * @var list<array{from: string, name: string, body: string}>
     */
    private const array TURNS = [
        ['from' => 'ada@e2e.test',  'name' => 'Ada Lovelace', 'body' => 'Does Thursday still work for the handover?'],
        ['from' => 'mailbox@e2e.test', 'name' => 'E2E Mailbox', 'body' => 'Thursday is fine. Morning or afternoon?'],
        ['from' => 'ada@e2e.test',  'name' => 'Ada Lovelace', 'body' => 'Morning, if the room is free by then.'],
        ['from' => 'mailbox@e2e.test', 'name' => 'E2E Mailbox', 'body' => 'Booked. See you at ten.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelResolver           $labelResolver,
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
            $io->error('app:test:seed-conversation must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null === $account) {
            $io->error('The E2E account is missing — run app:test:seed-mail first.');

            return Command::FAILURE;
        }

        // Only ours, by subject. Re-running replaces this conversation and
        // leaves every other thread on the account exactly where it was.
        foreach ($this->threadRepository->findBy(['account' => $account, 'subject' => self::SUBJECT]) as $existing) {
            $this->entityManager->remove($existing);
        }

        $this->entityManager->flush();

        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $now   = new DateTimeImmutable();

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = self::SUBJECT;
        $thread->normalizedSubject = mb_strtolower(self::SUBJECT);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->messageCount      = count(self::TURNS);
        $thread->unreadCount       = 0;
        $thread->category          = MessageCategory::Primary;
        $thread->attachmentCount   = 0;
        $thread->lastMessageAt     = $now;
        $thread->addLabel($inbox);

        $this->entityManager->persist($thread);

        foreach (self::TURNS as $index => $turn) {
            // Oldest first, an hour apart, so the order on screen is the order
            // written here and a header's "next message" is the next turn.
            $receivedAt = $now->modify(sprintf('-%d hours', count(self::TURNS) - $index));

            $message                  = new Message();
            $message->account         = $account;
            $message->thread          = $thread;
            $message->subject         = 0 === $index ? self::SUBJECT : 'Re: ' . self::SUBJECT;
            $message->fromName        = $turn['name'];
            $message->fromAddress     = $turn['from'];
            $message->toAddresses     = [['name' => '', 'address' => (string) $user->email]];
            $message->bodyText        = $turn['body'];
            $message->messageId       = sprintf('e2e-conversation-%d@e2e.test', $index);
            $message->headers         = [
                'Message-ID'   => sprintf('<e2e-conversation-%d@e2e.test>', $index),
                'From'         => sprintf('%s <%s>', $turn['name'], $turn['from']),
                'To'           => (string) $user->email,
                'Subject'      => (string) $message->subject,
                'Date'         => $receivedAt->format(DATE_RFC2822),
                'Content-Type' => 'text/plain; charset=utf-8',
            ];
            $message->receivedAt      = $receivedAt;
            $message->sentAt          = $receivedAt;
            $message->hasAttachments  = false;
            $message->flags           = [];
            $message->syncedAt        = $now;
            // Read, all of them: an unread conversation moves the badge counts
            // that six other specs assert on, and this fixture exists to be
            // additive.
            $message->seenAt          = $now;
            $message->addLabel($inbox);

            $this->entityManager->persist($message);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Seeded "%s" with %d messages.', self::SUBJECT, count(self::TURNS)));

        return Command::SUCCESS;
    }
}
