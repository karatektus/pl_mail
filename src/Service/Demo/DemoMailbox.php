<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The mailbox a demo starts from: senders, subjects, labels and dates that read
 * like somebody's Tuesday.
 *
 * Extracted from SeedDemoMailboxCommand when demo mode arrived, because the two
 * callers must not drift. The command builds the mailbox the README is
 * photographed against; the provisioner builds the one a visitor to the hosted
 * demo lands in. If those two were separate lists, the screenshots would stop
 * being pictures of the demo — which is the one thing they are for.
 *
 * Ordinary and dull on purpose. A screenshot of a mailbox full of exciting
 * subjects reads as a mock-up; this reads as Tuesday. Nothing in it is anyone's
 * real correspondence, and every domain is either example.* or a name that
 * resolves nowhere, which is what the caption under those images promises.
 */
final readonly class DemoMailbox
{
    /** The account every demo mailbox hangs off. */
    public const string ACCOUNT_USERNAME = 'demo@plmail.test';

    /** The address the demo user appears to own. */
    public const string ACCOUNT_EMAIL = 'you@example.com';

    /** Custom labels the demo mailbox files things under. */
    public const array LABELS = ['Receipts', 'Travel', 'Family'];

    /**
     * Subject, sender name, sender address, label, unread, hours ago, body.
     *
     * @var list<array{string, string, string, string|null, bool, int, string}>
     */
    public const array THREADS = [
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
        private EntityManagerInterface  $entityManager,
        private MessageThreadRepository $threadRepository,
        private ContactRepository       $contactRepository,
        private LabelResolver           $labelResolver,
        private StateManager            $stateManager,
    ) {
    }

    /**
     * The demo account for this user, created if it is not there yet.
     *
     * The IMAP and SMTP hosts are documentation domains that resolve nowhere.
     * That is belt to demo mode's braces: DemoMailSender means nothing tries to
     * reach them, and if something ever did it would fail to connect rather
     * than reach a stranger's server.
     */
    public function account(User $user, iterable $existing): Account
    {
        foreach ($existing as $candidate) {
            if (self::ACCOUNT_USERNAME === $candidate->username) {
                return $candidate;
            }
        }

        $account = new Account();
        $account->usr            = $user;
        $account->name           = 'Demo';
        $account->email          = self::ACCOUNT_EMAIL;
        $account->username       = self::ACCOUNT_USERNAME;
        $account->imapHost       = 'imap.example.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.example.com';
        $account->smtpPort       = 465;
        $account->smtpEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Wipes the account's threads and rewrites the demo mailbox onto it.
     *
     * Wiped and rewritten rather than appended, so the same call twice gives
     * the same mailbox: a screenshot suite that accumulates threads shows a
     * different inbox every time it is run, and a demo that did would drift
     * further from its own screenshots with every visitor.
     *
     * @return list<Message>
     */
    public function seed(User $user, Account $account): array
    {
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
            $message->account        = $account;
            $message->subject        = $subject;
            $message->fromName       = $fromName;
            $message->fromAddress    = $fromAddress;
            $message->toAddresses    = [['name' => 'You', 'address' => self::ACCOUNT_EMAIL]];
            $message->bodyText       = $body;
            $message->receivedAt     = $receivedAt;
            $message->sentAt         = $receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->syncedAt       = $now;
            $message->addLabel($inbox);

            if (false === $unread) {
                $message->seenAt = $receivedAt;
            }

            $thread = new MessageThread();
            $thread->account           = $account;
            $thread->subject           = $subject;
            $thread->normalizedSubject = mb_strtolower(trim($subject));
            $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
            $thread->messageCount      = 1;
            $thread->unreadCount       = true === $unread ? 1 : 0;
            $thread->category          = MessageCategory::Primary;
            $thread->attachmentCount   = 0;
            $thread->lastMessageAt     = $receivedAt;
            $thread->addLabel($inbox);

            if (null !== $label) {
                $message->addLabel($labels[$label]);
                $thread->addLabel($labels[$label]);
            }

            $this->entityManager->persist($message);
            $this->entityManager->persist($thread);

            $message->thread = $thread;
            $messages[]      = $message;
        }

        $this->entityManager->flush();

        // Seeded mail is real mail to a JMAP client — see the same note in
        // SeedTestEmailCommand. Recorded after the flush, because the log rows
        // need the ids it mints.
        $accountId = (int) $account->id;

        foreach ($messages as $message) {
            $this->stateManager->recordCreated($accountId, JmapObjectType::Email, (string) $message->id);
        }

        // The senders as contacts, which is what makes compose worth showing:
        // recipients autocomplete from the address book, and that is normally
        // filled by harvesting synced mail rather than by seeding it.
        $contacts = [];

        foreach (self::THREADS as [, $fromName, $fromAddress]) {
            $contacts[] = ['email' => $fromAddress, 'name' => $fromName, 'correspondent' => true];
        }

        $this->contactRepository->upsertBatch($user, $contacts);

        $this->entityManager->flush();

        return $messages;
    }
}
