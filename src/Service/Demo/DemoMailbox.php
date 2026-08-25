<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\SamplePdf;
use App\Entity\Mail\Account;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\LabelResolver;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Mail\PostIngestPipeline;
use App\Service\User\UserTimezoneResolver;
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
     * The mailbox itself.
     *
     * Keyed rather than positional: it grew a body in HTML and a couple of
     * entries whose text is written to be READ by something — the invoice
     * states a total and a due date, the sign-in mail carries a code — and
     * seven-element tuples stopped being legible at about the fifth field.
     *
     * Those two are deliberate. Insights are extracted from what a mail says,
     * not from a flag, so a demo mailbox of plausible prose produces an empty
     * Insights pane and looks like a feature that does not work. Writing mail
     * that genuinely says "Gesamtbetrag 285,60 EUR" and "fällig am 12.09.2026"
     * means the extractor finds it the same way it would in a real mailbox —
     * which is the only version of this worth showing.
     *
     * Senders stay fictional throughout, which is why there is no parcel here:
     * ParcelExtractor keys on real carrier domains, and putting noreply@dhl.de
     * in a demo would be impersonating DHL to make a card appear.
     *
     * @var list<array{
     *     subject: string, fromName: string, fromAddress: string,
     *     label: string|null, unread: bool, hoursAgo: int,
     *     body: string, html?: string, headers?: array<string, string>,
     *     attachment?: string,
     *     correspondent?: bool
     * }>
     */
    public const array THREADS = [
        [
            'subject' => 'Bookshelf dimensions',
            'fromName' => 'Priya Raman', 'fromAddress' => 'priya.raman@example.com',
            'label' => null, 'unread' => true, 'hoursAgo' => 1,
            'body' => "The alcove is 182cm from skirting to the underside of the shelf, and 96cm wide.\n\n"
                . 'The 175cm unit would leave a finger of daylight at the top, which I think looks '
                . "deliberate. Photos when the light is better.\n\nP",
        ],
        [
            'subject' => 'Your order has shipped',
            'fromName' => 'Kleinschmidt Werkzeug', 'fromAddress' => 'versand@kleinschmidt.example',
            'label' => 'Receipts', 'unread' => true, 'hoursAgo' => 3,
            'body' => "Two of three items are on their way and should arrive Thursday.\n\n"
                . 'The chisel set is back-ordered until the 19th; nothing to do at your end.',
        ],
        [
            'subject' => 'Re: Sunday',
            'fromName' => 'Mum', 'fromAddress' => 'ruth@example.org',
            'label' => 'Family', 'unread' => false, 'hoursAgo' => 6,
            'body' => "Sunday works. Bring nothing, there is far too much food already.\n\n"
                . 'Your father has opinions about the hedge again.',
        ],
        [
            'subject' => 'Booking confirmed — Hotel Aare, 12–14 Sep',
            'fromName' => 'Hotel Aare', 'fromAddress' => 'reservations@hotelaare.example',
            'label' => 'Travel', 'unread' => true, 'hoursAgo' => 9,
            'body' => "Room 214, two nights, breakfast included. Arrival any time after 15:00.\n\n"
                . 'Reply to this message if the train puts you in after 22:00 and we will leave the key.',
        ],
        [
            'subject' => 'Rechnung 2026-0841',
            'fromName' => 'Steuerbüro Lang', 'fromAddress' => 'buchhaltung@lang-steuer.example',
            'label' => 'Receipts', 'unread' => false, 'hoursAgo' => 26,
            // The mail says "Anbei die Rechnung" and, until this, attached
            // nothing — a demo that describes an attachment it does not have.
            // It is also the document a visitor would most plausibly want to
            // open, and later to sign.
            'attachment' => 'Rechnung-2026-0841.pdf',
            'body' => "Anbei die Rechnung für das Quartal.\n\n"
                . "Buchhaltung Quartal III .......... 240,00 EUR\n"
                . "Umsatzsteuer 19% ................. 45,60 EUR\n"
                . "Gesamtbetrag ..................... 285,60 EUR\n\n"
                . "Zahlbar bis 12.09.2026. Es muss nichts unterschrieben werden.",
        ],
        [
            'subject' => 'Rail replacement on the 12th',
            'fromName' => 'SBB Kundeninfo', 'fromAddress' => 'info@sbb.example',
            'label' => 'Travel', 'unread' => false, 'hoursAgo' => 30,
            'body' => "The 07:41 does not run on the 12th. The 07:19 is replaced by a bus as far as Olten "
                . "and adds 25 minutes.\n\nNo action needed if you are travelling later.",
        ],
        [
            'subject' => 'Your sign-in code',
            'fromName' => 'Werkstatt Portal', 'fromAddress' => 'noreply@werkstatt-portal.example',
            'label' => null, 'unread' => true, 'hoursAgo' => 34,
            'headers' => ['Auto-Submitted' => 'auto-generated'],
            'correspondent' => false,
            // Contiguous digits: OtpExtractor matches \d{4,8} and a code
            // written "418 259" is two three-digit runs it will not read.
            'body' => "Your verification code is 418259.\n\n"
                . "It is good for ten minutes. If you did not ask to sign in, you can ignore this "
                . "and nothing happens.",
        ],
        [
            'subject' => 'Six things worth reading this month',
            'fromName' => 'The Long Field', 'fromAddress' => 'post@longfield.example',
            'label' => null, 'unread' => false, 'hoursAgo' => 41,
            // MessageCategorizer decides from headers, not from prose, so a
            // newsletter with none of them lands in Primary alongside a note
            // from your mother — and the category tabs, which are one of the
            // things worth showing, have nothing in them but Primary.
            'headers' => ['List-Unsubscribe' => '<mailto:unsubscribe@longfield.example>'],
            'correspondent' => false,
            'body' => "This month: a piece on hedgerows, two on bread, and one that is mostly "
                . "photographs of doors.\n\nUnsubscribe at the bottom, as ever.",
            'html' => '<div style="font-family:Georgia,serif;max-width:520px;color:#222">'
                . '<h1 style="font-size:22px;margin:0 0 2px">The Long Field</h1>'
                . '<p style="color:#777;margin:0 0 18px;font-style:italic">Six things worth reading this month</p>'
                . '<ol style="line-height:1.8;padding-left:18px">'
                . '<li>The hedge that outlived the farm</li>'
                . '<li>Bread, part one: the flour</li>'
                . '<li>Bread, part two: the wait</li>'
                . '<li>What the doors of Lisbon are painted with</li>'
                . '<li>An argument for the short walk</li>'
                . '<li>Letters, and one correction</li></ol>'
                . '<hr style="border:none;border-top:1px solid #ddd;margin:18px 0">'
                . '<p style="color:#999;font-size:12px">You are reading a demo. '
                . 'Nobody is subscribed to anything.</p></div>',
        ],
        [
            // HTML, and deliberately NOT a newsletter. The newsletter below
            // carries List-Unsubscribe, which puts it in Promotions — correct,
            // and it means the only formatted mail in the mailbox sat behind a
            // tab nobody clicks, so the reading pane looked as though it could
            // only render plain text. This one is from a person, stays in
            // Primary, and is the first thing a visitor opens.
            'subject' => 'Your booking at Hotel Aare — what to expect',
            'fromName' => 'Hotel Aare', 'fromAddress' => 'reservations@hotelaare.example',
            'label' => 'Travel', 'unread' => true, 'hoursAgo' => 5,
            'body' => "Room 214, two nights, breakfast included.\n\n"
                . "Arrival from 15:00. The side door takes the same key after 22:00.",
            'html' => '<div style="font-family:-apple-system,Segoe UI,sans-serif;max-width:540px;color:#1f2933">'
                . '<h2 style="margin:0 0 2px;font-size:19px">Hotel Aare</h2>'
                . '<p style="margin:0 0 18px;color:#66757f">Confirmation 4471-B · 12–14 September</p>'
                . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
                . '<tr><td style="padding:7px 0;color:#66757f;width:120px">Room</td><td style="padding:7px 0"><strong>214</strong>, lake side</td></tr>'
                . '<tr><td style="padding:7px 0;color:#66757f">Nights</td><td style="padding:7px 0">Two</td></tr>'
                . '<tr><td style="padding:7px 0;color:#66757f">Breakfast</td><td style="padding:7px 0">Included, 07:00–10:00</td></tr>'
                . '<tr><td style="padding:7px 0;color:#66757f">Arrival</td><td style="padding:7px 0">Any time after 15:00</td></tr>'
                . '</table>'
                . '<p style="margin:18px 0 0;padding:12px;background:#f4f1e8;border-radius:8px;font-size:13px">'
                . 'Coming in after 22:00? Reply here and we will leave the key in the side door.</p>'
                . '<p style="margin:18px 0 0;color:#9aa5b1;font-size:12px">This is demo data. '
                . 'No such booking exists.</p></div>',
        ],
        [
            'subject' => 'Photos from the weekend',
            'fromName' => 'Jonas Weber', 'fromAddress' => 'jonas.weber@example.com',
            'label' => 'Family', 'unread' => false, 'hoursAgo' => 52,
            'body' => "Forty of them, and about six are any good. The one of the dog in the river is the "
                . "only one worth printing.\n\nJ",
        ],
        [
            'subject' => 'Reading group — October pick',
            'fromName' => 'Sofia Marchetti', 'fromAddress' => 'sofia@example.net',
            'label' => null, 'unread' => false, 'hoursAgo' => 74,
            'body' => "We landed on the short one, thankfully. Meeting moves to the 8th because the room "
                . "is taken on the 1st.\n\nSame time, same place, bring the good chairs back.",
        ],
        [
            'subject' => 'Meter reading due',
            'fromName' => 'Stadtwerke', 'fromAddress' => 'ablesung@stadtwerke.example',
            'label' => null, 'unread' => false, 'hoursAgo' => 99,
            'headers' => ['Auto-Submitted' => 'auto-generated'],
            'correspondent' => false,
            'body' => "Please submit your reading before the 20th. It takes a minute online and saves an "
                . 'estimate you will only have to correct later.',
        ],
        [
            'subject' => 'Re: quote for the trim',
            'fromName' => 'Marek Nowak', 'fromAddress' => 'marek@nowak-schreinerei.example',
            'label' => null, 'unread' => false, 'hoursAgo' => 120,
            'body' => "Oak trim to match is fine, and I can do it in the same run as the shelves.\n\n"
                . 'Adds about a week. Say the word and I will order the timber.',
        ],
    ];

    /**
     * One conversation, four turns, oldest first.
     *
     * Every other thread here is a single message, which makes the demo's
     * threading invisible: the reading pane's whole argument is that replies
     * collapse into one conversation, and a mailbox of singletons never shows
     * it. This is the thread somebody opens to see what the product does with
     * a back-and-forth.
     *
     * `mine` marks the turns the demo user wrote, which is what gives the
     * thread two sides rather than four messages from one stranger.
     *
     * @var list<array{from: string, address: string, mine: bool, hoursAgo: int, body: string}>
     */
    public const array CONVERSATION = [
        [
            'from' => 'Priya Raman', 'address' => 'priya.raman@example.com', 'mine' => false,
            'hoursAgo' => 96,
            'body' => "Are you still after the oak for the alcove?\n\n"
                . "The yard has one board wide enough left and they will not hold it past Friday. "
                . "It is a little over budget but the grain is worth looking at.",
        ],
        [
            'from' => 'You', 'address' => self::ACCOUNT_EMAIL, 'mine' => true,
            'hoursAgo' => 92,
            'body' => "Yes — how far over?\n\n"
                . "If it is under fifty I will take it. Anything more and I would rather wait for "
                . "the next delivery.",
        ],
        [
            'from' => 'Priya Raman', 'address' => 'priya.raman@example.com', 'mine' => false,
            'hoursAgo' => 74,
            'body' => "Thirty-two, and they will cut it to length for nothing.\n\n"
                . "I said you would ring before Friday. Marek can collect it with the shelves if "
                . "you would rather not drive out.",
        ],
        [
            'from' => 'You', 'address' => self::ACCOUNT_EMAIL, 'mine' => true,
            'hoursAgo' => 70,
            'body' => "Take it. And yes, let Marek bring it — one delivery is plenty.\n\n"
                . "Thank you for chasing them.",
        ],
    ];

    /** The subject the conversation runs under. */
    public const string CONVERSATION_SUBJECT = 'Oak for the alcove';

    public function __construct(
        private EntityManagerInterface  $entityManager,
        private MessageThreadRepository $threadRepository,
        private ContactRepository       $contactRepository,
        private LabelResolver           $labelResolver,
        private AttachmentStorageHelper $attachmentStorage,
        private PostIngestPipeline      $pipeline,
        private CalendarEventRepository  $calendarEvents,
        private CalendarProvisioner      $calendarProvisioner,
        private CalendarEventWriter      $calendarWriter,
        private UserTimezoneResolver     $timezones,
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
     * A real PDF on a message that says it has one.
     *
     * Written to actual blob storage rather than faked, so downloading it, the
     * reader, and saving it to a connected file store all exercise the same
     * path a synced attachment does. See App\Domain\Helper\SamplePdf for why
     * the document is generated rather than committed.
     *
     * The message must be flushed before this: the storage path is built from
     * its id.
     */
    private function attach(Message $message, Account $account, string $filename, string $subject): void
    {
        $pdf = SamplePdf::document($subject, [
            'Buchhaltung Quartal III .......... 240,00 EUR',
            'Umsatzsteuer 19% ................. 45,60 EUR',
            'Gesamtbetrag ..................... 285,60 EUR',
            '',
            'Zahlbar bis 12.09.2026.',
            '',
            'This is demo data. No such invoice exists.',
        ]);

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'application/pdf';
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($pdf);
        $part->isInline    = false;
        $part->storagePath = $this->attachmentStorage->store(
            (int) $account->id,
            0,
            (int) $message->id,
            $filename,
            $pdf,
        );

        $message->addMessagePart($part);
        $message->hasAttachments = true;

        $this->entityManager->persist($part);
    }

    /**
     * Wipes the account's threads and rewrites the demo mailbox onto it.
     *
     * Wiped and rewritten rather than appended, so the same call twice gives
     * the same mailbox: a screenshot suite that accumulates threads shows a
     * different inbox every time it is run, and a demo that did would drift
     * further from its own screenshots with every visitor.
     *
     * The batch goes through PostIngestPipeline rather than being written as
     * finished rows, which is what it did before. Writing rows directly is
     * faster and produces a mailbox that LOOKS right and is inert: no
     * categories, no insights, and no evaluation by the filters the visitor is
     * invited to build. Those are the things plMail does when mail arrives, and
     * a demo whose mailbox has never had them done to it is showing the inbox
     * and none of the product.
     *
     * Two consequences worth stating. Threads are no longer built here — the
     * pipeline assigns them, and building both would leave two — and the JMAP
     * create is no longer recorded here either, because the pipeline records
     * it; doing both announced every message twice.
     *
     * @return list<Message>
     */
    public function seed(User $user, Account $account): array
    {
        foreach ($this->threadRepository->findBy(['account' => $account]) as $thread) {
            $this->entityManager->remove($thread);
        }

        // The address book goes with the threads, and this is not tidiness.
        // upsertBatch does not clear a correspondent flag it has already set,
        // and MessageCategorizer treats a correspondent as an override that
        // forces Primary — so contacts left from a previous seed dragged the
        // newsletter and the robots back into Primary and emptied the category
        // tabs. It worked the first time and not the second, which is the worst
        // way for it to fail.
        foreach ($this->contactRepository->findBy(['usr' => $user]) as $contact) {
            $this->entityManager->remove($contact);
        }

        $this->entityManager->flush();

        $inbox  = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $sent   = $this->labelResolver->systemLabel(LabelRole::Sent, $account);
        $labels = [];

        foreach (self::LABELS as $name) {
            $labels[$name] = $this->labelResolver->customChain([$name], $account);
        }

        $this->entityManager->flush();

        $now      = new DateTimeImmutable();
        $messages = [];
        $ingested = [];
        $pending  = [];

        foreach (self::THREADS as $entry) {
            $receivedAt = $now->modify(sprintf('-%d hours', $entry['hoursAgo']));

            $message = new Message();
            $message->account        = $account;
            // Bracket-less, like every other write path — MessageThreader
            // normalises references before matching against what is stored.
            $message->messageId      = MessageIdHelper::mint($entry['fromAddress']);
            $message->subject        = $entry['subject'];
            $message->fromName       = $entry['fromName'];
            $message->fromAddress    = $entry['fromAddress'];
            $message->toAddresses    = [['name' => 'You', 'address' => self::ACCOUNT_EMAIL]];
            $message->bodyText       = $entry['body'];
            $message->bodyHtml       = $entry['html'] ?? null;
            $message->headers        = $entry['headers'] ?? null;
            $message->receivedAt     = $receivedAt;
            $message->sentAt         = $receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->syncedAt       = $now;
            $message->addLabel($inbox);

            if (false === $entry['unread']) {
                $message->seenAt = $receivedAt;
            }

            if (null !== $entry['label']) {
                $message->addLabel($labels[$entry['label']]);
            }

            $this->entityManager->persist($message);

            $messages[] = $message;
            $ingested[] = new IngestedMessage($message, $account);

            if (true === isset($entry['attachment'])) {
                $pending[] = [$message, $entry['attachment'], $entry['subject']];
            }
        }

        // The pipeline's precondition: ids must exist before it threads, and
        // its rule engine matches in SQL against a generated column, so a
        // message that has not reached the database is invisible to it.
        $this->entityManager->flush();

        // Attachments AFTER that flush, not inside the loop above: the storage
        // path is built from the message id, and inside the loop there is not
        // one yet. Same precondition, one step later.
        foreach ($pending as [$message, $filename, $subject]) {
            $this->attach($message, $account, $filename, $subject);
        }

        $this->entityManager->flush();

        // One run for the whole batch rather than one per message. This is
        // called while a visitor waits for /demo to answer, and the pipeline
        // does per-batch work — correspondent lookup, one query per rule — that
        // would otherwise be repeated a dozen times over.
        $this->pipeline->run($account, $ingested);

        // The senders as contacts, which is what makes compose worth showing:
        // recipients autocomplete from the address book, and that is normally
        // filled by harvesting synced mail rather than by seeding it.
        $contacts = [];

        foreach (self::THREADS as $entry) {
            $contacts[] = [
                'email' => $entry['fromAddress'],
                'name'  => $entry['fromName'],
                // A correspondent is someone the user has WRITTEN to, and
                // MessageCategorizer treats that as an override: mail from one
                // is Primary whatever its bulk headers say. Flagging the
                // newsletter and the two robots as correspondents therefore
                // dragged them into Primary and emptied the category tabs —
                // and only on the SECOND seed, because the first ran before
                // these contacts existed. A demo that categorises correctly
                // once and then stops is worse than one that never did.
                'correspondent' => $entry['correspondent'] ?? true,
            ];
        }

        $this->contactRepository->upsertBatch($user, $contacts);

        $this->entityManager->flush();

        return array_merge($messages, $this->seedConversation($account, $inbox, $sent, $now));
    }

    /**
     * One thread with four turns in it, so the demo has a conversation.
     *
     * Every other thread here is a single message, which leaves the reading
     * pane's whole argument — replies collapse into one conversation — with
     * nothing to demonstrate it on.
     *
     * Threaded ONE AT A TIME rather than as a batch, and that is not a style
     * choice. MessageThreader resolves a reply's parent with a SQL query that
     * joins message.thread, so a parent created earlier in the same unflushed
     * batch is invisible to it: all four turns looked like conversation
     * openers and got a thread each. The code says as much where Gmail hits
     * the same wall from the other side. Flushing between turns is what makes
     * each parent findable by the time the next one asks for it.
     *
     * @return list<Message>
     */
    private function seedConversation(
        Account           $account,
        Label             $inbox,
        Label             $sent,
        DateTimeImmutable $now,
    ): array {
        $messages   = [];
        $previousId = null;

        foreach (self::CONVERSATION as $turn) {
            $receivedAt = $now->modify(sprintf('-%d hours', $turn['hoursAgo']));
            $messageId  = MessageIdHelper::mint($turn['address']);

            $message = new Message();
            $message->account     = $account;
            $message->messageId   = $messageId;
            $message->subject     = null === $previousId
                ? self::CONVERSATION_SUBJECT
                : 'Re: '.self::CONVERSATION_SUBJECT;
            $message->fromName    = $turn['from'];
            $message->fromAddress = $turn['address'];
            $message->toAddresses = true === $turn['mine']
                ? [['name' => 'Priya Raman', 'address' => 'priya.raman@example.com']]
                : [['name' => 'You', 'address' => self::ACCOUNT_EMAIL]];
            $message->bodyText       = $turn['body'];
            $message->receivedAt     = $receivedAt;
            $message->sentAt         = $receivedAt;
            $message->syncedAt       = $now;
            $message->flags          = [];
            $message->hasAttachments = false;

            // Read, all of them. A conversation in which your own replies show
            // as unread mail from yourself is not a conversation.
            $message->seenAt = $receivedAt;

            if (null !== $previousId) {
                $message->inReplyTo  = [$previousId];
                $message->references = [$previousId];
            }

            $message->addLabel(true === $turn['mine'] ? $sent : $inbox);

            $this->entityManager->persist($message);
            $this->entityManager->flush();

            $this->pipeline->run($account, [new IngestedMessage($message, $account)]);

            $messages[]  = $message;
            $previousId  = $messageId;
        }

        return $messages;
    }

    /**
     * A week with something in it.
     *
     * A demo whose calendar is empty shows the grid and none of the point: the
     * week view, the overlap handling and the docked pane beside the mail all
     * need events to be anything but a ruled page. These are the same kind of
     * dull and plausible as the mailbox — a standing meeting, a haircut, a
     * birthday that lasts all day — and they are written relative to today, so
     * the demo is never showing last month.
     *
     * Wiped first for the reason everything here is: run twice, this must give
     * the same week rather than two of everything.
     *
     * @return int how many events were written
     */
    public function seedCalendar(User $user): int
    {
        foreach ($this->calendarEvents->findBy(['usr' => $user]) as $event) {
            $this->entityManager->remove($event);
        }

        $this->entityManager->flush();

        $calendar = $this->calendarProvisioner->defaultFor($user);
        $zone     = $this->timezones->resolve($user);
        $today    = new DateTimeImmutable('today', $zone);

        // Day offset from today, start hour, duration in minutes, title,
        // location, all-day. Kept inside the working week either side of today
        // so the default view has something in it whichever day it is opened.
        $plan = [
            [0,  9,  30, 'Stand-up',                 'Jitsi',            false],
            [0, 14,  60, 'Call with Marek — trim',   'Phone',            false],
            [1, 11,  45, 'Dentist',                  'Praxis Dr. Ilg',   false],
            [1, 18,  90, 'Reading group',            'Sofia\'s place',   false],
            [2,  0,   0, 'Jonas — birthday',         null,               true],
            [2, 10, 120, 'Workshop: sharpening',     'Werkstatt Nord',   false],
            [3,  8,  30, 'Meter reading due',        null,               false],
            [4, 16,  60, 'Pick up the shelves',      'Nowak Schreinerei', false],
        ];

        $written = 0;

        foreach ($plan as [$dayOffset, $hour, $minutes, $title, $location, $allDay]) {
            $day = $today->modify(sprintf('+%d days', $dayOffset));

            if (true === $allDay) {
                $startsAt = $day;
                $endsAt   = $day->modify('+1 day');
            } else {
                $startsAt = $day->setTime($hour, 0);
                $endsAt   = $startsAt->modify(sprintf('+%d minutes', $minutes));
            }

            $this->calendarWriter->write(
                event:    new CalendarEvent(),
                calendar: $calendar,
                user:     $user,
                title:    $title,
                startsAt: $startsAt,
                endsAt:   $endsAt,
                timeZone: $zone->getName(),
                isAllDay: $allDay,
                location: $location,
            );

            ++$written;
        }

        $this->entityManager->flush();

        return $written;
    }
}
