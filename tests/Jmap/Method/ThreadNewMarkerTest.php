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
use App\Jmap\Method\Mail\ThreadGetMethod;
use App\Jmap\Method\Mail\ThreadSetMethod;
use App\Jmap\Protocol\JmapContext;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The New marker, over the wire, in both directions.
 *
 * `MessageThread::$listedAt` and NEW_WINDOW have driven the web's badges and
 * sidebar dots for a while, and until now none of it had a JMAP surface at all:
 * a phone could not read the marker and could not clear it. The visible cost
 * was a mailbox triaged entirely on a phone opening in the browser with every
 * conversation from the last day still badged "New", and five category tabs
 * still dotted.
 *
 * These tests are about the *pair* of methods rather than either one, because
 * the bug being fixed lives between them: a read that reported newness the
 * write could not retire, or a write whose effect the read did not reflect,
 * would each look correct in isolation and leave the two surfaces disagreeing.
 * So every case below goes get → set → get.
 */
final class ThreadNewMarkerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private ThreadGetMethod $get;
    private ThreadSetMethod $set;

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
        $this->get           = $container->get(ThreadGetMethod::class);
        $this->set           = $container->get(ThreadSetMethod::class);

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

    /** Mail that has just arrived and has never been drawn is new. */
    public function testMailNobodyHasBeenShownIsNew(): void
    {
        $thread = $this->inboxThread();

        self::assertTrue($this->isNew($thread));
    }

    /**
     * **The round trip the feature is.** A client draws the row, says so, and
     * every other client stops calling it new.
     */
    public function testReportingADisplayRetiresTheMarkerForEverybody(): void
    {
        $thread = $this->inboxThread();

        self::assertTrue($this->isNew($thread));

        $this->retire($thread);

        self::assertFalse($this->isNew($thread));
    }

    /**
     * The window is the other half of the rule, and it is applied server-side
     * on purpose: a client that judged the 24 hours itself would be a second
     * implementation of NEW_WINDOW, drifting the day somebody changes it.
     */
    public function testMailOlderThanTheWindowIsNotNewEvenIfNeverShown(): void
    {
        $thread = $this->inboxThread();
        $thread->lastMessageAt = new \DateTimeImmutable('-25 hours');
        $this->em->flush();

        self::assertNull($this->storedListedAt($thread));
        self::assertFalse($this->isNew($thread));
    }

    /**
     * Reading it retires the marker, and this claim is the reverse of what it
     * used to be.
     *
     * It said: "**Newness is not unreadness**, and the two are allowed to
     * disagree — that is the entire feature rather than an accident of it. A
     * conversation read on a laptop is still new to a client that has never
     * drawn its row." That is a coherent definition — the marker records what
     * THIS client has displayed — and it produced an interface nobody could
     * read. Reported as: "I often see new mail that's already marked as read.
     * That also does not make sense."
     *
     * "Often" is the word that settles it — and the reason is NOT that
     * `listedAt` is per client. It is account-wide: whichever plMail surface
     * draws the row first retires the badge everywhere, which
     * NewMailMarkerTest::testADisplayInTheAppRetiresTheBadgeInTheBrowser proves
     * across the two. The gap is mail read in something that is not plMail —
     * the provider's own web client, a phone's built-in mail app — which
     * arrives already \Seen with no plMail surface having drawn its row.
     *
     * Being shown a row was always a proxy for having seen the mail; having
     * READ it is the stronger form of the same statement, and the two only ever
     * disagree in the direction that misleads.
     *
     * What has NOT changed is the other direction: retiring the marker still
     * says nothing about whether anybody read it — see the test below. The
     * marker is a subset of read, not a synonym for it.
     */
    public function testReadingMailRetiresTheMarker(): void
    {
        $thread = $this->inboxThread();

        foreach ($thread->messages as $message) {
            $message->seenAt = new \DateTimeImmutable();
        }

        $thread->unreadCount = 0;
        $this->em->flush();

        self::assertFalse($this->isNew($thread));
    }

    /** And retiring the marker says nothing about whether anybody read it. */
    public function testRetiringTheMarkerDoesNotMarkAnythingRead(): void
    {
        $thread = $this->inboxThread();
        $thread->unreadCount = 1;
        $this->em->flush();

        $this->retire($thread);

        self::assertSame(1, $thread->unreadCount);
        self::assertNull($thread->messages->first()->seenAt);
    }

    /**
     * Thread/get is where a client reads this, so the property has to be
     * present on every thread rather than only on new ones — an absent key and
     * `false` are the same thing to a lenient parser and different things to a
     * strict one.
     */
    public function testTheMarkerIsAlwaysPresentOnTheWire(): void
    {
        $thread = $this->inboxThread();
        $this->retire($thread);

        $row = $this->fetch($thread);

        self::assertArrayHasKey('isNew', $row);
        self::assertIsBool($row['isNew']);
    }

    /**
     * The spec's own two properties are still there, and so are the other two
     * extensions. A client that only knows RFC 8621 must be unaffected by any
     * of this.
     */
    public function testTheSpecPropertiesAreUntouched(): void
    {
        $row = $this->fetch($this->inboxThread());

        self::assertArrayHasKey('id', $row);
        self::assertArrayHasKey('emailIds', $row);
        self::assertArrayHasKey('snoozedUntil', $row);
        self::assertArrayHasKey('category', $row);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function isNew(MessageThread $thread): bool
    {
        return (bool) $this->fetch($thread)['isNew'];
    }

    /** @return array<string,mixed> */
    private function fetch(MessageThread $thread): array
    {
        $result = $this->get->handle(
            ['accountId' => (string) $this->account->id, 'ids' => [(string) $thread->id]],
            new JmapContext($this->user),
        );

        return $result['list'][0];
    }

    private function retire(MessageThread $thread): void
    {
        $this->set->handle(
            [
                'accountId' => (string) $this->account->id,
                'update' => [(string) $thread->id => ['isNew' => false]],
            ],
            new JmapContext($this->user),
        );
    }

    /**
     * Straight SQL, because the retirement is a DQL UPDATE that bypasses the
     * unit of work: a loaded entity goes on reporting what it was hydrated
     * with.
     */
    private function storedListedAt(MessageThread $thread): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT listed_at FROM message_thread WHERE id = ?',
            [$thread->id],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function inboxThread(): MessageThread
    {
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'New marker fixture';
        $thread->normalizedSubject = 'new marker fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        // Unread, because that is what mail nobody has seen looks like — and
        // since reading now retires the marker, a fixture seeded as read would
        // be testing the retirement rather than the marker.
        $thread->unreadCount = 1;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'New marker fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<newmarker-%s@example.test>', uniqid('', true));
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
        $this->user->email = 'newmarker-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'New';
        $this->user->nameLast = 'Marker';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'New Marker';
        $this->account->username = 'newmarker-fixture@example.test';
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
