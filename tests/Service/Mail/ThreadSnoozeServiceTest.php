<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadSnoozeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Snooze is a label move, not a column write.
 *
 * That distinction is the whole subject here. The web endpoint used to set
 * snoozedUntil directly and nothing else, which left the conversation sitting
 * in the Inbox — locally and at the provider — while the row disappeared from
 * the list, until the sweep "woke" a thread that had never left.
 *
 * Against a real container and a real database rather than doubles, for two
 * reasons. Every collaborator this service takes is `final`, so none of them
 * can be doubled anyway; and the behaviour worth pinning is the one that
 * emerges from them together — labels moved, an outward propagation queued —
 * which a set of mocks would assert into existence rather than observe.
 */
final class ThreadSnoozeServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadSnoozeService $service;
    private LabelResolver $labelResolver;
    private LabelRepository $labelRepository;

    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em              = $container->get(EntityManagerInterface::class);
        $this->connection      = $container->get(Connection::class);
        $this->service         = $container->get(ThreadSnoozeService::class);
        $this->labelResolver   = $container->get(LabelResolver::class);
        $this->labelRepository = $container->get(LabelRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->inboxMailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSnoozeMovesTheThreadOutOfTheInbox(): void
    {
        $thread = $this->inboxThread(2);
        $until  = new \DateTimeImmutable('+1 day');

        $this->service->snooze($thread, $until);

        $inbox   = $this->role(LabelRole::Inbox);
        $snoozed = $this->role(LabelRole::Snoozed);

        foreach ($thread->getMessages() as $message) {
            self::assertFalse($message->getLabels()->contains($inbox), 'Inbox label should be gone');
            self::assertTrue($message->getLabels()->contains($snoozed), 'Snoozed label should be set');
        }

        self::assertSame($until, $thread->getSnoozedUntil());
        self::assertTrue($thread->isSnoozed());
    }

    /**
     * The regression that matters most: a snooze the provider never hears about
     * leaves the conversation in the Gmail inbox, which is exactly what
     * snoozing is supposed to prevent. The propagator works by queueing, so the
     * observable fact is that something was queued.
     */
    public function testSnoozeQueuesTheOutwardChange(): void
    {
        $transport = $this->transport();
        $before    = count($transport->getSent());

        $this->service->snooze($this->inboxThread(1), new \DateTimeImmutable('+1 day'));

        self::assertGreaterThan(
            $before,
            count($transport->getSent()),
            'snoozing must propagate outward, not only move local labels',
        );
    }

    public function testWakeReturnsTheThreadToTheInboxAndClearsTheSnooze(): void
    {
        $thread = $this->inboxThread(2);
        $this->service->snooze($thread, new \DateTimeImmutable('+1 day'));

        $this->service->wake($thread);

        $inbox   = $this->role(LabelRole::Inbox);
        $snoozed = $this->role(LabelRole::Snoozed);

        foreach ($thread->getMessages() as $message) {
            self::assertTrue($message->getLabels()->contains($inbox));
            self::assertFalse($message->getLabels()->contains($snoozed));
        }

        self::assertNull($thread->getSnoozedUntil());
        self::assertFalse($thread->isSnoozed());
    }

    /**
     * Waking marks unread on purpose — a thread that comes back in the state it
     * left in is one the reader has already trained themselves to scroll past.
     */
    public function testWakeMarksEveryMessageUnread(): void
    {
        $thread = $this->inboxThread(3);

        foreach ($thread->getMessages() as $message) {
            $message->addFlag(MessageFlag::SEEN)->setSeenAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        $this->service->wake($thread);

        foreach ($thread->getMessages() as $message) {
            self::assertFalse($message->hasFlag(MessageFlag::SEEN));
            self::assertNull($message->getSeenAt());
        }

        self::assertSame(3, $thread->getUnreadCount());
    }

    /**
     * The sweep and a user clicking "unsnooze" can race, and the scheduler
     * replays a missed run — so waking twice has to be harmless.
     */
    public function testWakeIsIdempotent(): void
    {
        $thread = $this->inboxThread(1);
        $this->service->snooze($thread, new \DateTimeImmutable('+1 day'));

        $this->service->wake($thread);
        $this->service->wake($thread);

        self::assertNull($thread->getSnoozedUntil());
        self::assertTrue(
            $thread->getMessages()->first()->getLabels()->contains($this->role(LabelRole::Inbox)),
        );
    }

    /**
     * A past time is accepted rather than rejected: it is a legitimate way to
     * say "bring this back on the next sweep", and saves every caller a clock
     * comparison.
     */
    public function testSnoozeAcceptsATimeInThePast(): void
    {
        $thread = $this->inboxThread(1);
        $past   = new \DateTimeImmutable('-1 hour');

        $this->service->snooze($thread, $past);

        self::assertSame($past, $thread->getSnoozedUntil());
        self::assertFalse($thread->isSnoozed(), 'already due, so not snoozed');
    }

    /** A thread with no messages has no labels to move; it must not fatal. */
    public function testEmptyThreadIsSafe(): void
    {
        $thread = new MessageThread();
        $thread
            ->setAccount($this->account)
            ->setSubject('Empty')
            ->setNormalizedSubject('empty')
            ->setLastMessageAt(new \DateTimeImmutable('-1 hour'))
            ->setThreadingMethod(ThreadingMethod::References)
            ->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        $this->em->persist($thread);
        $this->em->flush();

        $this->service->snooze($thread, new \DateTimeImmutable('+2 days'));
        $this->service->wake($thread);

        self::assertNull($thread->getSnoozedUntil());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function role(LabelRole $role): object
    {
        return $this->labelResolver->systemLabel($role, $this->account);
    }

    private function inboxThread(int $messages): MessageThread
    {
        $inbox  = $this->role(LabelRole::Inbox);
        $thread = new MessageThread();
        $thread
            ->setAccount($this->account)
            ->setSubject('Snooze fixture')
            ->setNormalizedSubject('snooze fixture')
            ->setLastMessageAt(new \DateTimeImmutable('-1 hour'))
            ->setThreadingMethod(ThreadingMethod::References)
            ->setUnreadCount(0);
        $this->em->persist($thread);

        for ($i = 0; $i < $messages; $i++) {
            $message = new Message();
            $message
                ->setAccount($this->account)
                ->setSubject(sprintf('Snooze fixture %d', $i))
                ->setFromAddress('sender@example.test')
                ->setReceivedAt(new \DateTimeImmutable('-1 hour'))
                ->setHasAttachments(false)
                ->setMessageId(sprintf('<snooze-%s-%d@example.test>', uniqid('', true), $i))
                // A mailbox and a UID, because LabelChangePropagator skips
                // messages that have neither — without them the propagation
                // assertion would be testing the fixture, not the service.
                ->setMailbox($this->inboxMailbox)
                ->setImapUid(1000 + $i);

            $message->addLabel($inbox);
            $thread->addMessage($message);

            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user
            ->setEmail('snooze-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Snooze')
            ->setNameLast('Fixture')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Snooze Fixture')
            ->setUsername('snooze-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox
            ->setAccount($this->account)
            ->setName('INBOX')
            ->setFullPath('INBOX')
            ->setIsSyncEnabled(true)
            ->setIsIdleEnabled(false)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }
}
