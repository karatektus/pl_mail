<?php

declare(strict_types=1);

namespace App\Tests\Repository\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two label reads whose whole value is in what they refuse to match.
 *
 * findOneChildByName() is the uniqueness check behind find-or-create, and a
 * null parent has to mean "at the root" rather than "any parent" — the version
 * that got that wrong would let a nested "Invoices" satisfy a request for a
 * top-level one, and the two would then be the same label forever.
 *
 * unlabelAccountMessagesAndThreads() is what destroying a JMAP Mailbox does. A
 * Label is user-scoped and can be materialized on several accounts, so this must
 * strip exactly one of them; if it stripped the label everywhere, deleting a
 * folder on one account would silently unfile mail on all the others.
 */
final class LabelRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelRepository $repository;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(LabelRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── find-or-create ───────────────────────────────────────────────────────

    public function testANullParentMeansTheRootAndNotAnyParent(): void
    {
        $work = $this->label('Work');
        $this->label('Invoices', parent: $work);

        self::assertNull(
            $this->repository->findOneChildByName($this->user, null, 'Invoices'),
            'a nested label must not satisfy a request for a top-level one',
        );
    }

    public function testAChildIsFoundUnderItsOwnParent(): void
    {
        $work     = $this->label('Work');
        $invoices = $this->label('Invoices', parent: $work);

        self::assertSame(
            $invoices,
            $this->repository->findOneChildByName($this->user, $work, 'Invoices'),
        );
    }

    public function testARootLabelIsFoundAtTheRoot(): void
    {
        $invoices = $this->label('Invoices');

        self::assertSame(
            $invoices,
            $this->repository->findOneChildByName($this->user, null, 'Invoices'),
        );
    }

    public function testAnotherUsersLabelIsNeverMatched(): void
    {
        $stranger = $this->seedUser();

        $label      = new Label();
        $label->usr = $stranger;
        $label->name = 'Invoices';
        $this->em->persist($label);
        $this->em->flush();

        self::assertNull($this->repository->findOneChildByName($this->user, null, 'Invoices'));
    }

    // ── the parent picker ────────────────────────────────────────────────────

    /** A system mailbox is not somewhere a user gets to file things under. */
    public function testTheParentPickerOffersNoSystemLabels(): void
    {
        $custom = $this->label('Work');
        $this->label('Inbox', role: LabelRole::Inbox);

        $offered = $this->repository->createParentChoiceQueryBuilder($this->user)->getQuery()->getResult();

        self::assertSame([$custom], $offered);
    }

    // ── un-materializing a mailbox ───────────────────────────────────────────

    public function testDetachingALabelLeavesTheOtherAccountsMailLabelled(): void
    {
        $other = $this->seedAccount($this->user, 'other@example.test');
        $label = $this->label('Shared');

        $mine   = $this->labelledMessage($this->account, $label);
        $theirs = $this->labelledMessage($other, $label);

        $this->repository->unlabelAccountMessagesAndThreads($this->account, $label);

        self::assertSame(0, $this->labelAssignments($mine));
        self::assertSame(1, $this->labelAssignments($theirs), "the other account's mail keeps the label");
    }

    public function testDetachingALabelAlsoClearsTheAccountsThreads(): void
    {
        $other = $this->seedAccount($this->user, 'other@example.test');
        $label = $this->label('Shared');

        $mine   = $this->labelledThread($this->account, $label);
        $theirs = $this->labelledThread($other, $label);

        $this->repository->unlabelAccountMessagesAndThreads($this->account, $label);

        self::assertSame(0, $this->threadLabelAssignments($mine));
        self::assertSame(1, $this->threadLabelAssignments($theirs));
    }

    /** The label row itself is not this method's business — only the filing is. */
    public function testTheLabelItselfSurvivesBeingDetached(): void
    {
        $label = $this->label('Shared');

        $this->repository->unlabelAccountMessagesAndThreads($this->account, $label);

        self::assertNotNull($this->repository->find($label->id));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function labelAssignments(Message $message): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_label WHERE message_id = :id',
            ['id' => $message->id],
        );
    }

    private function threadLabelAssignments(MessageThread $thread): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM thread_label WHERE message_thread_id = :id',
            ['id' => $thread->id],
        );
    }

    private function labelledMessage(Account $account, Label $label): Message
    {
        $message                 = new Message();
        $message->account        = $account;
        $message->subject        = 'Filed';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->addLabel($label);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function labelledThread(Account $account, Label $label): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Filed';
        $thread->normalizedSubject = 'filed';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->addLabel($label);

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function label(string $name, ?Label $parent = null, ?LabelRole $role = null): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->parent    = $parent;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user, string $email = 'labels@example.test'): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Labels fixture';
        $account->email          = $email;
        $account->username       = uniqid('labels-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'labels-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Labels';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
