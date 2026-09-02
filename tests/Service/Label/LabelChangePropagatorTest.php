<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Service\Label\LabelChangePropagator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The one component that writes to somebody else's server.
 *
 * Everything else in the sync layer reads from a provider or writes to the
 * local database, both of which are recoverable. This turns local state into
 * outbound API calls: a bug here archives mail in a real Gmail account, or
 * moves an IMAP message to a folder the user never asked for, and there is no
 * undo. That is the entire reason it is the first thing tested.
 *
 * Asserted on what reaches the bus rather than on provider calls. The handlers
 * do the talking; this class decides *who* gets told and *what*, which is where
 * the interesting mistakes live — telling the wrong provider, or telling a
 * provider about a message it has never heard of.
 */
final class LabelChangePropagatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelChangePropagator $propagator;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->propagator = $container->get(LabelChangePropagator::class);

        $this->connection->beginTransaction();
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Each provider hears only about its own messages ───────────────────

    /**
     * The mistake this guards: a message with no provider id still being
     * announced to that provider, which the handler can only answer with a
     * 404 or — worse — by acting on the wrong message.
     */
    public function testAPlainImapMessageReachesOnlyTheImapQueue(): void
    {
        $message = $this->imapMessage();

        $this->propagator->markRead([$message], true);

        self::assertCount(1, $this->sentOfType(ApplyImapFlagsMessage::class));
        self::assertCount(0, $this->sentOfType(ApplyGmailLabelsMessage::class));
        self::assertCount(0, $this->sentOfType(ApplyGraphChangesMessage::class));
    }

    public function testAGmailMessageReachesOnlyTheGmailQueue(): void
    {
        $message = $this->gmailMessage();

        $this->propagator->markRead([$message], true);

        self::assertCount(1, $this->sentOfType(ApplyGmailLabelsMessage::class));
        self::assertCount(0, $this->sentOfType(ApplyImapFlagsMessage::class));
    }

    /**
     * A Gmail account whose message has no Gmail id yet — synced locally but
     * never matched up — must be skipped rather than sent as a null id.
     */
    public function testAGmailAccountMessageWithoutAProviderIdIsSkipped(): void
    {
        $message = $this->gmailMessage(gmailId: null);

        $this->propagator->markRead([$message], true);

        self::assertCount(0, $this->sentOfType(ApplyGmailLabelsMessage::class));
    }

    /**
     * GMAILIFY: an IMAP row carrying a Gmail identity still reaches Gmail.
     *
     * Google can fetch another mailbox into Gmail. Connect both here and every
     * message arrives twice, and SyncGmailMessageBatchHandler merges the pair
     * onto the IMAP row rather than keeping two — so the row belongs to an
     * account with no Gmail credentials and carries a perfectly good gmailId.
     *
     * This asked the row's OWN account whether to tell Gmail, got no, and
     * dropped the change. Every push: archive, trash, restore, star, mark-read,
     * every label. The report was a mail dragged out of spam that Hetzner
     * obeyed and Gmail filed straight back, because Gmail was never told.
     *
     * The job goes out under the CARRIER's id, which is what ApplyGmailLabels
     * needs — it uses the account for credentials and takes the gmail ids off
     * the rows.
     */
    public function testAGmailifiedImapMessageReachesGmailThroughItsCarrier(): void
    {
        $carrier = $this->gmailAccount();
        $imap    = $this->account(AuthType::Password);
        $message = $this->messageOn($imap, mailbox: $this->mailbox($imap), uid: 42, gmailId: 'g-carried');

        $message->gmailCarrierAccount = $carrier;
        $this->em->flush();

        $this->propagator->markRead([$message], true);

        $jobs = $this->sentOfType(ApplyGmailLabelsMessage::class);

        self::assertCount(1, $jobs, 'the Gmailified row never reached Gmail');
        self::assertSame((int) $carrier->id, $jobs[0]->accountId, 'the job went out under the wrong account');

        // And the IMAP side is untouched by the fix: the row still has a
        // mailbox and a uid, and that half was always working.
        self::assertCount(1, $this->sentOfType(ApplyImapFlagsMessage::class));
    }

    /**
     * A carrier that is no longer a Gmail account is refused rather than used.
     *
     * The column is nulled when the account is disconnected, but an account can
     * also be re-authenticated as something else — and pushing a Gmail id at
     * whatever it is now is worse than not pushing it.
     */
    public function testACarrierThatIsNoLongerGmailIsRefused(): void
    {
        $imap    = $this->account(AuthType::Password);
        $message = $this->messageOn($imap, mailbox: $this->mailbox($imap), uid: 42, gmailId: 'g-carried');

        $message->gmailCarrierAccount = $this->account(AuthType::OAuth2, 'microsoft');
        $this->em->flush();

        $this->propagator->markRead([$message], true);

        self::assertCount(0, $this->sentOfType(ApplyGmailLabelsMessage::class));
    }

    /** IMAP needs a mailbox and a UID; without both there is nothing to act on. */
    public function testAnImapMessageWithoutAUidIsSkipped(): void
    {
        $message = $this->imapMessage(uid: null);

        $this->propagator->markRead([$message], true);

        self::assertCount(0, $this->sentOfType(ApplyImapFlagsMessage::class));
    }

    // ── Batching ──────────────────────────────────────────────────────────

    /**
     * One job per account, not one per message. Two accounts must not be
     * collapsed into a single job — the handler authenticates as one account,
     * so a merged batch would send another account's ids on the wrong
     * connection.
     */
    public function testMessagesAreGroupedPerAccount(): void
    {
        $first  = $this->gmailMessage();
        $second = $this->gmailMessage();

        $this->propagator->markRead([$first, $second], true);
        $perAccount = $this->sentOfType(ApplyGmailLabelsMessage::class);

        self::assertCount(2, $perAccount, 'two accounts were merged into one job');
    }

    public function testTwoMessagesOnOneAccountShareOneJob(): void
    {
        $account = $this->gmailAccount();
        $first   = $this->messageOn($account, gmailId: 'g-1');
        $second  = $this->messageOn($account, gmailId: 'g-2');

        $this->propagator->markRead([$first, $second], true);

        $jobs = $this->sentOfType(ApplyGmailLabelsMessage::class);

        self::assertCount(1, $jobs);
    }

    // ── The payloads that reach a provider ────────────────────────────────

    /** Archiving in plMail must archive in Gmail, not merely locally. */
    public function testArchiveRemovesTheInboxLabelAtGmail(): void
    {
        $this->propagator->archive([$this->gmailMessage()]);

        $job = $this->sentOfType(ApplyGmailLabelsMessage::class)[0];

        self::assertSame(['INBOX'], $job->remove);
        self::assertSame([], $job->add);
    }

    public function testTrashAddsTrashAndRemovesInboxAtGmail(): void
    {
        $this->propagator->trash([$this->gmailMessage()]);

        $job = $this->sentOfType(ApplyGmailLabelsMessage::class)[0];

        self::assertSame(['TRASH'], $job->add);
        self::assertSame(['INBOX'], $job->remove);
    }

    /**
     * Gmail has no "delete for real" through this path — the scope plMail asks
     * for cannot do it. TRASH is the honest equivalent of what the UI offers.
     */
    public function testDeleteTrashesAtGmailRatherThanPurging(): void
    {
        $this->propagator->delete([$this->gmailMessage()]);

        $job = $this->sentOfType(ApplyGmailLabelsMessage::class)[0];

        self::assertSame(['TRASH'], $job->add);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function readStates(): iterable
    {
        yield 'marking read'   => [true, 'seen'];
        yield 'marking unread' => [false, 'unseen'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readStates')]
    public function testTheImapActionMatchesTheDirection(bool $read, string $expected): void
    {
        $this->propagator->markRead([$this->imapMessage()], $read);

        self::assertSame($expected, $this->sentOfType(ApplyImapFlagsMessage::class)[0]->action);
    }

    /** Nothing to say means nothing dispatched — no empty jobs on the queue. */
    public function testAnEmptyMessageListDispatchesNothing(): void
    {
        $this->propagator->archive([]);

        self::assertCount(0, $this->transport()->getSent());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<object> */
    // ── discarding, which had no caller until drafts needed one ───────────

    /**
     * delete() has always known how to say "this is gone" to each provider in
     * its own terms, and until drafts were wired to it nothing called it at
     * all — so the one action that removes mail from a server was reachable
     * only in principle.
     *
     * On IMAP that means an expunge, which is the whole point: a draft moved to
     * Trash instead would still be a draft the user discarded and can still
     * see.
     */
    public function testDiscardingAnImapDraftIssuesAnExpunge(): void
    {
        $message = $this->imapMessage();

        $this->propagator->delete([$message]);

        $sent = $this->sentOfType(ApplyImapFlagsMessage::class);

        self::assertCount(1, $sent);
        self::assertSame('delete', $sent[0]->action);
        self::assertSame(4242, $sent[0]->sourceUidFor((int) $message->id), 'the address travels with the job');
    }

    /**
     * Gmail's model, and deliberately not an expunge. A permanent delete needs
     * the full mail scope, which plMail does not ask for; TRASH is the
     * Gmail-native equivalent of what the discard button exposes.
     */
    public function testDiscardingAGmailDraftTrashesItThere(): void
    {
        $message = $this->gmailMessage();

        $this->propagator->delete([$message]);

        $sent = $this->sentOfType(ApplyGmailLabelsMessage::class);

        self::assertCount(1, $sent);
        self::assertSame(['TRASH'], $sent[0]->add);
        self::assertSame([], $sent[0]->remove);
    }

    /**
     * A draft that never reached any server has no address on one, so nothing
     * is announced. This is what keeps discarding a half-typed message from
     * talking to a mail server at all.
     */
    public function testDiscardingADraftThatNeverLeftPlMailAnnouncesNothing(): void
    {
        $message = $this->imapMessage(uid: null);

        $this->propagator->delete([$message]);

        self::assertCount(0, $this->sentOfType(ApplyImapFlagsMessage::class));
        self::assertCount(0, $this->sentOfType(ApplyGmailLabelsMessage::class));
        self::assertCount(0, $this->sentOfType(ApplyGraphChangesMessage::class));
    }

    private function sentOfType(string $class): array
    {
        $found = [];

        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $found[] = $message;
            }
        }

        return $found;
    }

    /**
     * The export queue by name, not whichever transport happens to exist:
     * these are outgoing pushes and sends, and which queue they land on is
     * the behaviour under test. Routing one of them onto ingest would put a
     * user's archive behind a mailbox sync, which is the thing the split was
     * made to stop.
     */
    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.export');

        // in-memory:// in .env.test, so this holds — asserted rather than cast,
        // because a transport change would otherwise surface as a confusing
        // "nothing was dispatched" rather than as its actual cause.
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function imapMessage(?int $uid = 4242): Message
    {
        $account = $this->account(AuthType::Password);
        $mailbox = $this->mailbox($account);

        return $this->messageOn($account, mailbox: $mailbox, uid: $uid);
    }

    private function gmailMessage(?string $gmailId = 'gmail-1'): Message
    {
        return $this->messageOn($this->gmailAccount(), gmailId: $gmailId);
    }

    private function gmailAccount(): Account
    {
        // isGmail() needs both: oauth2 *and* google. An account with only the
        // auth type set is a Microsoft account as far as this class is
        // concerned, which is exactly the confusion worth not having in a
        // fixture.
        return $this->account(AuthType::OAuth2, 'google');
    }

    private function messageOn(
        Account  $account,
        ?Mailbox $mailbox = null,
        ?int     $uid = null,
        ?string  $gmailId = null,
    ): Message {
        $thread = new MessageThread();
        $thread->account = $account;
        $thread->subject = 'Propagator fixture';
        $thread->normalizedSubject = 'propagator fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $account;
        $message->subject = 'Propagator fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<prop-%s@example.test>', uniqid('', true));

        if (null !== $mailbox) {
            $message->mailbox = $mailbox;
        }

        if (null !== $uid) {
            $message->imapUid = $uid;
        }

        if (null !== $gmailId) {
            $message->gmailId = $gmailId;
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function account(AuthType $authType, ?string $oauthProvider = null): Account
    {
        $user = new User();
        $user->email = 'prop-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Prop';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Prop Fixture';
        $account->username = 'prop-'.uniqid('', true).'@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = $authType->value;
        $account->isActive = true;

        if (null !== $oauthProvider) {
            $account->oauthProvider = $oauthProvider;
        }

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function mailbox(Account $account): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account = $account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
