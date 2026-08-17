<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Method\Mail\ThreadSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Thread/set" is a plMail extension, so its shape is ours to get right.
 *
 * RFC 8621's Thread is read-only. This adds exactly two settable properties,
 * and the tests below are mostly about what it refuses: a method that accepted
 * `create`, or silently ignored an unknown property, would be inventing
 * semantics the rest of the system has no meaning for.
 *
 * The snooze behaviour itself lives in ThreadSnoozeServiceTest — here the
 * question is only whether the method routes to it.
 *
 * `isNew` is the newer half and the more delicate one, because it is the only
 * way a non-browser client can retire a New marker. Every case below is about
 * the marker being *evidence* rather than a preference: it goes one way, a
 * repeat does not move it, and it must actually reach the database — the
 * property is assigned nowhere, so a test that only inspected the entity in
 * memory would pass against a method that persisted nothing.
 */
final class ThreadSetMethodTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private ThreadSetMethod $method;

    private User $user;
    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->method        = $container->get(ThreadSetMethod::class);

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

    public function testItIsNamedThreadSet(): void
    {
        self::assertSame('Thread/set', $this->method->name());
    }

    public function testSettingSnoozedUntilSnoozesTheThread(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle([
            'update' => [
                (string) $thread->id => [
                    'snoozedUntil' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['updated']);
        self::assertNotNull($thread->snoozedUntil);
        self::assertNotContains(LabelRole::Inbox, $this->rolesOn($thread));
    }

    public function testNullClearsTheSnooze(): void
    {
        $thread = $this->inboxThread();
        $id     = (string) $thread->id;

        $this->handle([
            'update' => [$id => ['snoozedUntil' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s\Z')]],
        ]);

        $this->handle(['update' => [$id => ['snoozedUntil' => null]]]);

        self::assertNull($thread->snoozedUntil);
        self::assertContains(LabelRole::Inbox, $this->rolesOn($thread));
    }

    /**
     * Threads come into being by mail arriving. A client that could conjure one
     * would be describing something nothing else in the system understands.
     */
    public function testCreateIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['create' => ['tmp' => ['snoozedUntil' => null]]]);
    }

    public function testDestroyIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['destroy' => ['1']]);
    }

    /**
     * Refused rather than ignored: a client told its write succeeded, when the
     * property was silently dropped, has no way to discover otherwise.
     */
    public function testAnUnknownPropertyIsRejectedForThatThread(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle([
            'update' => [(string) $thread->id => ['subject' => 'nope']],
        ]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['notUpdated']);
        self::assertCount(0, (array) $result['updated']);
    }

    /**
     * The web endpoint substitutes "in 1 day" on an unparseable date, which is
     * defensible for a form post. For an API it is not: the client would be
     * told it succeeded and find the thread back at a time it never chose.
     */
    public function testAMalformedDateIsRefusedRatherThanCoerced(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle([
            'update' => [(string) $thread->id => ['snoozedUntil' => 'next tuesday-ish']],
        ]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['notUpdated']);
        self::assertNull($thread->snoozedUntil);
    }

    /** Scoped by account, so guessing an integer reaches nothing. */
    public function testAThreadOutsideTheAccountIsNotFound(): void
    {
        $result = $this->handle(['update' => ['999999' => ['snoozedUntil' => null]]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    /** An empty patch changes nothing, and must not be an error. */
    public function testAnEmptyPatchIsANoOp(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle(['update' => [(string) $thread->id => []]]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['updated']);
        self::assertNull($thread->snoozedUntil);
    }

    // ── The New marker ────────────────────────────────────────────────────

    /**
     * **The change, in one assertion.** A JMAP client can now say "I have shown
     * this", which is what stops a phone-triaged mailbox opening in the browser
     * with every conversation from the last day still badged.
     */
    public function testSettingIsNewToFalseRetiresTheMarker(): void
    {
        $thread = $this->inboxThread();

        self::assertTrue($thread->isNewAt(new \DateTimeImmutable()));

        $result = $this->handle(['update' => [(string) $thread->id => ['isNew' => false]]]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['updated']);
        self::assertNotNull($this->storedListedAt($thread));
    }

    /**
     * Read back from the database rather than from the entity, because that is
     * the failure this method was one line away from having: `listedAt` is
     * written by a DQL UPDATE, and an implementation that assigned the property
     * instead would leave a loaded entity looking correct and persist nothing.
     */
    public function testTheRetirementIsActuallyPersisted(): void
    {
        $thread = $this->inboxThread();

        $this->handle(['update' => [(string) $thread->id => ['isNew' => false]]]);

        self::assertNotNull($this->storedListedAt($thread));
    }

    /**
     * A list client sends this for every row it draws, including rows it drew a
     * minute ago. A repeat must not move the timestamp forward — the column
     * says when the row was FIRST shown, and a later feature reading it would
     * inherit the lie.
     */
    public function testRetiringTwiceDoesNotMoveTheTimestamp(): void
    {
        $thread = $this->inboxThread();

        $this->handle(['update' => [(string) $thread->id => ['isNew' => false]]]);
        $first = $this->storedListedAt($thread);

        $this->handle(['update' => [(string) $thread->id => ['isNew' => false]]]);

        self::assertSame($first, $this->storedListedAt($thread));
    }

    /**
     * **One way only.** "Make this new again" is not something the product
     * means, and a client that could do it would keep its own badges alive for
     * ever.
     */
    public function testIsNewCannotBeSetToTrue(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle(['update' => [(string) $thread->id => ['isNew' => true]]]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['notUpdated']);
        self::assertNull($this->storedListedAt($thread));
    }

    /** Nor by any other spelling of it. A string "false" is not false. */
    public function testANonBooleanIsNewIsRefused(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle(['update' => [(string) $thread->id => ['isNew' => 'false']]]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['notUpdated']);
        self::assertNull($this->storedListedAt($thread));
    }

    /** Both properties in one patch, which is what a client catching up sends. */
    public function testIsNewAndSnoozedUntilCanTravelTogether(): void
    {
        $thread = $this->inboxThread();

        $result = $this->handle([
            'update' => [
                (string) $thread->id => [
                    'isNew' => false,
                    'snoozedUntil' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ]);

        self::assertArrayHasKey((string) $thread->id, (array) $result['updated']);
        self::assertNotNull($this->storedListedAt($thread));
        self::assertNotNull($thread->snoozedUntil);
    }

    /**
     * A refused patch retires nothing, even though the two properties are
     * applied by different code paths. The patch is validated whole.
     */
    public function testARefusedPatchDoesNotRetireTheMarker(): void
    {
        $thread = $this->inboxThread();

        $this->handle([
            'update' => [(string) $thread->id => ['isNew' => false, 'subject' => 'nope']],
        ]);

        self::assertNull($this->storedListedAt($thread));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * `listed_at` as the database actually holds it.
     *
     * Straight SQL rather than the entity, because the retirement is a DQL
     * UPDATE that bypasses the unit of work: a loaded entity would go on
     * reporting whatever it was hydrated with.
     */
    private function storedListedAt(MessageThread $thread): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT listed_at FROM message_thread WHERE id = ?',
            [$thread->id],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => (string) $this->account->id],
            new JmapContext($this->user),
        );
    }

    /** @return list<LabelRole|null> */
    private function rolesOn(MessageThread $thread): array
    {
        $roles = [];

        foreach ($thread->messages as $message) {
            foreach ($message->labels as $label) {
                $roles[] = $label->role;
            }
        }

        return $roles;
    }

    private function inboxThread(): MessageThread
    {
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Thread/set fixture';
        $thread->normalizedSubject = 'thread/set fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Thread/set fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<threadset-%s@example.test>', uniqid('', true));
        $message->mailbox = $this->mailbox;
        $message->imapUid = 8000;
        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'threadset-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Thread';
        $this->user->nameLast = 'Set';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'Thread Set';
        $this->account->username = 'threadset-fixture@example.test';
        $this->account->imapHost = 'localhost';
        $this->account->imapPort = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost = 'localhost';
        $this->account->smtpPort = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password = 'x';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $this->account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();

        // getAccounts() is what AccountResolver scopes on, and the inverse
        // side is not populated by persisting the owning side alone.
        $this->user->addAccount($this->account);
    }
}
