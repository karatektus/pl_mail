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
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MoveToService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "Move to" takes off the label of the list it was started from — and only
 * that one.
 *
 * The whole subject is which label leaves, because that is the part the client
 * does not get to say. It names a view; the server derives the removal. Each
 * test below is one view type from the issue, stated as the label set a
 * message ends up with, since a move that arrived in the right place while
 * stripping a tag — or while leaving the conversation in the Inbox it was
 * moved out of — would pass any assertion about the target alone.
 *
 * Against a real container and a real database, for ThreadSnoozeServiceTest's
 * reasons: every collaborator is `final`, and what is worth pinning is what
 * the existing operations add up to when this service composes them. Leaving
 * the Inbox for a tag has to come out as an archive — Archive on — and that is
 * ThreadStatusUpdater's behaviour, observed here rather than restated.
 */
final class MoveToServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
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
        $this->moveTo        = $container->get(MoveToService::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
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

    // ── from the Inbox ───────────────────────────────────────────────────────

    /**
     * Label and archive, in one action. Archive ON is the half that is easy to
     * lose: plMail has no All Mail, so a conversation that left the Inbox and
     * gained nothing but a tag would sit in no folder-shaped list at all.
     */
    public function testFromTheInboxTheTargetGoesOnAndTheInboxComesOff(): void
    {
        $receipts = $this->tag('Receipts');
        $other    = $this->tag('Important');
        $message  = $this->inboxMessage($other);

        $this->moveFrom('inbox', '', $receipts, $message);

        self::assertTrue($message->hasLabel($receipts), 'the conversation did not arrive under the target');
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)), 'the conversation is still in the Inbox it was moved out of');
        self::assertTrue($message->hasLabel($this->role(LabelRole::Archive)), 'out of the Inbox without Archive is mail in no list');
        self::assertTrue($message->hasLabel($other), 'a move stripped a label that was neither the target nor the view');
    }

    /**
     * A thread is its messages and they are not all in the same place. The
     * reply that was sent is in Sent and was never in the Inbox, so leaving
     * the Inbox has nothing to say about it — it gains the target with the
     * rest of the conversation and is not archived.
     */
    public function testOnlyTheMessagesThatWereInTheInboxLeaveIt(): void
    {
        $receipts = $this->tag('Receipts');
        $sent     = $this->role(LabelRole::Sent);

        $thread   = $this->thread();
        $received = $this->messageIn($thread, $this->role(LabelRole::Inbox));
        $reply    = $this->messageIn($thread, $sent);

        $this->moveTo->move([$thread], $this->moveTo->plan($this->account->usr, $receipts, 'inbox', ''));

        self::assertTrue($received->hasLabel($this->role(LabelRole::Archive)));
        self::assertTrue($reply->hasLabel($receipts), 'the target belongs on the whole conversation');
        self::assertTrue($reply->hasLabel($sent));
        self::assertFalse(
            $reply->hasLabel($this->role(LabelRole::Archive)),
            'a sent reply was filed under Archive because the thread it is in left the Inbox',
        );
    }

    /**
     * Where the target is a real folder on the account, the mail goes INTO it
     * and Archive has nothing to do with it — the row has to name the folder
     * the mail is now in, or the next flag sync looks for it under a UID the
     * destination never issued.
     */
    public function testAFolderTargetReceivesTheMailRatherThanTheArchive(): void
    {
        $folder  = $this->seedMailbox('Receipts');
        $target  = $this->tag('Receipts');
        $this->labelResolver->bindMailbox($target, $folder);
        $this->labelResolver->bindMailbox($this->role(LabelRole::Inbox), $this->inboxMailbox);
        $this->em->flush();

        $message = $this->inboxMessage();

        $this->moveFrom('inbox', '', $target, $message);

        self::assertSame($folder, $message->mailbox);
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Archive)), 'mail filed in a folder was also archived');
    }

    // ── from a label ─────────────────────────────────────────────────────────

    /**
     * The swap. Everything else stays — including the Inbox, which is what
     * distinguishes this from the Inbox case: the view was the label, so the
     * label is what comes off.
     */
    public function testFromALabelThatLabelComesOffAndEveryOtherOneStays(): void
    {
        $viewed  = $this->tag('Todo');
        $target  = $this->tag('Done');
        $other   = $this->tag('Important');
        $message = $this->inboxMessage($viewed, $other);

        $this->moveFrom('label', (string) $viewed->id, $target, $message);

        self::assertTrue($message->hasLabel($target));
        self::assertFalse($message->hasLabel($viewed), 'the label of the view it was moved from is still on it');
        self::assertTrue($message->hasLabel($this->role(LabelRole::Inbox)), 'a move between labels took the conversation out of the Inbox');
        self::assertTrue($message->hasLabel($other));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Archive)));
    }

    /**
     * The view is named by the client, so it has to be checked like anything
     * else from there. A label that is somebody else's is not a view this
     * person can have been looking at: it resolves to "nowhere in particular",
     * and above all its id is never used to take a label off.
     */
    public function testALabelViewThatIsNotTheUsersIsNotALabelView(): void
    {
        $stranger = $this->foreignLabel();
        $target   = $this->tag('Receipts');
        // System labels are made lazily, so the user has no Inbox until
        // something asks for it — and the fallback this asserts is the Inbox.
        $inbox    = $this->role(LabelRole::Inbox);

        $move = $this->moveTo->plan($this->account->usr, $target, 'label', (string) $stranger->id);

        self::assertNotSame($stranger, $move->leaving);
        self::assertSame($inbox, $move->leaving, 'an unrecognised view should behave like any list with no label of its own');
    }

    // ── from a list with no label of its own ─────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function unlabelledViews(): iterable
    {
        yield 'search results'      => [''];
        yield 'starred'             => ['starred'];
        yield 'sent'                => ['sent'];
        yield 'an account’s folders' => ['*'];
    }

    #[DataProvider('unlabelledViews')]
    public function testFromAnUnlabelledViewTheInboxComesOffWhereItWasOn(string $scope): void
    {
        $target  = $this->tag('Receipts');
        $other   = $this->tag('Important');
        $message = $this->inboxMessage($other);

        $this->moveFrom($scope, '', $target, $message);

        self::assertTrue($message->hasLabel($target));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)));
        self::assertTrue($message->hasLabel($other));
    }

    /**
     * "If present" is the whole of this one. A conversation that was already
     * archived gains the target and loses nothing — there is no Inbox to take
     * off, and nothing else is the view's to remove.
     */
    #[DataProvider('unlabelledViews')]
    public function testFromAnUnlabelledViewMailOutsideTheInboxOnlyGainsTheTarget(string $scope): void
    {
        $target  = $this->tag('Receipts');
        $archive = $this->role(LabelRole::Archive);
        $other   = $this->tag('Important');

        $message = $this->messageIn($this->thread(), $archive, $other);

        $this->moveFrom($scope, '', $target, $message);

        self::assertSameLabels(
            [$archive, $other, $target],
            $message->labels->toArray(),
        );
    }

    // ── from the bin and from spam ───────────────────────────────────────────

    /** @return iterable<string, array{string, list<LabelRole>}> */
    public static function discardedViews(): iterable
    {
        yield 'the bin'                        => ['trash', [LabelRole::Trash]];
        yield 'spam'                           => ['spam', [LabelRole::Spam]];
        yield 'the bin, for spam then deleted' => ['trash', [LabelRole::Trash, LabelRole::Spam]];
    }

    /**
     * Out of the bin and under the label — and neither still in the bin nor
     * dropped into the inbox on the way. Both discarded labels go, whichever
     * list it was moved from: a deleted spam mail carries the two, and one
     * that came out still marked as spam would be filed away again.
     *
     * @param list<LabelRole> $carried
     */
    #[DataProvider('discardedViews')]
    public function testFromTheBinTheMailLeavesItForTheLabelAndNotForTheInbox(string $scope, array $carried): void
    {
        $target  = $this->tag('Receipts');
        $other   = $this->tag('Important');
        $message = $this->messageIn($this->thread(), $other, ...array_map($this->role(...), $carried));

        $this->moveFrom($scope, '', $target, $message);

        self::assertSameLabels(
            [$this->role(LabelRole::Archive), $other, $target],
            $message->labels->toArray(),
        );
        self::assertContains('restore', $this->dispatchedActions(), 'nothing told the mail server to take the message out of the bin');
    }

    // ── to the Inbox ─────────────────────────────────────────────────────────

    public function testMovingToTheInboxFromALabelTakesThatLabelOff(): void
    {
        $viewed  = $this->tag('Todo');
        $other   = $this->tag('Important');
        $inbox   = $this->role(LabelRole::Inbox);
        $message = $this->messageIn($this->thread(), $this->role(LabelRole::Archive), $viewed, $other);

        $this->moveFrom('label', (string) $viewed->id, $inbox, $message);

        self::assertTrue($message->hasLabel($inbox));
        self::assertFalse($message->hasLabel($viewed));
        self::assertTrue($message->hasLabel($other));
        self::assertFalse(
            $message->hasLabel($this->role(LabelRole::Archive)),
            'back in the inbox and still in the archive is a conversation in two places at once',
        );
    }

    /**
     * The one move that takes nothing of the user's away: the Inbox goes on,
     * and with no view label there is nothing else to remove.
     */
    public function testMovingToTheInboxFromAnUnlabelledViewRemovesNoneOfTheUsersLabels(): void
    {
        $tag     = $this->tag('Important');
        $inbox   = $this->role(LabelRole::Inbox);
        $message = $this->messageIn($this->thread(), $this->role(LabelRole::Archive), $tag);

        $this->moveFrom('', '', $inbox, $message);

        self::assertTrue($message->hasLabel($inbox));
        self::assertTrue($message->hasLabel($tag));
    }

    // ── to the bin and to spam ───────────────────────────────────────────────

    /**
     * Through the trash action, not a label change — and the evidence is the
     * job. A label swap would have queued nothing for a plain IMAP server; the
     * action queues `trash`, which is what moves the message into the bin
     * there. The view's label is left on, as it is when the Delete button is
     * pressed in that same view.
     */
    public function testMovingToTrashIsTheTrashAction(): void
    {
        $viewed  = $this->tag('Todo');
        $trash   = $this->role(LabelRole::Trash);
        $message = $this->inboxMessage($viewed);

        $this->moveFrom('label', (string) $viewed->id, $trash, $message);

        self::assertTrue($message->hasLabel($trash));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)));
        self::assertTrue($message->hasLabel($viewed), 'trashing does not strip the user’s labels, and moving to Trash is trashing');
        self::assertContains('trash', $this->dispatchedActions(), 'nothing told the mail server to bin the message');
    }

    public function testMovingToSpamTakesTheMailOutOfTheInbox(): void
    {
        $spam    = $this->role(LabelRole::Spam);
        $message = $this->inboxMessage();

        $this->moveFrom('inbox', '', $spam, $message);

        self::assertTrue($message->hasLabel($spam));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)));
        self::assertFalse(
            $message->hasLabel($this->role(LabelRole::Archive)),
            'junk was filed under Archive — moving to Spam went down the label path instead of the spam one',
        );
    }

    // ── nothing to do ────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function ownViews(): iterable
    {
        yield 'the Inbox, from the Inbox'  => ['inbox'];
        yield 'a label, from that label' => ['label'];
        yield 'the bin, from the bin'    => ['trash'];
        yield 'spam, from spam'          => ['spam'];
    }

    #[DataProvider('ownViews')]
    public function testMovingToTheViewItIsAlreadyInChangesNothing(string $scope): void
    {
        $viewed  = 'label' === $scope ? $this->tag('Todo') : $this->role(LabelRole::from($scope));
        $message = $this->messageIn($this->thread(), $viewed);
        $before  = $message->labels->toArray();

        $move = $this->moveTo->plan($this->account->usr, $viewed, $scope, (string) $viewed->id);

        self::assertTrue($move->isNoop);

        $this->moveTo->move([$message->thread], $move);

        self::assertSameLabels($before, $message->labels->toArray());
        self::assertSame([], $this->dispatchedActions(), 'a move that does nothing still told the mail server to do something');
    }

    // ── what may be moved to, and what stays on screen ───────────────────────

    /** @return iterable<string, array{LabelRole, bool}> */
    public static function roles(): iterable
    {
        yield 'Inbox'   => [LabelRole::Inbox, true];
        yield 'Spam'    => [LabelRole::Spam, true];
        yield 'Trash'   => [LabelRole::Trash, true];
        yield 'Archive' => [LabelRole::Archive, false];
        yield 'Sent'    => [LabelRole::Sent, false];
        yield 'Drafts'  => [LabelRole::Drafts, false];
        yield 'Snoozed' => [LabelRole::Snoozed, false];
    }

    #[DataProvider('roles')]
    public function testOnlyThePlacesThePickerOffersAreAccepted(LabelRole $role, bool $accepted): void
    {
        self::assertSame($accepted, $this->moveTo->accepts($this->role($role)));
    }

    /**
     * What decides whether the row is removed or redrawn. Moved out of the
     * Inbox it has left the list; filed from a search result it is still a
     * search result.
     */
    public function testAMovedConversationLeavesALabelViewAndStaysInAnUnlabelledOne(): void
    {
        $target  = $this->tag('Receipts');
        $message = $this->inboxMessage();
        $user    = $this->account->usr;

        $this->moveFrom('inbox', '', $target, $message);

        self::assertFalse($this->moveTo->staysInView($message->thread, $user, 'inbox', ''));
        self::assertTrue($this->moveTo->staysInView($message->thread, $user, 'starred', ''));
        self::assertTrue($this->moveTo->staysInView($message->thread, $user, 'label', (string) $target->id));
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function moveFrom(string $scope, string $value, Label $target, Message $message): void
    {
        $this->moveTo->move(
            [$message->thread],
            $this->moveTo->plan($this->account->usr, $target, $scope, $value),
        );
    }

    /**
     * The actions queued for a plain IMAP server.
     *
     * @return list<string>
     */
    private function dispatchedActions(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.export');

        $actions = [];

        foreach ($transport->getSent() as $envelope) {
            $job = $envelope->getMessage();

            if ($job instanceof ApplyImapFlagsMessage) {
                $actions[] = $job->action;
            }
        }

        return $actions;
    }

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

    /** A custom label with no folder — a tag the mail wears wherever it lives. */
    private function tag(string $name): Label
    {
        $label = $this->labelResolver->customChain([$name], $this->account);

        self::assertNotNull($label);
        $this->em->flush();

        return $label;
    }

    /** A label owned by a different user entirely. */
    private function foreignLabel(): Label
    {
        $user            = new User();
        $user->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Some';
        $user->nameLast  = 'Stranger';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $label       = new Label();
        $label->usr  = $user;
        $label->name = 'Theirs';
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    /** One message, alone in its thread, in the Inbox — plus whatever else. */
    private function inboxMessage(Label ...$labels): Message
    {
        return $this->messageIn($this->thread(), $this->role(LabelRole::Inbox), ...$labels);
    }

    private function messageIn(MessageThread $thread, Label ...$labels): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Move-to fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<move-to-%s@example.test>', uniqid('', true));
        // A mailbox and a UID, or the propagator treats the row as having no
        // address at a provider and queues nothing for it.
        $message->mailbox = $this->inboxMailbox;
        $message->imapUid = random_int(3000, 90000);

        foreach ($labels as $label) {
            $message->addLabel($label);
            $thread->addLabel($label);
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function thread(): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Move-to fixture';
        $thread->normalizedSubject = 'move-to fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'move-to-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Move';
        $user->nameLast  = 'To';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'move-to-fixture-' . uniqid('', true) . '@example.test';

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
