<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which change-log rows follow from which announcement.
 *
 * That mapping used to be spelled out at every call site, and the copies that
 * left out the Thread were not obviously wrong when read — a phone would show
 * the message and an unchanged conversation list around it. So the assertions
 * below are about what an announcement writes, including the parts that are
 * easy to forget and the parts that are deliberately absent.
 *
 * There are fewer tests here than there are features that announce something,
 * because saving a draft, attaching a file and sending mail are the same
 * announcement. Where they differ — whether the id is new, whether there is a
 * conversation to name — is a parameter, and that is what is covered.
 *
 * Against the real change log rather than a doubled StateManager: entity ids
 * are the whole payload here, and an unsaved entity has none.
 */
final class MailChangeRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MailChangeRecorder $changes;
    private LabelResolver $labelResolver;

    private User $user;
    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->changes       = $container->get(MailChangeRecorder::class);
        $this->labelResolver = $container->get(LabelResolver::class);

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

    // ── the contract every caller inherits ────────────────────────────────

    /**
     * Recording persists and nothing else, which is what lets a caller put it
     * inside its own unit of work. A recorder that flushed would commit the
     * announcement of a change that the caller may still abandon.
     */
    public function testNothingIsWrittenUntilTheCallerFlushes(): void
    {
        $message = $this->message('unflushed');

        $since = $this->latestSequence();

        $this->changes->emailChanged($this->accountId(), (string) $message->id, false, null);

        self::assertSame([], $this->rowsSince($since), 'the recorder flushed on its own');

        $this->em->flush();

        self::assertNotSame([], $this->rowsSince($since));
    }

    // ── an email that changed ─────────────────────────────────────────────

    /**
     * The write that minted the row: a first autosave, or a message arriving
     * from a sync. The conversation goes with it — every client list is sorted
     * and summarised per thread.
     */
    public function testACreatedEmailIsAnnouncedWithItsConversation(): void
    {
        $message = $this->message('created', withThread: true);

        $rows = $this->record(
            fn () => $this->changes->emailChanged(
                $this->accountId(),
                (string) $message->id,
                true,
                $message->thread,
            ),
        );

        self::assertSame(
            [
                'Email:created:' . $message->id,
                'Thread:updated:' . $message->thread->id,
            ],
            $rows,
        );
    }

    /**
     * Every later write of the same message — the next keystroke's autosave, a
     * file attached, the send that turns it into mail. Announcing "created" for
     * an id the client already holds is the one thing RFC 8620 §5.2 forbids.
     */
    public function testAnEmailTheClientAlreadyHoldsIsAnnouncedAsAnUpdate(): void
    {
        $message = $this->message('updated', withThread: true);

        $rows = $this->record(
            fn () => $this->changes->emailChanged(
                $this->accountId(),
                (string) $message->id,
                false,
                $message->thread,
            ),
        );

        self::assertSame(
            [
                'Email:updated:' . $message->id,
                'Thread:updated:' . $message->thread->id,
            ],
            $rows,
        );
    }

    /**
     * No thread announced where the caller has none to announce yet: a draft
     * before it is threaded, and ingest, which threads its batch afterwards.
     * Reading the thread anyway would publish a conversation id of 0.
     */
    public function testAnEmailWithNoConversationAnnouncesOnlyItself(): void
    {
        $message = $this->message('threadless', withThread: true);

        $rows = $this->record(
            fn () => $this->changes->emailChanged($this->accountId(), (string) $message->id, true, null),
        );

        self::assertSame(['Email:created:' . $message->id], $rows);
    }

    /**
     * A destroyed message really goes away, unlike Email/set destroy which
     * moves it to Trash. The conversation does not: its row is left standing so
     * the id a client holds still resolves, which is why it is announced as an
     * update and not a second destroy.
     */
    public function testADestroyedEmailTakesItsIdWithItButNotItsConversations(): void
    {
        $message = $this->message('discarded', withThread: true);
        $thread  = $message->thread;

        $rows = $this->record(
            fn () => $this->changes->emailDestroyed($this->accountId(), (string) $message->id, $thread),
        );

        self::assertSame(
            [
                'Email:destroyed:' . $message->id,
                'Thread:updated:' . $thread->id,
            ],
            $rows,
        );
    }

    /** One row per conversation however many of its messages moved. */
    public function testABatchAnnouncesEachConversationOnce(): void
    {
        $thread = $this->thread();

        $rows = $this->record(
            fn () => $this->changes->threadsTouched(
                (int) $this->account->id,
                [(int) $thread->id, (int) $thread->id],
            ),
        );

        self::assertSame(['Thread:updated:' . $thread->id], $rows);
    }

    // ── labels ────────────────────────────────────────────────────────────

    /**
     * JMAP state is per account and a Mailbox id is a binding id, so renaming
     * one label is a Mailbox change on every account it reaches.
     */
    public function testALabelChangeIsOneMailboxChangePerAccountItIsBoundTo(): void
    {
        $second = $this->secondAccount();
        $label  = $this->labelResolver->systemLabel(LabelRole::Drafts, $this->account);
        $this->labelResolver->systemLabel(LabelRole::Drafts, $second);
        $this->em->flush();

        $rows = $this->record(fn () => $this->changes->labelChanged($label));

        self::assertSame($this->mailboxRowsFor($label, 'updated'), $rows);
        self::assertCount(2, $rows, 'a label bound to two accounts is two Mailboxes');
    }

    public function testDeletingALabelDestroysEveryBinding(): void
    {
        $label = $this->labelResolver->systemLabel(LabelRole::Drafts, $this->account);
        $this->em->flush();

        $rows = $this->record(fn () => $this->changes->labelDeleted($label));

        self::assertSame($this->mailboxRowsFor($label, 'destroyed'), $rows);
    }

    /** A label that reaches no account has no JMAP presence to announce. */
    public function testALabelWithNoBindingsAnnouncesNothing(): void
    {
        $label       = new Label();
        $label->usr  = $this->user;
        $label->name = 'Unbound';
        $this->em->persist($label);
        $this->em->flush();

        self::assertSame([], $this->record(fn () => $this->changes->labelChanged($label)));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The rows one event wrote, in order.
     *
     * @return list<string>
     */
    private function record(callable $event): array
    {
        $since = $this->latestSequence();

        $event();

        $this->em->flush();

        return $this->rowsSince($since);
    }

    /**
     * @return list<string>
     */
    private function mailboxRowsFor(Label $label, string $changeType): array
    {
        $expected = [];

        foreach ($label->bindings as $binding) {
            $expected[] = sprintf('Mailbox:%s:%d', $changeType, $binding->id);
        }

        return $expected;
    }

    private function accountId(): int
    {
        return (int) $this->account->id;
    }

    private function latestSequence(): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM jmap_change_log');
    }

    /**
     * Every account, not just the fixture's: a label change reaches all of
     * them, and filtering to one would hide exactly what that test is about.
     *
     * @return list<string> "objectType:changeType:entityId", in log order
     */
    private function rowsSince(int $sequence): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT object_type, change_type, entity_id FROM jmap_change_log
             WHERE sequence > ?
             ORDER BY sequence',
            [$sequence],
        );

        return array_map(
            static fn (array $row): string => sprintf(
                '%s:%s:%s',
                $row['object_type'],
                $row['change_type'],
                $row['entity_id'],
            ),
            $rows,
        );
    }

    private function message(string $slug, bool $withThread = false): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = 'Recorder fixture ' . $slug;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<recorder-%s-%s@example.test>', $slug, uniqid('', true));

        if (true === $withThread) {
            $message->thread = $this->thread();
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function thread(): MessageThread
    {
        $thread                  = new MessageThread();
        $thread->account         = $this->account;
        $thread->subject         = 'Recorder conversation';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->normalizedSubject = 'recorder conversation';

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function secondAccount(): Account
    {
        $account = $this->newAccount('recorder-second-' . uniqid('', true) . '@example.test');
        $this->em->flush();

        return $account;
    }

    private function seed(): void
    {
        $this->user            = new User();
        $this->user->email     = 'recorder-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Mail';
        $this->user->nameLast  = 'Recorder';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->account = $this->newAccount('recorder-fixture-' . uniqid('', true) . '@example.test');

        $this->mailbox                = new Mailbox();
        $this->mailbox->account       = $this->account;
        $this->mailbox->name          = 'INBOX';
        $this->mailbox->fullPath      = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();

        $this->user->addAccount($this->account);
    }

    private function newAccount(string $email): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = $email;
        $account->username       = $email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);

        return $account;
    }
}
