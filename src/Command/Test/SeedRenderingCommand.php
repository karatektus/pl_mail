<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\TrustedImageSender;
use App\Entity\Mail\MessagePart;
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
 * The three messages the rendering-security specs are built on.
 *
 * Each one is a reproduction of something from the bug report, kept as close to
 * the original as a fixture can be:
 *
 *  · "E2E Remote Images" — a newsletter with remote images including a
 *    526×5 tracking pixel, the exact shape observed loading from
 *    images.ctfassets.net. The host here is a `.invalid` name, which RFC 2606
 *    guarantees will never resolve: if the block ever regresses, the spec sees
 *    a request attempt rather than a passing test against a host that happens
 *    to be down.
 *  · "E2E Phish Invoice" — the Hetzner-branded phish, in Spam. Display name
 *    "Hetzner Online GmbH", real sender support@ownkhalsick.com, a "Rechnung"
 *    button pointing somewhere else entirely.
 *  · "E2E Long Body" — a very long body with an attachment, so "are the
 *    attachment chips above the fold" and "does the header stay put" are
 *    questions with an answer.
 *
 * Adds only its own threads and removes previous copies by subject, like
 * seed-attachment does, so it composes with seed-mail in either order.
 *
 * Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:seed-rendering',
    description: 'Seed remote-image, phishing and long-body messages for the rendering-security E2E tests',
)]
final class SeedRenderingCommand extends Command
{
    use TargetsTestUser;

    /** Shared with app:test:seed-mail. */
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    public const string SUBJECT_REMOTE    = 'E2E Remote Images';
    public const string SUBJECT_PHISH     = 'E2E Phish Invoice';
    public const string SUBJECT_LONG      = 'E2E Long Body';
    public const string SUBJECT_FETCHABLE = 'E2E Fetchable Image';
    public const string SUBJECT_QUOTED    = 'E2E Quoted Reply';

    /**
     * Markers the collapse-quoted-text spec keys off: the sender's new line must
     * be visible, the quoted history must start hidden behind the toggle.
     */
    public const string QUOTED_NEW    = 'This is my brand-new reply text.';
    public const string QUOTED_BURIED = 'This is the original quoted history that starts hidden.';

    /**
     * The one remote reference in these fixtures that points at a host the proxy
     * can actually reach, so a spec can prove the last mile the others cannot:
     * that a proxied image renders as real pixels INSIDE the opaque-origin
     * sandbox, where the browser sends no session cookie. Every other fixture
     * uses the `.invalid` host on purpose (a leak is then an observable failed
     * lookup); this one is deliberately the exception, and only the render spec
     * loads it, so the external dependency never touches another test.
     */
    public const string FETCHABLE_URL = 'https://www.gstatic.com/webp/gallery/1.jpg';
    public const string FETCHABLE_ALT = 'E2E Fetchable Render Probe';

    /**
     * The host every remote reference in the fixtures points at.
     *
     * `.invalid` is reserved by RFC 2606 and can never be registered or
     * resolved, which is exactly what a privacy fixture wants: a leak is a
     * failed DNS lookup the spec can observe, not a silent success against
     * somebody's real CDN.
     */
    public const string REMOTE_HOST = 'tracker.e2e-rendering.invalid';

    private const string FILENAME = 'e2e-long-body.txt';
    private const string CONTENTS = "Seeded attachment for the long-body message.\n";

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
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
            $io->error('app:test:seed-rendering must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $this->resolveUserEmail($input)]);

        if (null === $user) {
            $io->error('E2E user not found — run app:test:seed-user first.');

            return Command::FAILURE;
        }

        $account = $this->account($user);

        foreach ([self::SUBJECT_REMOTE, self::SUBJECT_PHISH, self::SUBJECT_LONG, self::SUBJECT_FETCHABLE, self::SUBJECT_QUOTED] as $subject) {
            $this->removePreviousSeed($account, $subject);
        }

        $this->forgetTrustedSenders($user);

        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $spam  = $this->labelResolver->systemLabel(LabelRole::Spam, $account);
        $now   = new DateTimeImmutable();

        $remote = $this->seedMessage(
            account:  $account,
            user:     $user,
            label:    $inbox,
            subject:  self::SUBJECT_REMOTE,
            fromName: 'E2E Newsletter',
            fromAddr: 'newsletter@e2e.test',
            html:     $this->remoteImageBody(),
            when:     $now,
        );

        $phish = $this->seedMessage(
            account:  $account,
            user:     $user,
            label:    $spam,
            subject:  self::SUBJECT_PHISH,
            // The display name that Rule 2 of SenderIdentityChecker fires on:
            // it carries a legal form ("GmbH"), and none of its words occur in
            // the registrable domain of the address beside it.
            fromName: 'Hetzner Online GmbH',
            fromAddr: 'support@ownkhalsick.com',
            html:     $this->phishBody(),
            when:     $now->modify('-1 minute'),
        );

        $long = $this->seedMessage(
            account:  $account,
            user:     $user,
            label:    $inbox,
            subject:  self::SUBJECT_LONG,
            fromName: 'E2E Long Sender',
            fromAddr: 'long@e2e.test',
            html:     $this->longBody(),
            when:     $now->modify('-2 minutes'),
        );

        $long->hasAttachments = true;

        $fetchable = $this->seedMessage(
            account:  $account,
            user:     $user,
            label:    $inbox,
            subject:  self::SUBJECT_FETCHABLE,
            fromName: 'E2E Fetchable',
            fromAddr: 'fetchable@e2e.test',
            html:     $this->fetchableImageBody(),
            when:     $now->modify('-3 minutes'),
        );

        $quoted = $this->seedMessage(
            account:  $account,
            user:     $user,
            label:    $inbox,
            subject:  self::SUBJECT_QUOTED,
            fromName: 'E2E Quoter',
            fromAddr: 'quoter@e2e.test',
            html:     $this->quotedReplyBody(),
            when:     $now->modify('-4 minutes'),
        );

        $this->entityManager->flush();

        $this->attach($long);

        $accountId = (int) $account->id;
        $threadIds = [];

        foreach ([$remote, $phish, $long, $fetchable, $quoted] as $message) {
            $this->stateManager->recordCreated($accountId, JmapObjectType::Email, (string) $message->id);

            if (null !== $message->thread) {
                $threadIds[] = (int) $message->thread->id;
            }
        }

        $this->stateManager->recordThreadsTouched($accountId, $threadIds);
        $this->entityManager->flush();

        $io->success('Seeded 5 rendering-security threads.');

        return Command::SUCCESS;
    }

    /**
     * Eight remote images, one of them the 526×5 strip a tracking pixel wears
     * when it is pretending to be a spacer, plus a remote CSS background —
     * because a background image is a tracker that happens to be styled, and
     * the blocker has to catch both.
     */
    private function remoteImageBody(): string
    {
        $images = '';

        for ($i = 1; $i <= 7; $i++) {
            $images .= sprintf(
                '<p><img src="https://%s/asset-%d.png" width="120" height="90" alt="Product %d"></p>',
                self::REMOTE_HOST,
                $i,
                $i,
            );
        }

        return sprintf(
            '<div style="background-image: url(https://%s/backdrop.png); padding: 12px;">'
            . '<h2>Your weekly digest</h2>%s'
            . '<img src="https://%s/open.gif" width="526" height="5" alt="">'
            . '<p><a href="https://%s/unsubscribe">Unsubscribe</a></p>'
            . '</div>',
            self::REMOTE_HOST,
            $images,
            self::REMOTE_HOST,
            self::REMOTE_HOST,
        );
    }

    /**
     * A single remote image the proxy can genuinely fetch, so the render spec
     * can assert real pixels reach the inside of the opaque frame. Blocked by
     * default like any other remote image; only "Show images" loads it, and only
     * that one spec does so.
     */
    private function fetchableImageBody(): string
    {
        return sprintf(
            '<div style="padding: 12px;"><h2>One real image</h2>'
            . '<p><img src="%s" width="550" height="368" alt="%s"></p></div>',
            self::FETCHABLE_URL,
            self::FETCHABLE_ALT,
        );
    }

    private function phishBody(): string
    {
        return '<div style="font-family: Arial, sans-serif;">'
            . '<h2>Ihre Rechnung ist f&auml;llig</h2>'
            . '<p>Sehr geehrter Kunde, bitte begleichen Sie Ihre offene Rechnung.</p>'
            . '<p><a href="https://dereclamefabriek.nl/invoice-2026" '
            . 'style="background:#d50c2d;color:#fff;padding:10px 18px;text-decoration:none;">Rechnung</a></p>'
            . '<p>Mit freundlichen Gr&uuml;&szlig;en,<br>Hetzner Online GmbH</p>'
            . '</div>';
    }

    /** Long enough that anything below it is genuinely off-screen. */
    private function longBody(): string
    {
        $paragraphs = '';

        for ($i = 1; $i <= 60; $i++) {
            $paragraphs .= sprintf(
                '<p>Paragraph %d — this message is deliberately long, so that a reader '
                . 'scrolling through it leaves the header behind and would leave the '
                . 'attachments behind too, if they were still rendered underneath.</p>',
                $i,
            );
        }

        return '<div>' . $paragraphs . '</div>';
    }

    /**
     * A Gmail-shaped reply: the sender's new line, then an attribution line and
     * a blockquote of the message being answered. Already in post-sanitize form
     * (no class/id) since these fixtures write straight to bodyHtmlSafe — which
     * is exactly what QuoteCollapser sees at render time, and what it must fold
     * behind the "Show quoted text" toggle.
     */
    private function quotedReplyBody(): string
    {
        return sprintf(
            '<div dir="ltr">%s</div>'
            . '<div><div dir="ltr">On Mon, Aug 11, 2026 at 9:14 AM Jane Roe &lt;jane@e2e.test&gt; wrote:</div>'
            . '<blockquote style="border-left:1px solid #ccc;padding-left:1ex;margin:0">'
            . '<div dir="ltr">%s</div></blockquote></div>',
            self::QUOTED_NEW,
            self::QUOTED_BURIED,
        );
    }

    private function seedMessage(
        Account          $account,
        object           $user,
        object           $label,
        string           $subject,
        string           $fromName,
        string           $fromAddr,
        string           $html,
        DateTimeImmutable $when,
    ): Message {
        $message = new Message();
        $message->account     = $account;
        $message->subject     = $subject;
        $message->fromName    = $fromName;
        $message->fromAddress = $fromAddr;
        $message->toAddresses = [['name' => 'E2E Tester', 'address' => (string) $user->email]];
        $message->bodyText    = strip_tags($html);
        // Straight onto bodyHtmlSafe: these fixtures are testing what happens
        // AFTER sanitizing, and the markup above is already what the sanitizer
        // would emit — no scripts, no styles, inline attributes only.
        $message->bodyHtml     = $html;
        $message->bodyHtmlSafe = $html;
        $message->headers = [
            'Message-ID'   => sprintf('<%s@e2e.test>', md5($subject)),
            'From'         => sprintf('%s <%s>', $fromName, $fromAddr),
            'To'           => (string) $user->email,
            'Subject'      => $subject,
            'Date'         => $when->format(DATE_RFC2822),
            'Content-Type' => 'text/html; charset=utf-8',
        ];
        $message->receivedAt     = $when;
        $message->sentAt         = $when;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = $when;
        $message->addLabel($label);

        $this->entityManager->persist($message);

        $thread = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 1;
        $thread->category          = MessageCategory::Primary;
        $thread->attachmentCount   = 0;
        $thread->lastMessageAt     = $when;
        $thread->addLabel($label);

        $this->entityManager->persist($thread);
        $message->thread = $thread;

        return $message;
    }

    private function attach(Message $message): void
    {
        $storagePath = $this->attachmentStorage->store(
            (int) $message->account->id,
            0,
            (int) $message->id,
            self::FILENAME,
            self::CONTENTS,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/plain';
        $part->filename    = self::FILENAME;
        $part->disposition = 'attachment';
        $part->size        = strlen(self::CONTENTS);
        $part->storagePath = $storagePath;
        $part->isInline    = false;

        $message->addMessagePart($part);

        if (null !== $message->thread) {
            $message->thread->attachmentCount = 1;
        }

        $this->entityManager->persist($part);
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
     * Forget every sender this user has decided to trust with remote images.
     *
     * Part of seeding rather than a separate step, because a seed's job is to
     * put the mailbox into a KNOWN state and "which senders am I already
     * trusting" is part of that state. Reseeding the messages while leaving the
     * decisions about them behind produces a mailbox no run ever started from.
     *
     * The spec that needs this restores its own trust at the end, and that was
     * the only thing restoring it. So the first failure anywhere above that line
     * left the sender trusted for good, and every later run failed differently
     * and earlier — at the "Show images always" button, which is not offered for
     * a sender already trusted. One flake became a permanent failure that looked
     * like a second, unrelated bug. That is exactly how it presented in CI: one
     * run failing at the cleanup, its retry failing thirty lines earlier.
     *
     * A DELETE rather than a cascade off the messages: the row is keyed by
     * address and outlives every message from that sender, which is the whole
     * point of it.
     */
    private function forgetTrustedSenders(object $user): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM ' . TrustedImageSender::class . ' trusted WHERE trusted.usr = :user',
        )->setParameter('user', $user)->execute();
    }

    private function removePreviousSeed(Account $account, string $subject): void
    {
        $threads = $this->threadRepository->findBy(['account' => $account, 'subject' => $subject]);

        if (0 === count($threads)) {
            return;
        }

        $accountId  = (int) $account->id;
        $threadIds  = array_map(static fn (MessageThread $t): int => (int) $t->id, $threads);
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
