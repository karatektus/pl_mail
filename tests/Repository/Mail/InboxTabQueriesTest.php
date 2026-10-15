<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every question the tab strip asks, answered for a reader who has a tab of
 * their own.
 *
 * ONE FIXTURE, CHOSEN TO BE THE AWKWARD CASE. A carrier's notice has the
 * built-in category Promotions AND sits in the reader's Shipping tab; beside it
 * is a genuine promotion whose built-in category is also Promotions and which is
 * in no tab of the reader's. A query that read only the built-in column would
 * put both in Promotions and leave Shipping empty; one that read only the
 * reader's would drop every ordinary promotion. A thread is in exactly one tab,
 * and which one is "the reader's, if it has one".
 *
 * The built-in tabs are asserted by name as well as the new one, so a refactor
 * that made Shipping work by breaking Promotions cannot pass.
 *
 * Hand-derived figures, from the fixture below:
 *
 *   thread       built-in    reader's tab   unread   new
 *   carrierNew   promotions  Shipping       yes      yes
 *   promoNew     promotions  -              yes      yes
 *   primaryRead  primary     -              no       no
 *   updateRead   updates     Shipping       no       no
 *
 *   Shipping = {carrierNew, updateRead}     -> 2 threads, 1 unread, 1 new
 *   Promotions = {promoNew}                  -> 1 thread, 1 unread, 1 new
 *   Primary = {primaryRead}                  -> 1 thread, 0 unread, 0 new
 *   Updates = {}                             -> updateRead left it for Shipping
 */
final class InboxTabQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private User $user;
    private Account $account;
    private Label $inbox;
    private InboxCategory $shipping;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
        $this->inbox   = $this->seedInboxLabel();

        $this->shipping       = new InboxCategory();
        $this->shipping->usr  = $this->user;
        $this->shipping->name = 'Shipping';
        $this->em->persist($this->shipping);
        $this->em->flush();

        $this->inboxThread('carrier, new', MessageCategory::Promotions, $this->shipping, unread: 1);
        $this->inboxThread('promo, new', MessageCategory::Promotions, null, unread: 1);
        $this->inboxThread('primary, read', MessageCategory::Primary, null, unread: 0);
        $this->inboxThread('update, read', MessageCategory::Updates, $this->shipping, unread: 0);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheReadersOwnTabHoldsWhatWasFiledInItWhateverItsBuiltInCategory(): void
    {
        $shipping = InboxTab::custom((int) $this->shipping->id, 'Shipping');

        self::assertSame(
            ['carrier, new', 'update, read'],
            $this->subjects($this->repository->findForUnifiedInbox($this->user, $shipping)),
        );
        self::assertSame(2, $this->repository->countForUnifiedInbox($this->user, $shipping));
    }

    public function testABuiltInTabNoLongerHoldsWhatLeftItForTheReadersOwn(): void
    {
        $promotions = InboxTab::builtIn(MessageCategory::Promotions);

        self::assertSame(['promo, new'], $this->subjects($this->repository->findForUnifiedInbox($this->user, $promotions)));
        self::assertSame(1, $this->repository->countForUnifiedInbox($this->user, $promotions));

        self::assertSame(
            ['primary, read'],
            $this->subjects($this->repository->findForUnifiedInbox($this->user, InboxTab::builtIn(MessageCategory::Primary))),
        );
        self::assertSame(
            [],
            $this->subjects($this->repository->findForUnifiedInbox($this->user, InboxTab::builtIn(MessageCategory::Updates))),
            'updateRead is in Shipping, so Updates is empty',
        );
    }

    /**
     * Every existing caller passes the enum, and every one of them must keep
     * getting what it always got — narrowed now, since a thread in the reader's
     * tab is not in the built-in one.
     */
    public function testTheEnumStillWorksAsATabArgument(): void
    {
        self::assertSame(
            ['promo, new'],
            $this->subjects($this->repository->findForUnifiedInbox($this->user, MessageCategory::Promotions)),
        );
        self::assertSame(1, $this->repository->countForUnifiedInbox($this->user, MessageCategory::Promotions));
    }

    public function testNoTabAtAllIsTheWholeInbox(): void
    {
        self::assertSame(4, $this->repository->countForUnifiedInbox($this->user, null));
    }

    public function testTheGroupedCountsKeyTheReadersTabAndAddItsThreadsUpAcrossBuiltIns(): void
    {
        $key = 'c' . $this->shipping->id;

        // carrierNew (promotions) and updateRead (updates) are one tab's two
        // threads: the figure is 2, not a last-row-wins 1.
        self::assertEquals(
            [$key => 2, 'promotions' => 1, 'primary' => 1],
            $this->sorted($this->repository->countByCategoryForUnifiedInbox($this->user)),
        );
        self::assertEquals(
            [$key => 1, 'promotions' => 1],
            $this->sorted($this->repository->countUnreadByCategoryForUnifiedInbox($this->user)),
        );
        self::assertEquals(
            [$key => 1, 'promotions' => 1],
            $this->sorted($this->repository->countNewByCategoryForUnifiedInbox($this->user, new DateTimeImmutable())),
        );
    }

    public function testTheSendersHintNamesWhoWroteIntoTheReadersTab(): void
    {
        $senders = $this->repository->newSendersByCategoryForUnifiedInbox($this->user, new DateTimeImmutable());

        self::assertSame(['Carrier Co'], $senders['c' . $this->shipping->id] ?? null);
        self::assertSame(['Promo Co'], $senders['promotions'] ?? null);
    }

    /**
     * Promotions switched off: its conversation is listed and counted under
     * Primary — until the re-sort that the switch queues has moved it, and for
     * good when somebody had pinned it there. The one filed in the reader's own
     * tab stays where it is; switching a built-in off says nothing about that.
     */
    public function testASwitchedOffCategoryIsListedAndCountedUnderPrimary(): void
    {
        $this->user->categorySorting->switchOff([MessageCategory::Promotions]);

        $primary = InboxTab::builtIn(MessageCategory::Primary);

        self::assertSame(
            ['primary, read', 'promo, new'],
            $this->subjects($this->repository->findForUnifiedInbox($this->user, $primary)),
        );
        self::assertSame(2, $this->repository->countForUnifiedInbox($this->user, $primary));

        $key = 'c' . $this->shipping->id;

        self::assertEquals(
            [$key => 2, 'primary' => 2],
            $this->sorted($this->repository->countByCategoryForUnifiedInbox($this->user)),
        );
        self::assertEquals(
            [$key => 1, 'primary' => 1],
            $this->sorted($this->repository->countUnreadByCategoryForUnifiedInbox($this->user)),
        );
        self::assertSame(['Promo Co'], $this->repository->newSendersByCategoryForUnifiedInbox($this->user, new DateTimeImmutable())['primary'] ?? null);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,int> $counts
     *
     * @return array<string,int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<MessageThread> $threads
     *
     * @return list<string>
     */
    private function subjects(array $threads): array
    {
        $subjects = array_map(static fn (MessageThread $thread): string => (string) $thread->subject, $threads);
        sort($subjects);

        return $subjects;
    }

    private function inboxThread(string $subject, MessageCategory $builtIn, ?InboxCategory $own, int $unread): MessageThread
    {
        $at = new DateTimeImmutable('-1 hour');

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $at;
        $thread->category          = $builtIn;
        $thread->userCategory      = $own;
        $thread->unreadCount       = $unread;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = str_contains($subject, 'carrier') ? 'Carrier Co' : 'Promo Co';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->category       = $builtIn;
        $message->addLabel($this->inbox);
        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        self::getContainer()->get(ThreadLabelSynchronizer::class)->sync($thread);
        $this->em->flush();

        return $thread;
    }

    private function seedInboxLabel(): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = 'Inbox';
        $label->role      = LabelRole::Inbox;
        $label->isVisible = true;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Tab fixture';
        $account->email          = 'tabs@example.test';
        $account->username       = uniqid('tabs-', true);
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
        $user->email     = 'tabs-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Tabs';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
