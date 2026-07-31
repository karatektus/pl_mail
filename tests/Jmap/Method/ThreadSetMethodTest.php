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
 * RFC 8621's Thread is read-only. This adds exactly one settable property, and
 * the tests below are mostly about what it refuses: a method that accepted
 * `create`, or silently ignored an unknown property, would be inventing
 * semantics the rest of the system has no meaning for.
 *
 * The snooze behaviour itself lives in ThreadSnoozeServiceTest — here the
 * question is only whether the method routes to it.
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
                (string) $thread->getId() => [
                    'snoozedUntil' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ]);

        self::assertArrayHasKey((string) $thread->getId(), (array) $result['updated']);
        self::assertNotNull($thread->getSnoozedUntil());
        self::assertNotContains(LabelRole::Inbox, $this->rolesOn($thread));
    }

    public function testNullClearsTheSnooze(): void
    {
        $thread = $this->inboxThread();
        $id     = (string) $thread->getId();

        $this->handle([
            'update' => [$id => ['snoozedUntil' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s\Z')]],
        ]);

        $this->handle(['update' => [$id => ['snoozedUntil' => null]]]);

        self::assertNull($thread->getSnoozedUntil());
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
            'update' => [(string) $thread->getId() => ['subject' => 'nope']],
        ]);

        self::assertArrayHasKey((string) $thread->getId(), (array) $result['notUpdated']);
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
            'update' => [(string) $thread->getId() => ['snoozedUntil' => 'next tuesday-ish']],
        ]);

        self::assertArrayHasKey((string) $thread->getId(), (array) $result['notUpdated']);
        self::assertNull($thread->getSnoozedUntil());
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

        $result = $this->handle(['update' => [(string) $thread->getId() => []]]);

        self::assertArrayHasKey((string) $thread->getId(), (array) $result['updated']);
        self::assertNull($thread->getSnoozedUntil());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => (string) $this->account->getId()],
            new JmapContext($this->user),
        );
    }

    /** @return list<LabelRole|null> */
    private function rolesOn(MessageThread $thread): array
    {
        $roles = [];

        foreach ($thread->getMessages() as $message) {
            foreach ($message->getLabels() as $label) {
                $roles[] = $label->role;
            }
        }

        return $roles;
    }

    private function inboxThread(): MessageThread
    {
        $thread = new MessageThread();
        $thread
            ->setAccount($this->account)
            ->setSubject('Thread/set fixture')
            ->setNormalizedSubject('thread/set fixture')
            ->setLastMessageAt(new \DateTimeImmutable('-1 hour'))
            ->setThreadingMethod(ThreadingMethod::References)
            ->setUnreadCount(0);
        $this->em->persist($thread);

        $message = new Message();
        $message
            ->setAccount($this->account)
            ->setSubject('Thread/set fixture')
            ->setFromAddress('sender@example.test')
            ->setReceivedAt(new \DateTimeImmutable('-1 hour'))
            ->setHasAttachments(false)
            ->setMessageId(sprintf('<threadset-%s@example.test>', uniqid('', true)))
            ->setMailbox($this->mailbox)
            ->setImapUid(8000)
            ->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user
            ->setEmail('threadset-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Thread')
            ->setNameLast('Set')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account
            ->setUsr($this->user)
            ->setEmail('Thread Set')
            ->setUsername('threadset-fixture@example.test')
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
        $this->mailbox
            ->setAccount($this->account)
            ->setName('INBOX')
            ->setFullPath('INBOX')
            ->setIsSyncEnabled(true)
            ->setIsIdleEnabled(false)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($this->mailbox);

        $this->em->flush();

        // getAccounts() is what AccountResolver scopes on, and the inverse
        // side is not populated by persisting the owning side alone.
        $this->user->addAccount($this->account);
    }
}
