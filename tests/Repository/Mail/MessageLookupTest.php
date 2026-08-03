<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\Mail\Account;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lookups sync and rules are built on.
 *
 * Two of these decide whether mail is duplicated or lost. Dedup keys on the RFC
 * Message-ID rather than the provider's own id, because Graph ids rotate when a
 * message moves — so a lookup that misses means the same message arrives twice,
 * and one that matches too eagerly means a real message is silently treated as
 * one already seen.
 *
 * The keyset walk is the other: "apply this rule to existing mail" pages
 * through a mailbox by id while its own writes change what matches, and an
 * OFFSET walk would skip rows as it went. Nothing about that is visible from
 * the outside — the run simply finishes having missed some.
 */
final class MessageLookupTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $repository;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageRepository::class);

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

    /**
     * The dedup key. Scoped to the account on purpose: the same message
     * genuinely exists in two mailboxes when somebody is on both ends of it,
     * and collapsing those would lose one.
     */
    public function testAMessageIsFoundByAnyOfItsMessageIdsWithinItsAccount(): void
    {
        $this->seedMessage(messageId: '<abc@example.test>');

        self::assertNotNull($this->repository->findOneByMessageIdsForAccount(
            ['<nope@example.test>', '<abc@example.test>'],
            $this->account,
        ));

        $other = $this->seedAccount($this->user);

        self::assertNull($this->repository->findOneByMessageIdsForAccount(
            ['<abc@example.test>'],
            $other,
        ));
    }

    public function testAnEmptyListMatchesNothingRatherThanTheFirstMessage(): void
    {
        $this->seedMessage(messageId: '<abc@example.test>');

        self::assertNull($this->repository->findOneByMessageIdsForAccount([], $this->account));
    }

    /**
     * Graph ids are locators, not identities, and the syncer asks "which of
     * these do I already have" before deciding what to fetch. A user's own
     * mail only — the question is asked per account but answered per user,
     * because a message can move between accounts' folders.
     */
    public function testSyncedProviderIdsAreListedPerUser(): void
    {
        $this->seedMessage(graphId: 'AAA');
        $this->seedMessage(graphId: 'BBB');
        $this->seedMessage();

        $ids = $this->repository->findSyncedGraphIdsForUser($this->user);

        sort($ids);

        self::assertSame(['AAA', 'BBB'], $ids);

        $stranger = $this->seedUser();
        self::assertSame([], $this->repository->findSyncedGraphIdsForUser($stranger));
    }

    /**
     * The walk "apply to existing mail" uses. Paging by id rather than by
     * offset is what makes it survive its own writes: rows it has already acted
     * on stop matching, and an OFFSET walk would then step over rows it never
     * looked at.
     */
    public function testTheKeysetWalkReturnsEveryMatchExactlyOnce(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->seedMessage(subject: 'walk me')->id;
        }

        $filter = new CompiledFilter('TRUE', []);
        $seen   = [];
        $after  = 0;

        while (true) {
            $page = $this->repository->findIdsMatchingForUser($this->user, $filter, $after, 2);

            if (0 === count($page)) {
                break;
            }

            self::assertLessThanOrEqual(2, count($page));

            $seen  = [...$seen, ...$page];
            $after = $page[count($page) - 1];
        }

        self::assertSame($ids, $seen);
    }

    /**
     * The count under the filter editor. Capped, because the question is "is
     * this filter roughly right" and an exact count over a large mailbox costs
     * a full scan to tell the author something they do not need.
     */
    public function testTheMatchCountIsCappedAndSaysSo(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->seedMessage();
        }

        $filter = new CompiledFilter('TRUE', []);

        $under = $this->repository->countMatchingForUser($this->user, $filter, 10);
        self::assertSame(4, $under['count']);
        self::assertFalse($under['capped']);

        $over = $this->repository->countMatchingForUser($this->user, $filter, 2);
        self::assertSame(2, $over['count']);
        self::assertTrue($over['capped']);
    }

    /** Scoped to the account when the rule is, and to the user always. */
    public function testTheMatchCountRespectsBothScopes(): void
    {
        $this->seedMessage();

        $second = $this->seedAccount($this->user);
        $this->seedMessage(account: $second);

        $stranger = $this->seedUser();
        $this->seedMessage(account: $this->seedAccount($stranger));

        $filter = new CompiledFilter('TRUE', []);

        self::assertSame(2, $this->repository->countMatchingForUser($this->user, $filter)['count']);
        self::assertSame(
            1,
            $this->repository->countMatchingForUser($this->user, $filter, 500, $second)['count'],
        );
    }

    /**
     * Hydration by id keeps the order it was given, because the caller has
     * already decided it — search hands over ids in relevance order, and
     * findBy() would quietly return them in whatever order the database liked.
     */
    public function testMessagesComeBackInArrivalOrderWhenAskedForIt(): void
    {
        $older = $this->seedMessage(receivedAt: '-2 hours');
        $newer = $this->seedMessage(receivedAt: '-1 hour');

        $ids = [(int) $newer->id, (int) $older->id];

        $arrival = $this->repository->findByIdsInArrivalOrder($ids);

        self::assertSame(
            [(int) $older->id, (int) $newer->id],
            array_map(static fn (Message $m): int => (int) $m->id, $arrival),
        );
    }

    public function testAskingForNoIdsIsNotAskingForEverything(): void
    {
        $this->seedMessage();

        self::assertSame([], $this->repository->findByIds([]));
        self::assertSame([], $this->repository->findByIdsInArrivalOrder([]));
    }

    /**
     * Filtering a set of candidate ids, which is how a rule is evaluated
     * against a freshly synced batch: the ids bound the work, the filter
     * decides. An empty candidate list must not mean "the whole mailbox".
     */
    public function testMatchingIdsNarrowsTheCandidatesRatherThanReplacingThem(): void
    {
        $one = (int) $this->seedMessage(subject: 'invoice')->id;
        $two = (int) $this->seedMessage(subject: 'invoice')->id;

        $filter = new CompiledFilter('TRUE', []);

        self::assertSame([$one], $this->repository->matchingIds([$one], $filter));
        self::assertSame([], $this->repository->matchingIds([], $filter));

        $none = new CompiledFilter('FALSE', []);
        self::assertSame([], $this->repository->matchingIds([$one, $two], $none));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function seedMessage(
        ?string $messageId = null,
        ?string $graphId = null,
        string $subject = 'Fixture',
        string $receivedAt = '-1 hour',
        ?Account $account = null,
    ): Message {
        $account ??= $this->account;

        // Threaded, because the dedup lookup joins through the thread to reach
        // the account — an unthreaded message is invisible to it, and every
        // message the sync asks about has been threaded by then.
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($receivedAt);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->messageId      = $messageId;
        $message->graphId        = $graphId;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Lookup fixture';
        $account->email          = 'lookup@example.test';
        $account->username       = uniqid('lookup-', true);
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
        $user->email     = 'lookup-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Lookup';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
