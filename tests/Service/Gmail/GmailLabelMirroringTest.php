<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Gmail\GmailLabelPolicy;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What Gmail is allowed to say about a message's labels, and what it is not.
 *
 * Label application only ever added. applyTranslatedLabels() resolved the ids
 * Gmail reported and put every one of them on the message, and nothing ever
 * came off — so unfiling in Gmail left the label here permanently. The visible
 * form of that was archiving: in Gmail, archiving *is* the removal of INBOX and
 * nothing else, so a message archived in the web interface went on showing in
 * plMail's inbox forever, and no amount of syncing could change it.
 *
 * Additive was the safe half of a rule whose other half had not been written,
 * because not all of a message's labels are Gmail's to speak for. Snoozed is
 * plMail's own bookkeeping. Archive has no Gmail counterpart at all. A user may
 * keep labels here that exist nowhere else. An authoritative rule that could not
 * tell the difference would answer the first archive by deleting the user's
 * local filing — which is why the missing piece was a policy and not a flag.
 *
 * These tests state a Gmail label set the way the other sync tests state a
 * server, and assert what plMail was entitled to conclude. The multi-account
 * pair at the end is the one that would have caught the bug worth fearing.
 */
final class GmailLabelMirroringTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private GmailMessageBuilder $builder;
    private GmailLabelPolicy $policy;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Account $sibling;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->builder    = $container->get(GmailMessageBuilder::class);
        $this->policy     = $container->get(GmailLabelPolicy::class);
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

    // ── the policy itself ────────────────────────────────────────────────

    /**
     * The discriminator is already in the data: a label Gmail knows carries a
     * gmailLabelId on its binding, put there by GmailLabelSyncer. No new column
     * — the same shape GraphLabelPolicy uses for graphFolderId.
     */
    public function testALabelWithAGmailIdIsGmailsToSpeakFor(): void
    {
        $inbox = $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);

        self::assertTrue($this->policy->isProviderOwned($inbox, $this->account));
        self::assertFalse($this->policy->isInternal($inbox, $this->account));
    }

    /**
     * Snoozed is plMail's alone — nothing in Gmail corresponds to it, so it is
     * never bound, so it falls out as internal by the ordinary rule rather than
     * by being named as a special case.
     */
    public function testSnoozedIsPlMailsOwn(): void
    {
        $snoozed = $this->labels->systemLabel(LabelRole::Snoozed, $this->account);
        $this->em->flush();

        self::assertTrue($this->policy->isInternal($snoozed, $this->account));
    }

    /**
     * Archive likewise, and for a more interesting reason: Gmail has no ARCHIVE
     * label, because it models archiving as the absence of INBOX. So there is
     * nothing to bind it to and it is internal for the same ordinary reason.
     */
    public function testArchiveIsInternalBecauseGmailHasNoSuchLabel(): void
    {
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->account);
        $this->em->flush();

        self::assertTrue($this->policy->isInternal($archive, $this->account));
    }

    // ── mirroring, both directions ───────────────────────────────────────

    /**
     * A label put on in Gmail appears here. This half always worked.
     */
    public function testALabelAddedInGmailIsAddedHere(): void
    {
        $projekte = $this->gmailLabel(null, 'Label_projekte', $this->account, 'Projekte');
        $message  = $this->message();

        $this->apply($message, ['INBOX', 'Label_projekte']);

        self::assertTrue($message->hasLabel($projekte));
    }

    /**
     * The half that did not. A label taken off in Gmail comes off here.
     */
    public function testALabelRemovedInGmailIsRemovedHere(): void
    {
        $projekte = $this->gmailLabel(null, 'Label_projekte', $this->account, 'Projekte');
        $inbox    = $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $message  = $this->message();

        $this->apply($message, ['INBOX', 'Label_projekte']);
        self::assertTrue($message->hasLabel($projekte));

        $this->apply($message, ['INBOX']);

        self::assertFalse($message->hasLabel($projekte), 'Gmail no longer files it there, so neither does plMail');
        self::assertTrue($message->hasLabel($inbox), 'and the one it does still name stays');
    }

    /**
     * The visible form of the bug. Archiving in Gmail is the removal of INBOX
     * and nothing else, so a message archived in the web interface used to go
     * on showing in plMail's inbox forever.
     */
    public function testArchivingInGmailTakesTheMessageOutOfTheInboxHere(): void
    {
        $inbox   = $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);
        self::assertTrue($message->hasLabel($inbox));

        // What Gmail reports for an archived message: it simply stops saying
        // INBOX. There is no ARCHIVE label to arrive in its place.
        $this->apply($message, []);

        self::assertFalse($message->hasLabel($inbox), 'archived in Gmail is archived here');
    }

    // ── archiving, which Gmail expresses as an absence ───────────────────

    /**
     * Gmail has no Archive label, so the authoritative removal above correctly
     * takes the message out of the inbox and leaves it wearing nothing that
     * says where it went — out of the inbox, out of the archive, reachable only
     * through search. plMail's Archive view is a label, so the label has to be
     * put on for the two sides to agree about a message either of them
     * archived.
     */
    public function testArchivingInGmailPutsTheMessageInPlMailsArchive(): void
    {
        $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);

        $this->apply($message, []);

        self::assertTrue(
            $message->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)),
            'the Archive view has to agree regardless of which side archived it',
        );
    }

    /**
     * And back again. Un-archiving in Gmail is INBOX returning, and the Archive
     * label is the thing that would otherwise survive it — leaving the message
     * in both lists at once.
     */
    public function testUnarchivingInGmailTakesTheArchiveLabelBackOff(): void
    {
        $inbox   = $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);
        $this->apply($message, []);
        self::assertTrue($message->hasLabel($archive));

        $this->apply($message, ['INBOX']);

        self::assertTrue($message->hasLabel($inbox));
        self::assertFalse($message->hasLabel($archive), 'it is back in the inbox, so it is not archived');
    }

    /**
     * Trashing is not archiving. It also removes INBOX, and it already says
     * where the message went — Archive means "left the inbox and went nowhere
     * in particular", which is precisely the case with nothing else to say.
     */
    public function testTrashingInGmailDoesNotAlsoArchive(): void
    {
        $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $this->gmailLabel(LabelRole::Trash, 'TRASH', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);
        $this->apply($message, ['TRASH']);

        self::assertFalse(
            $message->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)),
            'it went to Trash, which is a destination in its own right',
        );
    }

    public function testMarkingSpamInGmailDoesNotAlsoArchive(): void
    {
        $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $this->gmailLabel(LabelRole::Spam, 'SPAM', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);
        $this->apply($message, ['SPAM']);

        self::assertFalse($message->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)));
    }

    /**
     * A snoozed conversation has left the inbox and plMail already knows where
     * it went. Archiving it as well would show it in the archive while it is
     * waiting to come back.
     */
    public function testASnoozedMessageLeavingTheInboxIsNotArchived(): void
    {
        $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);

        $message->addLabel($this->labels->systemLabel(LabelRole::Snoozed, $this->account));
        $this->em->flush();

        $this->apply($message, []);

        self::assertFalse($message->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)));
    }

    /**
     * Written as a transition rather than a state, and this is why. "Has no
     * INBOX" is true of Sent mail, of drafts, and of every message on an
     * account that has never had an inbox label — inferring Archive from the
     * state would put the label on all of them.
     *
     * It is also what makes this not backfill: a message archived in Gmail
     * before this shipped never makes the transition, because plMail never saw
     * it in the inbox to begin with. A resync is what puts those right.
     */
    public function testAMessageThatNeverHadInboxIsNotArchivedRetroactively(): void
    {
        $this->gmailLabel(LabelRole::Sent, 'SENT', $this->account);
        $message = $this->message();

        $this->apply($message, ['SENT']);

        self::assertFalse(
            $message->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)),
            'sent mail has no inbox to have left',
        );
    }

    // ── what Gmail may not touch ─────────────────────────────────────────

    /**
     * The counter-case the policy exists for. A label Gmail has never heard of
     * is not one its silence can remove — otherwise the first archive would
     * delete the user's local filing along with the Inbox label.
     */
    public function testALabelGmailHasNeverHeardOfSurvivesAnEmptyLabelSet(): void
    {
        $localOnly = $this->labels->customChain(['Steuer'], $this->account);
        $message   = $this->message();

        self::assertNotNull($localOnly);
        $message->addLabel($localOnly);
        $this->em->flush();

        $this->apply($message, []);

        self::assertTrue($message->hasLabel($localOnly), 'plMail-only filing is not Gmail\'s to delete');
    }

    public function testSnoozedSurvivesAnEmptyLabelSet(): void
    {
        $snoozed = $this->labels->systemLabel(LabelRole::Snoozed, $this->account);
        $message = $this->message();

        $message->addLabel($snoozed);
        $this->em->flush();

        $this->apply($message, []);

        self::assertTrue($message->hasLabel($snoozed), 'snoozing is plMail\'s own bookkeeping');
    }

    // ── two accounts, which is where this could have gone wrong ──────────

    /**
     * The bug worth fearing, and the reason the policy is asked about the
     * *carrier* account rather than the message's own.
     *
     * Labels are user-scoped: one Label row is materialised on every account
     * that has it, each binding carrying its own remote id. So the same label
     * can be Gmail-owned on one account and purely local on another — and a
     * feed from the account that has never heard of it must not be allowed to
     * remove it. Silence from one mailbox is not a statement about another.
     */
    public function testAFeedFromOneAccountCannotRemoveALabelOnlyTheOtherKnows(): void
    {
        // Bound on the sibling with a Gmail id; on this account it exists as a
        // label but Gmail knows nothing about it.
        $shared = $this->labels->customChain(['Rechnungen'], $this->sibling);
        self::assertNotNull($shared);
        $this->labels->binding($shared, $this->sibling)->gmailLabelId = 'Label_rechnungen';
        $this->labels->binding($shared, $this->account);
        $this->em->flush();

        self::assertTrue($this->policy->isProviderOwned($shared, $this->sibling));
        self::assertTrue($this->policy->isInternal($shared, $this->account), 'this account\'s Gmail has no such label');

        $message = $this->message();
        $message->addLabel($shared);
        $this->em->flush();

        // This account's feed reports a label set that does not mention it.
        $this->apply($message, []);

        self::assertTrue(
            $message->hasLabel($shared),
            'the account whose feed this is has never heard of the label, so its silence means nothing',
        );
    }

    /**
     * And the other side of the same rule, so it is not merely never removing
     * anything: a label this account's Gmail *does* know, omitted from this
     * account's feed, does come off.
     */
    public function testAFeedFromTheAccountThatOwnsTheLabelDoesRemoveIt(): void
    {
        $owned   = $this->gmailLabel(null, 'Label_rechnungen', $this->account, 'Rechnungen');
        $message = $this->message();

        $this->apply($message, ['Label_rechnungen']);
        self::assertTrue($message->hasLabel($owned));

        $this->apply($message, []);

        self::assertFalse($message->hasLabel($owned));
    }

    // ── the conversation ─────────────────────────────────────────────────

    /**
     * A thread's labels are the union of its messages', so they have to be
     * re-derived rather than accumulated. Enrichment used to add to the thread
     * and never take away, which was consistent with application only ever
     * adding — now that a label can genuinely leave a message, a thread that
     * kept everything its messages had ever carried would go on showing in the
     * inbox after the last of them was archived.
     */
    public function testAThreadStopsWearingALabelNoneOfItsMessagesCarry(): void
    {
        $inbox   = $this->gmailLabel(LabelRole::Inbox, 'INBOX', $this->account);
        $message = $this->message();

        $this->apply($message, ['INBOX']);

        $thread = $message->thread;
        self::assertNotNull($thread);
        $thread->addLabel($inbox);
        $this->em->flush();

        $this->apply($message, []);

        self::getContainer()->get('App\Service\Label\ThreadLabelSynchronizer')->sync($thread);

        self::assertFalse($thread->hasLabel($inbox), 'no message of it is in the inbox any more');
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * Apply a Gmail label set to a message, the way the batch handler does:
     * resolved against the carrier account, attributed to the same one here.
     *
     * @param list<string> $labelIds
     */
    private function apply(Message $message, array $labelIds): void
    {
        $message->gmailLabelIds = $labelIds;

        $this->builder->applyTranslatedLabels($message, $labelIds, $this->account, $this->account);

        $this->em->flush();
    }

    /**
     * A label the given account's Gmail knows, which is what a gmailLabelId on
     * the binding means.
     */
    private function gmailLabel(
        ?LabelRole $role,
        string     $gmailLabelId,
        Account    $account,
        ?string    $name = null,
    ): Label {
        $label = null !== $role
            ? $this->labels->systemLabel($role, $account)
            : $this->labels->customChain([(string) $name], $account);

        self::assertNotNull($label);

        $this->labels->binding($label, $account)->gmailLabelId = $gmailLabelId;
        $this->em->flush();

        return $label;
    }

    private function message(): Message
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account        = $this->account;
        $message->gmailId        = 'gmail-' . uniqid('', true);
        $message->messageId      = uniqid('', true) . '@example.test';
        $message->subject        = 'Rechnung';
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = 'Rechnung';
        $message->hasAttachments = false;
        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $this->em->persist($message);
        $thread->addMessage($message);

        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'gmail-labels-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Gmail';
        $this->user->nameLast = 'Labels';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = $this->gmailAccount('primary');
        $this->sibling = $this->gmailAccount('sibling');

        $this->em->flush();

        $this->user->addAccount($this->account);
        $this->user->addAccount($this->sibling);

        $this->em->flush();
    }

    private function gmailAccount(string $slug): Account
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = $slug . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'imap.gmail.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.gmail.com';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = AuthType::OAuth2->value;
        $account->oauthProvider  = MailProvider::Google->value;
        $account->isActive       = true;

        $this->em->persist($account);

        return $account;
    }
}
