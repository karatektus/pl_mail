<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\GhostMessageReaper;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seven blank rows were the whole Spam badge.
 *
 * They had no sender, no subject, a "?" avatar, an empty body and a date of
 * 1 January 1970, and they counted as unread — so the badge promised seven new
 * messages and the folder showed seven corpses. MessageSyncer no longer creates
 * them (see MessageSyncerGhostFetchTest for the mechanism); this covers the
 * other half, which is removing the ones already in the database.
 *
 * The risk in a reaper is never that it misses something — the next sync tries
 * again — it is that it deletes mail. So most of what follows is not "the ghost
 * is removed" but "this real message, which looks blank in one way or another,
 * is left alone". A predicate that cannot survive these is not safe to run
 * unattended on someone's mail, which is exactly what it does.
 */
final class GhostMessageReaperTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $messages;
    private GhostMessageReaper $reaper;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Mailbox $junk;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->messages   = $container->get(MessageRepository::class);
        $this->reaper     = $container->get(GhostMessageReaper::class);
        $this->labels     = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the reported bug ──────────────────────────────────────────────────

    public function testAnEpochDatedGhostIsFoundAndRemoved(): void
    {
        // Held before the reap: Doctrine clears the identifier on the entity as
        // it flushes the delete, so asking the object afterwards asks nothing.
        $ghostId = $this->ghost()->id;

        self::assertSame(1, $this->reaper->reap(), 'the ghost has to be reaped');

        self::assertNull(
            $this->messages->find($ghostId),
            'and actually gone from the database, not merely reported',
        );
    }

    /**
     * The reported shape exactly: several of them, in Junk, all unread.
     */
    public function testTheWholeSpamBadgeOfGhostsGoes(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->ghost();
        }

        self::assertSame(7, $this->reaper->reap());
        self::assertCount(0, $this->messages->findEpochGhosts(100));
    }

    // ── what it must never touch ──────────────────────────────────────────

    /**
     * The case the report named. A message can legitimately have no subject;
     * it still has a sender, a Message-ID and a real date, and any one of those
     * is enough to disqualify it.
     */
    public function testAGenuinelySubjectlessMessageIsNotAGhost(): void
    {
        $subjectless = $this->realMessage(subject: '', from: 'kunde@example.test');

        self::assertSame(0, $this->reaper->reap());

        $this->em->flush();

        self::assertNotNull(
            $this->messages->find($subjectless->id),
            'a subjectless message is mail and must survive',
        );
    }

    /**
     * Blank in every field the ghost is blank in — no subject, no body, no
     * attachments — and still mail, because it is dated and identified.
     */
    public function testAnEmptyButDatedAndIdentifiedMessageIsNotAGhost(): void
    {
        $empty = $this->realMessage(subject: '', from: 'kunde@example.test');
        $empty->bodyText = '';
        $empty->bodyHtml = '';
        $this->em->flush();

        self::assertSame(0, $this->reaper->reap());
        self::assertNotNull($this->messages->find($empty->id));
    }

    /**
     * A message whose From could not be parsed is still mail if it is dated and
     * carries a Message-ID — it renders with a "?" avatar exactly like a ghost
     * does, which is precisely why the avatar cannot be the test.
     */
    public function testASenderlessButDatedMessageIsNotAGhost(): void
    {
        $senderless = $this->realMessage(subject: 'Rechnung', from: null);

        self::assertSame(0, $this->reaper->reap());
        self::assertNotNull($this->messages->find($senderless->id));
    }

    /**
     * An empty draft is the one thing in the schema that is legitimately blank
     * in every field at once. It has no receivedAt rather than an epoch one,
     * and it carries the Drafts label; both keep it out, because losing a draft
     * someone is still writing is the worst outcome here.
     */
    public function testAnEmptyDraftIsNotAGhost(): void
    {
        $draft = new Message();
        $draft->account        = $this->account;
        $draft->hasAttachments = false;
        $draft->flags          = [];
        $draft->addLabel($this->labels->systemLabel(LabelRole::Drafts, $this->account));
        $this->em->persist($draft);
        $this->em->flush();

        self::assertSame(0, $this->reaper->reap());

        $this->em->flush();

        self::assertNotNull(
            $this->messages->find($draft->id),
            'an unsent empty draft must never be reaped',
        );
    }

    /**
     * A draft that somehow also carries an epoch date still survives, because
     * the label exclusion is independent of the date test rather than a
     * refinement of it.
     */
    public function testAnEpochDatedDraftIsStillNotAGhost(): void
    {
        $draft = $this->blankRow();
        $draft->addLabel($this->labels->systemLabel(LabelRole::Drafts, $this->account));
        $this->em->flush();

        self::assertSame(0, $this->reaper->reap());
        self::assertNotNull($this->messages->find($draft->id));
    }

    /**
     * The date is what makes a ghost findable, so a blank row dated in the
     * present is deliberately out of reach. It would be a ghost the syncer can
     * no longer produce, and reaching for it would put every undated oddity in
     * the database in range.
     */
    public function testABlankRowWithARealDateIsLeftAlone(): void
    {
        $blank = $this->blankRow();
        $blank->receivedAt = new DateTimeImmutable('2026-08-10 09:00:00');
        $blank->sentAt     = $blank->receivedAt;
        $this->em->flush();

        self::assertSame(0, $this->reaper->reap());
        self::assertNotNull($this->messages->find($blank->id));
    }

    /**
     * A ghost that picked up an attachment row is not a ghost — something was
     * fetched, so the fetch was not empty, and whatever it is deserves a human
     * rather than a reaper.
     */
    public function testARowWithAttachmentsIsNotAGhost(): void
    {
        $withAttachment = $this->blankRow();
        $withAttachment->hasAttachments = true;
        $this->em->flush();

        self::assertSame(0, $this->reaper->reap());
        self::assertNotNull($this->messages->find($withAttachment->id));
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * A row exactly as the old MessageSyncer wrote it from an empty fetch:
     * every identifying field blank, dated at the epoch because
     * Carbon::parse(false) lands there, and unread because nothing set seenAt.
     */
    private function ghost(): Message
    {
        $ghost = $this->blankRow();
        $this->em->flush();

        return $ghost;
    }

    private function blankRow(): Message
    {
        $epoch = new DateTimeImmutable('@0');

        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->junk;
        $message->imapUid        = random_int(1000, 9999);
        $message->messageId      = null;
        $message->subject        = '';
        $message->fromAddress    = null;
        $message->fromName       = null;
        $message->bodyText       = '';
        $message->bodyHtml       = '';
        $message->hasAttachments = false;
        $message->seenAt         = null;
        $message->flags          = [];
        $message->receivedAt     = $epoch;
        $message->sentAt         = $epoch;

        $this->em->persist($message);

        return $message;
    }

    private function realMessage(string $subject, ?string $from): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->junk;
        $message->imapUid        = random_int(1000, 9999);
        $message->messageId      = MessageIdHelper::mint($from ?? 'someone@example.test');
        $message->subject        = $subject;
        $message->fromAddress    = $from;
        $message->bodyText       = 'body';
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email     = 'ghost-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Ghost';
        $this->user->nameLast  = 'Reaper';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr            = $this->user;
        $this->account->email          = 'ghost-fixture@example.test';
        $this->account->username       = 'ghost-fixture@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);

        $this->junk = new Mailbox();
        $this->junk->account       = $this->account;
        $this->junk->name          = 'Junk';
        $this->junk->fullPath      = 'Junk';
        $this->junk->specialUse    = MailboxSpecialUse::JUNK;
        $this->junk->isSyncEnabled = true;
        $this->junk->isIdleEnabled = false;
        $this->em->persist($this->junk);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->labels->bindMailbox(
            $this->labels->systemLabel(LabelRole::Spam, $this->account),
            $this->junk,
        );

        $this->em->flush();
    }
}
