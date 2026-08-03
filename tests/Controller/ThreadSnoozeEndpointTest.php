<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The web snooze endpoint has to mean the same thing as Thread/set.
 *
 * It did not. `POST /status/thread/{id}/snooze` wrote snoozedUntil and nothing
 * else — no labels moved, nothing propagated — so the conversation stayed in
 * the Inbox locally and at the provider while its row vanished from the list,
 * until the sweep "woke" a thread that had never left. Both callers now go
 * through ThreadSnoozeService, and this pins the endpoint to that.
 *
 * Deliberately asserted on labels rather than on the column: the column was
 * always written correctly, and a test that checked it would have passed
 * against the broken implementation.
 */
final class ThreadSnoozeEndpointTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private Account $account;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSnoozingMovesTheThreadOutOfTheInbox(): void
    {
        $client   = $this->signIn();
        $threadId = (int) $this->inboxThread()->id;

        $client->request(
            'POST',
            sprintf('/status/thread/%d/snooze', $threadId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['until' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)]),
        );

        self::assertResponseIsSuccessful();

        $thread = $this->reload($threadId);

        // Looked up, never created: systemLabel() would mint a Snoozed label
        // as a side effect of asserting, so a broken endpoint would fail here
        // on fixture mechanics instead of on the thing being tested.
        $roles = [];

        foreach ($thread->messages as $message) {
            foreach ($message->labels as $label) {
                $roles[] = $label->role;
            }
        }

        self::assertNotContains(
            LabelRole::Inbox,
            $roles,
            'the endpoint wrote the column but left the thread in the inbox',
        );
        self::assertContains(
            LabelRole::Snoozed,
            $roles,
            'the endpoint wrote the column but never applied the Snoozed label',
        );

        self::assertNotNull($thread->snoozedUntil);
    }

    public function testSendingNoUntilClearsTheSnoozeAndRestoresTheInbox(): void
    {
        $client   = $this->signIn();
        $threadId = (int) $this->inboxThread()->id;
        $path     = sprintf('/status/thread/%d/snooze', $threadId);

        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['until' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)]),
        );

        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['until' => null]),
        );

        self::assertResponseIsSuccessful();

        $thread = $this->reload($threadId);
        $roles  = [];

        foreach ($thread->messages->first()->labels as $label) {
            $roles[] = $label->role;
        }

        self::assertNull($thread->snoozedUntil);
        self::assertContains(LabelRole::Inbox, $roles);
        self::assertNotContains(LabelRole::Snoozed, $roles);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Re-read the thread from the container's current EntityManager.
     *
     * By id rather than refresh(): the kernel is rebooted between requests, so
     * the instance the fixtures returned is detached by the time the
     * assertions run and refresh() would reject it.
     */
    private function reload(int $id): MessageThread
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $thread = $em->find(MessageThread::class, $id);

        self::assertNotNull($thread, 'thread vanished');

        return $thread;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();

        // One kernel across the whole test. The client reboots between
        // requests by default, which detaches the EntityManager this test
        // holds — and the unsnooze case needs two requests against the same
        // fixtures.
        $client->disableReboot();

        $container = static::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        // Rolled back in tearDown. Without it this test commits an Account
        // onto a shared user, and AccountFormControllersTest — which renders
        // the onboarding account step — starts seeing a different page.
        $this->connection->beginTransaction();

        // Its own user rather than the seeded admin, for the same reason: the
        // fixtures here change what onboarding thinks the account holds.
        $user = new User();
        $user->email = 'snooze-endpoint-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Snooze';
        $user->nameLast = 'Endpoint';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);
        $this->em->flush();

        $client->loginUser($user);

        $this->account = new Account();
        $this->account
            ->setUsr($user)
            ->setEmail('Snooze Endpoint')
            ->setUsername('snooze-endpoint-' . uniqid('', true) . '@example.test')
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
        $this->em->flush();

        return $client;
    }

    private function inboxThread(): MessageThread
    {
        $mailbox = new Mailbox();
        $mailbox->account = $this->account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $mailbox->createdAt = new \DateTimeImmutable();
        $mailbox->updatedAt = new \DateTimeImmutable();
        $this->em->persist($mailbox);

        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Endpoint fixture';
        $thread->normalizedSubject = 'endpoint fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Endpoint fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<snooze-endpoint-%s@example.test>', uniqid('', true));
        $message->mailbox = $mailbox;
        $message->imapUid = 4242;
        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }
}
