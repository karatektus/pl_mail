<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL that resolves a conversation's tab from its messages must carry the
 * reader's own tabs the way it carries the built-in category.
 *
 * Two statements do this — one for a handful of threads, one for a whole
 * account — and they are separate hand-written UPDATEs, so a rule added to one
 * and forgotten in the other is the likely mistake. Every case runs against
 * both.
 *
 * The rule is most-recent-wins over the messages, pinned threads excluded, and
 * its two failure modes are opposite and both silent: a stale verdict kept when
 * the newest message was not filed anywhere (a conversation stuck in a tab it
 * has left), and a pinned conversation un-pinned by a backfill (a drag undone
 * for the whole installation at once).
 */
final class ThreadUserCategoryResolutionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothStatements(): iterable
    {
        yield 'a handful of threads' => ['threads'];
        yield 'a whole account'      => ['account'];
    }

    /**
     * Fixture, derived by hand:
     *   - "left"   older message filed under Shipping, NEWEST message filed
     *              nowhere  → the conversation has left the tab: null.
     *   - "joined" older message filed nowhere, NEWEST filed under Shipping
     *              → it is in the tab: Shipping.
     * Both, because "always null" and "always the newest message's verdict"
     * would each pass one of them.
     */
    #[DataProvider('bothStatements')]
    public function testTheNewestMessageDecidesWhetherAThreadIsInTheReadersTab(string $which): void
    {
        $shipping = $this->category('Shipping');

        $left = $this->thread('left');
        $this->message($left, '2026-03-01 09:00', MessageCategory::Primary, $shipping);
        $this->message($left, '2026-03-02 09:00', MessageCategory::Promotions, null);

        $joined = $this->thread('joined');
        $this->message($joined, '2026-03-01 09:00', MessageCategory::Primary, null);
        $this->message($joined, '2026-03-02 09:00', MessageCategory::Promotions, $shipping);

        // Start both from the opposite of the expected answer, so a statement
        // that wrote nothing cannot pass by leaving the fixture alone.
        $left->userCategory   = $shipping;
        $joined->userCategory = null;
        $this->em->flush();

        $this->resolve($which, [$left, $joined]);

        self::assertNull($this->userCategoryOf($left), 'a conversation whose newest message is not filed is still in the tab');
        self::assertSame($shipping->id, $this->userCategoryOf($joined), 'a conversation whose newest message is filed is not in the tab');
        self::assertSame(MessageCategory::Promotions->value, $this->builtInOf($joined), 'the built-in category stopped following the newest message');
    }

    /**
     * Fixture: a thread pinned into Shipping whose newest message is filed
     * nowhere and is Promotions. Both columns must stay exactly as the person
     * left them. Its unpinned twin beside it is resolved, so "nothing was
     * touched at all" cannot pass for "the pin was honoured".
     */
    #[DataProvider('bothStatements')]
    public function testAPinnedThreadIsLeftWhereItWas(string $which): void
    {
        $shipping = $this->category('Shipping');

        $pinned = $this->thread('pinned');
        $this->message($pinned, '2026-03-02 09:00', MessageCategory::Promotions, null);
        $pinned->userCategory     = $shipping;
        $pinned->category         = MessageCategory::Primary;
        $pinned->categoryPinnedAt = new DateTimeImmutable('2026-03-03 09:00');

        $unpinned = $this->thread('unpinned');
        $this->message($unpinned, '2026-03-02 09:00', MessageCategory::Promotions, null);
        $unpinned->userCategory = $shipping;
        $this->em->flush();

        $this->resolve($which, [$pinned, $unpinned]);

        self::assertSame($shipping->id, $this->userCategoryOf($pinned), 'a backfill un-pinned a thread the person had put in a tab');
        self::assertSame(MessageCategory::Primary->value, $this->builtInOf($pinned));
        self::assertNull($this->userCategoryOf($unpinned), 'the unpinned twin was not resolved');
    }

    /**
     * A verdict on Gmail-labelled mail counts like any other, with Google's
     * categories left in charge of everything else: the reader wrote the
     * filter. Starts from no tab, so "never copies it" cannot pass.
     */
    #[DataProvider('bothStatements')]
    public function testAVerdictOnGmailLabelledMailCounts(string $which): void
    {
        $shipping = $this->category('Shipping');

        $thread = $this->thread('gmail notice');
        $message = $this->message($thread, '2026-03-02 09:00', MessageCategory::Promotions, $shipping);
        $message->gmailLabelIds = ['INBOX', 'CATEGORY_PROMOTIONS'];
        $thread->userCategory   = null;
        $this->em->flush();

        self::assertFalse($this->account->usr->categorySorting->overrideProvider);

        $this->resolve($which, [$thread]);
        self::assertSame($shipping->id, $this->userCategoryOf($thread), 'a verdict on Gmail mail was dropped');
    }

    /**
     * The per-account statement resolves this account's conversations and
     * leaves another account's alone, now that it reads only this account's
     * messages.
     */
    public function testThePerAccountStatementLeavesOtherAccountsAlone(): void
    {
        $shipping = $this->category('Shipping');

        $other           = new \App\Entity\Mail\Account();
        $other->usr      = $this->account->usr;
        $other->name     = 'Other';
        $other->email    = 'other@example.test';
        $other->username = uniqid('other-', true);
        $other->authType = \App\Domain\Enum\Account\AuthType::Password->value;
        $other->imapHost = 'imap.example.test';
        $other->isActive = true;
        $this->em->persist($other);

        $mine = $this->thread('mine');
        $this->message($mine, '2026-03-02 09:00', MessageCategory::Updates, $shipping);

        $theirs = $this->thread('theirs');
        $theirs->account = $other;
        $theirMessage = $this->message($theirs, '2026-03-02 09:00', MessageCategory::Updates, $shipping);
        $theirMessage->account = $other;
        $this->em->flush();

        $this->repository->recomputeCategoriesForAccount($this->account);

        self::assertSame($shipping->id, $this->userCategoryOf($mine));
        self::assertNull($this->userCategoryOf($theirs), 'another account\'s conversation was resolved too');
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @param list<MessageThread> $threads */
    private function resolve(string $which, array $threads): void
    {
        if ('threads' === $which) {
            $this->repository->recomputeCategoriesForThreads(array_map(
                static fn (MessageThread $thread): int => (int) $thread->id,
                $threads,
            ));

            return;
        }

        $this->repository->recomputeCategoriesForAccount($this->account);
    }

    private function userCategoryOf(MessageThread $thread): ?int
    {
        $value = $this->connection->fetchOne('SELECT user_category_id FROM message_thread WHERE id = :id', ['id' => $thread->id]);

        return false === $value || null === $value ? null : (int) $value;
    }

    private function builtInOf(MessageThread $thread): ?string
    {
        $value = $this->connection->fetchOne('SELECT category FROM message_thread WHERE id = :id', ['id' => $thread->id]);

        return false === $value ? null : $value;
    }

    private function category(string $name): InboxCategory
    {
        $category       = new InboxCategory();
        $category->usr  = $this->account->usr;
        $category->name = $name;
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function thread(string $subject): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-03-02 09:00');

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function message(
        MessageThread $thread,
        string $receivedAt,
        MessageCategory $category,
        ?InboxCategory $filedUnder,
    ): Message {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $thread->subject;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->category       = $category;
        $message->ruleCategory   = $filedUnder;

        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'usercat-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'User';
        $user->nameLast  = 'Category';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'User category fixture';
        $account->email          = 'usercat-fixture@example.test';
        $account->username       = uniqid('usercat-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
