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
use App\Service\Mail\MoveToService;
use App\Service\Mail\StatusUndoService;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Undo puts back the labels each message had, not the inverse of the action.
 *
 * The two are the same for one conversation and different for a selection,
 * which is where an undo built from inverses goes wrong without anybody
 * noticing: "un-archive these five" is right for the four that were in the
 * Inbox and wrong for the one that was already archived and happened to be
 * ticked. So every test here asserts the whole label set against the one that
 * was there before, rather than checking that the action's own label is gone.
 *
 * Real container and database, as in MoveToServiceTest and for its reasons.
 * The session is the one stand-in: the service remembers in the session of the
 * person who acted, and a kernel test has no request to carry one.
 */
final class StatusUndoServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private StatusUndoService $undo;
    private ThreadStatusUpdater $status;
    private MoveToService $moveTo;
    private LabelResolver $labelResolver;

    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->undo          = $container->get(StatusUndoService::class);
        $this->status        = $container->get(ThreadStatusUpdater::class);
        $this->moveTo        = $container->get(MoveToService::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->inboxMailbox = $this->seedMailbox('INBOX');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUndoingAnArchiveReturnsTheConversationToTheInbox(): void
    {
        $tag     = $this->tag('Important');
        $message = $this->message($this->role(LabelRole::Inbox), $tag);
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->status->archive([$message]);

        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)), 'the fixture never left the Inbox');

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray());
        self::assertSame($this->inboxMailbox, $message->mailbox, 'the labels came back and the row still points at the archive folder');
    }

    /**
     * The case an inverse gets wrong. One of the two was already archived when
     * the selection was archived; undoing must leave it archived, not drag it
     * into an inbox it was never in.
     */
    public function testUndoingABulkArchiveLeavesAlreadyArchivedMailWhereItWas(): void
    {
        $fromInbox    = $this->message($this->role(LabelRole::Inbox));
        $wasArchived  = $this->message($this->role(LabelRole::Archive));
        $archivedSet  = $wasArchived->labels->toArray();

        $token = $this->undo->remember([$fromInbox, $wasArchived]);
        $this->status->archive([$fromInbox, $wasArchived]);

        $this->undo->restore($token, $this->account->usr);

        self::assertTrue($fromInbox->hasLabel($this->role(LabelRole::Inbox)));
        self::assertSameLabels(
            $archivedSet,
            $wasArchived->labels->toArray(),
            'undo moved a conversation into the inbox that was not in it before the action',
        );
    }

    public function testUndoingAMoveToRestoresTheExactLabelSet(): void
    {
        $target  = $this->tag('Receipts');
        $tag     = $this->tag('Important');
        $message = $this->message($this->role(LabelRole::Inbox), $tag);
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $target, 'inbox', ''),
        );

        self::assertTrue($message->hasLabel($target), 'the fixture was never moved');

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels(
            $before,
            $message->labels->toArray(),
            'undo left the target on, or left Archive behind from the archive the move performed',
        );
        self::assertSameLabels($before, $message->thread->labels->toArray(), 'the thread’s labels are what the list reads, and they did not follow');
    }

    public function testUndoingALabelSwapPutsTheOldLabelBack(): void
    {
        $viewed  = $this->tag('Todo');
        $target  = $this->tag('Done');
        $message = $this->message($this->role(LabelRole::Inbox), $viewed);
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $target, 'label', (string) $viewed->id),
        );

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray());
    }

    /**
     * What the final, database-only step is for. A conversation that wore
     * nothing but a custom label — ordinary on Gmail, where that is what
     * "archived and labelled" looks like — has no place to be moved back to.
     * Taking it out of the inbox again goes through archive(), which is what
     * tells the provider, and archive() attaches Archive. The message never
     * had it.
     */
    public function testUndoingAMoveToTheInboxLeavesNoArchiveLabelBehind(): void
    {
        $viewed  = $this->tag('Todo');
        $message = $this->message($viewed);
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $this->role(LabelRole::Inbox), 'label', (string) $viewed->id),
        );

        self::assertTrue($message->hasLabel($this->role(LabelRole::Inbox)), 'the fixture was never moved');

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray(), 'undo left behind a label the message did not have before');
    }

    public function testUndoingAMoveToTrashTakesTheMailBackOutOfTheBin(): void
    {
        $message = $this->message($this->role(LabelRole::Inbox));
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $this->role(LabelRole::Trash), 'inbox', ''),
        );

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray());
    }

    /**
     * The longest way round there is — out of the bin, into the inbox, out of
     * the inbox again — and so the one most likely to come back with a label
     * it picked up on the way.
     */
    public function testUndoingAMoveOutOfTheBinPutsTheMailBackInIt(): void
    {
        $target  = $this->tag('Receipts');
        $message = $this->message($this->role(LabelRole::Trash));
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $target, 'trash', ''),
        );

        self::assertFalse($message->hasLabel($this->role(LabelRole::Trash)), 'the fixture never left the bin');

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray());
    }

    public function testUndoingALabelTakesItOffAgain(): void
    {
        $label   = $this->tag('Receipts');
        $message = $this->message($this->role(LabelRole::Inbox));
        $before  = $message->labels->toArray();

        $token = $this->undo->remember([$message]);
        $this->status->applyLabel([$message], $label, true);

        $this->undo->restore($token, $this->account->usr);

        self::assertSameLabels($before, $message->labels->toArray());
    }

    /**
     * Single use, so a double click cannot run it twice and a stale toast
     * cannot re-apply an old state over whatever has happened since.
     */
    public function testATokenWorksOnceAndAnUnknownOneNotAtAll(): void
    {
        $message = $this->message($this->role(LabelRole::Inbox));

        $token = $this->undo->remember([$message]);
        $this->status->archive([$message]);

        self::assertSame(1, $this->undo->restore($token, $this->account->usr));
        self::assertNull($this->undo->restore($token, $this->account->usr), 'the same undo ran twice');
        self::assertNull($this->undo->restore(str_repeat('0', 32), $this->account->usr));
    }

    /**
     * The snapshot lives in the actor's own session, so this cannot arise in
     * the browser. It is checked because it is the last thing before a write.
     */
    public function testASnapshotIsNotAppliedForAnotherUser(): void
    {
        $message = $this->message($this->role(LabelRole::Inbox));

        $token = $this->undo->remember([$message]);
        $this->status->archive([$message]);

        $stranger            = new User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Some';
        $stranger->nameLast  = 'Stranger';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);
        $this->em->flush();

        self::assertSame(0, $this->undo->restore($token, $stranger));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)));
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * Compared by id, sorted. A Doctrine collection keeps the keys its
     * elements were added under, so a set that lost and regained a label is
     * the same set under different keys — which assertEquals reads as a
     * different array.
     *
     * @param array<array-key, Label> $expected
     * @param array<array-key, Label> $actual
     */
    private static function assertSameLabels(array $expected, array $actual, string $message = ''): void
    {
        $names = static function (array $labels): array {
            $names = array_map(static fn (Label $label): string => sprintf('%s#%d', $label->name, $label->id), $labels);
            sort($names);

            return $names;
        };

        self::assertSame($names($expected), $names($actual), $message);
    }

    private function role(LabelRole $role): Label
    {
        return $this->labelResolver->systemLabel($role, $this->account);
    }

    private function tag(string $name): Label
    {
        $label = $this->labelResolver->customChain([$name], $this->account);

        self::assertNotNull($label);
        $this->em->flush();

        return $label;
    }

    /** One message, alone in a thread of its own, carrying these labels. */
    private function message(Label ...$labels): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Undo fixture';
        $thread->normalizedSubject = 'undo fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Undo fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<undo-%s@example.test>', uniqid('', true));
        $message->mailbox        = $this->inboxMailbox;
        $message->imapUid        = random_int(3000, 90000);

        foreach ($labels as $label) {
            $message->addLabel($label);
            $thread->addLabel($label);
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'undo-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Status';
        $user->nameLast  = 'Undo';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'undo-fixture-' . uniqid('', true) . '@example.test';

        $account                 = new Account();
        $account->usr            = $user;
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
        $this->em->flush();

        return $account;
    }

    private function seedMailbox(string $name): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
