<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Jmap\State\JmapObjectType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\StateManager;
use App\Repository\Calendar\CalendarEventRepository;
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
 * A mailbox worth photographing.
 *
 * The README's tour is captured by tests/e2e/screenshots.spec.ts, which used to
 * need a demo installation nobody else had: the E2E fixtures seed "E2E Trash
 * Me", which is the right name for an assertion and the wrong one for a picture
 * in a readme. So the screenshots could only be retaken by the one person
 * holding that mailbox, and only they could tell whether they still matched the
 * app.
 *
 * This seeds mail that reads like mail — senders, subjects, labels and dates a
 * screenshot can show without a caption apologising for it — and nothing in it
 * is anyone's real correspondence, which is what the caption under those images
 * promises.
 *
 * Not for prod, and it says so. Everything it writes belongs to one account it
 * owns outright and wipes on every run, so it cannot touch mail that was synced.
 */
#[AsCommand(
    name: 'app:test:seed-demo',
    description: 'Seed a believable demo mailbox for the README screenshots',
)]
final class SeedDemoMailboxCommand extends Command
{
    use TargetsTestUser;

    private const string ACCOUNT_USERNAME = 'demo@plmail.test';

    /** Custom labels the demo mailbox files things under. */
    private const array LABELS = ['Receipts', 'Travel', 'Family'];

    /**
     * The mailbox itself: subject, sender name, sender address, label, unread,
     * hours ago, body.
     *
     * Ordinary, dull and plausible on purpose. A screenshot of a mailbox full
     * of exciting subjects reads as a mock-up; this reads as Tuesday.
     *
     * @var list<array{string, string, string, string|null, bool, int, string}>
     */
    private const array THREADS = [
        ['Bookshelf dimensions', 'Priya Raman', 'priya.raman@example.com', null, true, 1,
            "The alcove is 182cm from skirting to the underside of the shelf, and 96cm wide.\n\n"
            . 'The 175cm unit would leave a finger of daylight at the top, which I think looks '
            . "deliberate. Photos when the light is better.\n\nP",
        ],
        ['Your order has shipped', 'Kleinschmidt Werkzeug', 'versand@kleinschmidt.example', 'Receipts', true, 3,
            "Two of three items are on their way and should arrive Thursday.\n\n"
            . 'The chisel set is back-ordered until the 19th; nothing to do at your end.',
        ],
        ['Re: Sunday', 'Mum', 'ruth@example.org', 'Family', false, 6,
            "Sunday works. Bring nothing, there is far too much food already.\n\n"
            . 'Your father has opinions about the hedge again.',
        ],
        ['Booking confirmed — Hotel Aare, 12–14 Sep', 'Hotel Aare', 'reservations@hotelaare.example', 'Travel', true, 9,
            "Room 214, two nights, breakfast included. Arrival any time after 15:00.\n\n"
            . 'Reply to this message if the train puts you in after 22:00 and we will leave the key.',
        ],
        ['Invoice 2026-0841', 'Steuerbüro Lang', 'buchhaltung@lang-steuer.example', 'Receipts', false, 26,
            "Attached is the invoice for the quarter, due in 14 days.\n\n"
            . 'Nothing needs signing.',
        ],
        ['Rail replacement on the 12th', 'SBB Kundeninfo', 'info@sbb.example', 'Travel', false, 30,
            "The 07:41 does not run on the 12th. The 07:19 is replaced by a bus as far as Olten "
            . "and adds 25 minutes.\n\nNo action needed if you are travelling later.",
        ],
        ['Photos from the weekend', 'Jonas Weber', 'jonas.weber@example.com', 'Family', false, 52,
            "Forty of them, and about six are any good. The one of the dog in the river is the "
            . "only one worth printing.\n\nJ",
        ],
        ['Reading group — October pick', 'Sofia Marchetti', 'sofia@example.net', null, false, 74,
            "We landed on the short one, thankfully. Meeting moves to the 8th because the room "
            . "is taken on the 1st.\n\nSame time, same place, bring the good chairs back.",
        ],
        ['Meter reading due', 'Stadtwerke', 'ablesung@stadtwerke.example', null, false, 99,
            "Please submit your reading before the 20th. It takes a minute online and saves an "
            . 'estimate you will only have to correct later.',
        ],
        ['Re: quote for the trim', 'Marek Nowak', 'marek@nowak-schreinerei.example', null, false, 120,
            "Oak trim to match is fine, and I can do it in the same run as the shelves.\n\n"
            . 'Adds about a week. Say the word and I will order the timber.',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly CalendarEventRepository $calendarEventRepository,
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
            $io->error('app:test:seed-demo must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('User "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::ACCOUNT_USERNAME,
        ]);

        if (null === $account) {
            $account = new Account();
            $account->usr = $user;
            $account->name = 'Demo';
            $account->email = 'you@example.com';
            $account->username = self::ACCOUNT_USERNAME;
            $account->imapHost = 'imap.example.com';
            $account->imapPort = 993;
            $account->imapEncryption = 'ssl';
            $account->authType = 'password';
            $account->isActive = true;

            $this->entityManager->persist($account);
            $this->entityManager->flush();
        }

        // The calendar goes too. The screenshot suite creates its events
        // through the dialog on every run, so anything already there is a
        // leftover from the last one and would show up twice.
        foreach ($this->calendarEventRepository->findBy(['usr' => $user]) as $event) {
            $this->entityManager->remove($event);
        }

        // The demo mailbox ends up the only one, because the sidebar lists
        // every account and "E2E Mailbox" in a readme screenshot gives the
        // game away. Safe here and nowhere else: this command already refuses
        // to run in prod, and in dev or test the other accounts are fixtures.
        foreach ($this->accountRepository->findBy(['usr' => $user]) as $other) {
            if ($other->username !== self::ACCOUNT_USERNAME) {
                $this->entityManager->remove($other);
            }
        }

        // Wiped and rewritten each run, so the same command twice gives the
        // same mailbox — a screenshot suite that accumulates threads shows a
        // different inbox every time it is run.
        foreach ($this->threadRepository->findBy(['account' => $account]) as $thread) {
            $this->entityManager->remove($thread);
        }

        $this->entityManager->flush();

        $inbox  = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $labels = [];

        foreach (self::LABELS as $name) {
            $labels[$name] = $this->labelResolver->customChain([$name], $account);
        }

        $this->entityManager->flush();

        $now      = new DateTimeImmutable();
        $messages = [];

        foreach (self::THREADS as [$subject, $fromName, $fromAddress, $label, $unread, $hoursAgo, $body]) {
            $receivedAt = $now->modify(sprintf('-%d hours', $hoursAgo));

            $message = new Message();
            $message->account = $account;
            $message->subject = $subject;
            $message->fromName = $fromName;
            $message->fromAddress = $fromAddress;
            $message->toAddresses = [['name' => 'You', 'address' => 'you@example.com']];
            $message->bodyText = $body;
            $message->receivedAt = $receivedAt;
            $message->sentAt = $receivedAt;
            $message->hasAttachments = false;
            $message->flags = [];
            $message->syncedAt = $now;
            $message->addLabel($inbox);

            if (false === $unread) {
                $message->seenAt = $receivedAt;
            }

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
            $thread->addLabel($inbox);

            if (null !== $label) {
                $message->addLabel($labels[$label]);
                $thread->addLabel($labels[$label]);
            }

            $this->entityManager->persist($message);
            $this->entityManager->persist($thread);

            $message->thread = $thread;
            $messages[] = $message;
        }

        $this->entityManager->flush();

        // Seeded mail is real mail to a JMAP client — see the same note in
        // SeedTestEmailCommand. Recorded after the flush, because the log rows
        // need the ids it mints.
        $accountId = (int) $account->id;

        foreach ($messages as $message) {
            $this->stateManager->recordCreated($accountId, JmapObjectType::Email, (string) $message->id);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded %d demo threads and %d labels for %s.',
            count($messages),
            count($labels),
            $userEmail,
        ));

        return Command::SUCCESS;
    }
}
