<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Method\Mail\EmailQueryMethod;
use App\Jmap\Method\Mail\ThreadGetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Gmail-style inbox categories, over JMAP.
 *
 * plMail has classified inbox mail for a long time and the web has had tabs for
 * it; JMAP could see none of it, so a native client could not draw the tabs at
 * all. The interesting part is not that the value is now published — it is
 * *which* value, and that the tab is a server-side filter.
 *
 * Two things could each have been done the obvious way and been wrong, and most
 * of what follows pins them:
 *
 * 1. **The filter is thread-scoped.** A newsletter somebody replied to has
 *    messages in two categories. Filtering `message.category` would put that one
 *    conversation in two tabs, where the web — which filters the thread — shows
 *    it in one.
 * 2. **The filter is on the server.** `Email/query` windows by position and
 *    limit, so a client that fetched a page and sieved it would show two rows in
 *    Promotions and no way to reach the rest.
 */
final class EmailCategoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private EmailQueryMethod $query;
    private EmailGetMethod $emailGet;
    private ThreadGetMethod $threadGet;

    private User $user;
    private Account $account;
    private Mailbox $mailbox;

    private int $uid = 9000;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->query         = $container->get(EmailQueryMethod::class);
        $this->emailGet      = $container->get(EmailGetMethod::class);
        $this->threadGet     = $container->get(ThreadGetMethod::class);

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

    // ── The value, published ──────────────────────────────────────────────

    public function testThreadGetReportsTheResolvedCategory(): void
    {
        $thread = $this->thread(MessageCategory::Promotions, [MessageCategory::Promotions]);

        $result = $this->threadGet->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $thread->id]],
            new JmapContext($this->user),
        );

        self::assertSame('promotions', $result['list'][0]['category']);
    }

    /**
     * Null is a state, not an absent key.
     *
     * A thread predating the classifier has no category, and a client patching
     * on the presence of the key would read that as "the server does not publish
     * categories" rather than as "this conversation has not been classified".
     */
    public function testAnUnclassifiedThreadReportsNull(): void
    {
        $thread = $this->thread(null, [null]);

        $result = $this->threadGet->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $thread->id]],
            new JmapContext($this->user),
        );

        self::assertArrayHasKey('category', $result['list'][0]);
        self::assertNull($result['list'][0]['category']);
    }

    /**
     * Email carries the raw signal, and it may disagree with its own thread.
     *
     * That disagreement is the ordinary result of most-recent-wins, not a bug,
     * and publishing the per-message value is what lets a client show why a
     * conversation is where it is instead of concluding the classifier misfired.
     */
    public function testEmailGetReportsThePerMessageCategory(): void
    {
        $thread = $this->thread(
            MessageCategory::Primary,
            [MessageCategory::Promotions, MessageCategory::Primary],
        );

        $result = $this->emailGet->handle(
            [
                'accountId' => $this->accountId(),
                'ids' => $this->emailIdsOf($thread),
                'properties' => ['category'],
            ],
            new JmapContext($this->user),
        );

        // Keyed by id rather than compared positionally: Email/get reads rows in
        // repository order and does not preserve the order it was asked in.
        $byId = [];

        foreach ($result['list'] as $email) {
            $byId[$email['id']] = $email['category'];
        }

        $expected = [];

        foreach ($thread->messages as $message) {
            $expected[(string) $message->getId()] = $message->getCategory()?->value;
        }

        self::assertSame(['promotions', 'primary'], array_values($expected), 'fixture sanity');

        ksort($expected);
        ksort($byId);

        self::assertSame($expected, $byId);
    }

    // ── The filter ────────────────────────────────────────────────────────

    public function testTheFilterSelectsOnlyThatCategory(): void
    {
        $promotions = $this->thread(MessageCategory::Promotions, [MessageCategory::Promotions]);
        $this->thread(MessageCategory::Primary, [MessageCategory::Primary]);
        $this->thread(MessageCategory::Social, [MessageCategory::Social]);

        $ids = $this->queryIds(['threadCategory' => 'promotions']);

        self::assertSame($this->emailIdsOf($promotions), $ids);
    }

    /**
     * The reason this filter lives on the server, as a test.
     *
     * Twelve conversations, three of them Promotions, asked for a page of five —
     * which is how a client pages. Filtering locally would have returned five
     * mixed rows containing at most one or two Promotions, under a `total` of
     * twelve. The list would look nearly empty while more existed further down,
     * and nothing on the client could tell the difference between that and a
     * genuinely quiet tab.
     */
    public function testAPageOfTheFilteredQueryIsAPageOfThatCategory(): void
    {
        foreach (range(1, 9) as $ignored) {
            $this->thread(MessageCategory::Primary, [MessageCategory::Primary]);
        }

        foreach (range(1, 3) as $ignored) {
            $this->thread(MessageCategory::Promotions, [MessageCategory::Promotions]);
        }

        $result = $this->handleQuery([
            'filter' => ['threadCategory' => 'promotions'],
            'collapseThreads' => true,
            'limit' => 5,
        ]);

        self::assertSame(3, $result['total'], 'total must count the filtered list, not the mailbox');
        self::assertCount(3, $result['ids']);
    }

    /**
     * A conversation lands in one tab, however its messages are classified.
     *
     * This is the whole reason the condition reads `message_thread.category`.
     * The thread here resolves to Primary — a human answered a newsletter — and
     * the Promotions tab must not contain it, even though one of its messages
     * carries `promotions`.
     */
    public function testAMixedThreadAppearsInOneTabOnly(): void
    {
        $mixed = $this->thread(
            MessageCategory::Primary,
            [MessageCategory::Promotions, MessageCategory::Primary],
        );

        self::assertSame([], $this->queryIds(['threadCategory' => 'promotions']));

        // And every one of its messages is in the tab it does belong to,
        // including the one classified promotions: the tab holds conversations.
        self::assertSame($this->emailIdsOf($mixed), $this->queryIds(['threadCategory' => 'primary']));
    }

    /**
     * An unclassified thread is in no tab, exactly as the web's own inbox query
     * has it.
     *
     * Folding null into Primary was the tempting alternative and is the wrong
     * one: the two surfaces have to contain the same conversations, and a phone
     * showing mail the browser's Primary tab does not is the failure this layer
     * is most careful about. `app:backfill category` is the fix for the data.
     */
    public function testAnUnclassifiedThreadIsInNoTab(): void
    {
        $this->thread(null, [null]);

        foreach (MessageCategory::cases() as $category) {
            self::assertSame(
                [],
                $this->queryIds(['threadCategory' => $category->value]),
                sprintf('an unclassified thread must not appear under "%s"', $category->value),
            );
        }
    }

    /**
     * The condition composes, because that is how a client sends it.
     *
     * Categories are an *inbox* idea; the client asks for "in this mailbox and
     * in this category" and the two conditions have to be orthogonal. A
     * threadCategory that implied the Inbox itself would be a second, hidden
     * definition of what the inbox is.
     */
    public function testTheFilterCombinesWithInMailbox(): void
    {
        $inbox = $this->thread(MessageCategory::Social, [MessageCategory::Social], inbox: true);
        $this->thread(MessageCategory::Social, [MessageCategory::Social], inbox: false);

        $binding = $this->labelResolver->binding(
            $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account),
            $this->account,
        );
        $this->em->flush();

        $ids = $this->queryIds([
            'operator' => 'AND',
            'conditions' => [
                ['inMailbox' => (string) $binding->id],
                ['threadCategory' => 'social'],
            ],
        ]);

        self::assertSame($this->emailIdsOf($inbox), $ids);
    }

    // ── The refusals ──────────────────────────────────────────────────────

    /**
     * Refused rather than ignored.
     *
     * A dropped condition returns too much mail, which a client cannot detect —
     * the Promotions tab would quietly become a second copy of the inbox. This
     * compiler raises on everything it does not understand for that reason, and
     * a value it does not understand is the same failure one level down.
     */
    public function testAnUnknownCategoryIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->queryIds(['threadCategory' => 'newsletters']);
    }

    /** A closed vocabulary the caller cannot discover is barely a vocabulary. */
    public function testTheRefusalNamesTheAcceptedValues(): void
    {
        try {
            $this->queryIds(['threadCategory' => 'newsletters']);
            self::fail('an unknown category must be refused');
        } catch (MethodException $exception) {
            $error = $exception->toError();

            self::assertSame('invalidArguments', $error['type']);
            self::assertStringContainsString('newsletters', $error['description']);
            self::assertStringContainsString('promotions', $error['description']);
            self::assertStringContainsString('forums', $error['description']);
        }
    }

    public function testANonStringCategoryIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->queryIds(['threadCategory' => ['promotions']]);
    }

    /**
     * The per-message value stays read-only.
     *
     * Offering it as a condition would hand clients the exact mistake this whole
     * design avoids, and a filter that is never sent is cheaper to refuse than
     * to explain.
     */
    public function testThePerMessageCategoryIsNotAFilterCondition(): void
    {
        $this->expectException(MethodException::class);

        $this->queryIds(['category' => 'promotions']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function accountId(): string
    {
        return (string) $this->account->getId();
    }

    /**
     * @param array<string,mixed> $filter
     *
     * @return list<string>
     */
    private function queryIds(array $filter): array
    {
        return $this->handleQuery(['filter' => $filter])['ids'];
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handleQuery(array $arguments): array
    {
        $this->em->flush();

        return $this->query->handle(
            $arguments + ['accountId' => $this->accountId()],
            new JmapContext($this->user),
        );
    }

    /**
     * The thread's messages as Email ids, newest first — the order Email/query
     * answers in, so a comparison against it needs no re-sorting.
     *
     * @return list<string>
     */
    private function emailIdsOf(MessageThread $thread): array
    {
        $ids = [];

        foreach ($thread->messages as $message) {
            $ids[] = (string) $message->getId();
        }

        return array_reverse($ids);
    }

    /**
     * A conversation with one message per entry in $messageCategories, oldest
     * first, and its own resolved category set explicitly.
     *
     * Set rather than derived on purpose: MessageThreader owns the
     * most-recent-wins rule and is tested where it lives. What is under test
     * here is what JMAP does with the resolved value, so the fixture states it
     * — including the case where it disagrees with every message, which is what
     * a stale thread looks like before a backfill.
     *
     * @param list<MessageCategory|null> $messageCategories
     */
    private function thread(
        ?MessageCategory $category,
        array $messageCategories,
        bool $inbox = true,
    ): MessageThread {
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Category fixture';
        $thread->normalizedSubject = 'category fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->category = $category;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $offset = count($messageCategories);

        foreach ($messageCategories as $messageCategory) {
            ++$this->uid;
            --$offset;

            $message = new Message();
            $message
                ->setAccount($this->account)
                ->setSubject('Category fixture')
                ->setFromAddress('sender@example.test')
                // Spaced so the ordering is total: Email/query sorts on
                // received_at and ties are broken by id, and a fixture whose
                // messages all share one second would compare equal to whatever
                // order the database happened to return.
                ->setReceivedAt(new \DateTimeImmutable(sprintf('-%d minutes', 10 + $offset)))
                ->setHasAttachments(false)
                ->setCategory($messageCategory)
                ->setMessageId(sprintf('<category-%d@example.test>', $this->uid))
                ->setMailbox($this->mailbox)
                ->setImapUid($this->uid);

            if (true === $inbox) {
                $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));
            }

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user
            ->setEmail('category-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Email')
            ->setNameLast('Category')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account
            ->setUsr($this->user)
            ->setEmail('Email Category')
            ->setUsername('category-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($this->account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $this->account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->mailbox->createdAt = new \DateTimeImmutable();
        $this->mailbox->updatedAt = new \DateTimeImmutable();
        $this->em->persist($this->mailbox);

        $this->em->flush();

        // getAccounts() is what AccountResolver scopes on, and the inverse side
        // is not populated by persisting the owning side alone.
        $this->user->addAccount($this->account);
    }
}
