<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Service\Ai\BackfillPolicy;
use App\Service\Ai\ClassificationCatchUp;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Old mail is asked about after an import, not during it — and then newest
 * first, a bounded handful at a time, on the queue behind every other.
 *
 * Each of those is a separate way the first import used to go wrong, so each
 * has a test.
 */
final class ClassificationCatchUpTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->seed();
        $this->assistantSorts();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * A mailbox still arriving is a moving target, and its own recent mail has
     * first call on the model.
     */
    public function testNothingIsQueuedWhileTheImportIsStillRunning(): void
    {
        $this->mail(5);

        self::assertSame(0, $this->catchUp()->sweep($this->user, 100));
        self::assertSame([], $this->backlog()->getSent());
    }

    public function testTheNewestUnaskedMailIsQueuedFirstAndOnlyUpToTheLimit(): void
    {
        $ids = $this->mail(6);
        $this->importFinished();

        self::assertSame(4, $this->catchUp()->sweep($this->user, 4));

        // mail() dates them oldest first, so the newest four are the last four
        // and they come out newest first.
        self::assertSame(array_reverse(array_slice($ids, 2)), $this->queuedIds());
    }

    /**
     * The stamp is the progress marker: asked once, whatever the answer was.
     */
    public function testMailAlreadyAskedAboutIsNotQueuedAgain(): void
    {
        $ids = $this->mail(3);
        $this->importFinished();

        $this->connection->executeStatement(
            'UPDATE message SET ai_categorised_at = NOW() WHERE id = :id',
            ['id' => $ids[2]],
        );

        $this->catchUp()->sweep($this->user, 100);

        self::assertSame([$ids[1], $ids[0]], $this->queuedIds());
    }

    public function testTheWorkIsChunkedIntoJobsThatAreShort(): void
    {
        $this->mail(5);
        $this->importFinished();

        $this->catchUp()->sweep($this->user, 100);

        $batchSize = self::getContainer()->get(BackfillPolicy::class)->batchSize;

        self::assertCount((int) ceil(5 / $batchSize), $this->backlog()->getSent());
    }

    public function testNothingIsQueuedWhileSortingByAssistantIsOff(): void
    {
        $this->mail(3);
        $this->importFinished();
        $this->assistantSorts(enabled: false);

        self::assertSame(0, $this->catchUp()->sweep($this->user, 100));
    }

    /**
     * The queue is what stands in for state: a run that posted on top of the
     * last run's unfinished work would queue the same ids twice.
     */
    public function testARunWaitsForTheLastRunToBeWorkedThrough(): void
    {
        self::assertTrue($this->catchUp()->mayRun());

        $this->connection->insert('messenger_messages', [
            'body'         => 'x',
            'headers'      => '{}',
            'queue_name'   => 'enrich_backlog',
            'created_at'   => new DateTimeImmutable(),
            'available_at' => new DateTimeImmutable(),
        ], [
            'created_at'   => 'datetime_immutable',
            'available_at' => 'datetime_immutable',
        ]);

        self::assertFalse($this->catchUp()->mayRun());
    }

    /**
     * The limit from Admin → AI (#208): a year-old mailbox is left to the rules,
     * and last week's mail is still sorted.
     */
    public function testMailOlderThanTheLimitIsNotQueued(): void
    {
        $this->mail(3);
        $new = $this->mailReceived('-2 days');
        $this->importFinished();
        $this->assistantSorts(backlogDays: 30);

        self::assertSame(1, $this->catchUp()->sweep($this->user, 100));
        self::assertSame([$new], $this->queuedIds());
    }

    /**
     * Once everything inside the limit is done, a run finds nothing — rather
     * than walking on into the history and queueing envelopes the handler would
     * only throw away.
     */
    public function testARunWithNothingInsideTheLimitQueuesNothing(): void
    {
        $this->mail(3);
        $this->importFinished();
        $this->assistantSorts(backlogDays: 30);

        self::assertSame(0, $this->catchUp()->sweep($this->user, 100));
        self::assertSame([], $this->backlog()->getSent());
    }

    /**
     * The on-demand way past the limit, `app:ai:classify-backlog --any-age`:
     * the old mail is queued, and each envelope says so, because the handler
     * checks the limit again when it takes the envelope off the queue.
     */
    public function testAskingForAnyAgeQueuesTheOldMailAndFlagsIt(): void
    {
        $ids = $this->mail(3);
        $this->importFinished();
        $this->assistantSorts(backlogDays: 30);

        self::assertSame(3, $this->catchUp()->sweep($this->user, 100, anyAge: true));
        self::assertSame(array_reverse($ids), $this->queuedIds(anyAge: true));
    }

    /**
     * The query reads a message's age the way the handler does — receivedAt,
     * else sentAt, and neither is not too old — or the two would disagree
     * about a message and the catch-up would queue what the handler drops.
     */
    public function testTheLimitFallsBackToTheSentDateAndKeepsUndatedMail(): void
    {
        $recentlySent = $this->mailReceived(null, sent: '-2 days');
        $this->mailReceived(null, sent: '-90 days');
        $undated = $this->mailReceived(null);
        $this->importFinished();
        $this->assistantSorts(backlogDays: 30);

        self::assertSame(2, $this->catchUp()->sweep($this->user, 100));
        self::assertEqualsCanonicalizing([$recentlySent, $undated], $this->queuedIds());
    }

    private function catchUp(): ClassificationCatchUp
    {
        return self::getContainer()->get(ClassificationCatchUp::class);
    }

    /** @return list<int> */
    private function queuedIds(bool $anyAge = false): array
    {
        $ids = [];

        foreach ($this->backlog()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            self::assertInstanceOf(ClassifyMailMessage::class, $message);
            self::assertFalse($message->force);
            self::assertSame($anyAge, $message->anyAge);

            $ids = [...$ids, ...$message->messageIds];
        }

        return $ids;
    }

    /**
     * enrich_backlog and no other: the routing table sends this message to
     * `enrich`, where it would be level with an import's recent mail.
     */
    private function backlog(): InMemoryTransport
    {
        self::assertSame([], $this->transport('enrich')->getSent());
        self::assertSame([], $this->transport('ingest')->getSent());

        return $this->transport('enrich_backlog');
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * With no age limit unless a test sets one. The fixture mail is a year old
     * and most of these tests are about order and bounds, not about age; the
     * ones about age say so.
     */
    private function assistantSorts(bool $enabled = true, int $backlogDays = 0): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:4b-instruct';
        $settings->categorisationEnabled = $enabled;
        $settings->categoriseBacklogDays = $backlogDays;

        $this->em->persist($settings);
        $this->em->flush();
    }

    private function importFinished(): void
    {
        $this->mailbox->syncedAt       = new DateTimeImmutable('-1 day');
        $this->mailbox->importFloorUid = 0;

        $this->em->flush();
    }

    /**
     * Old mail, oldest first: the i-th is a day newer than the one before it.
     *
     * @return list<int>
     */
    private function mail(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $message = new Message();
            $message->account = $this->mailbox->account;
            $message->mailbox = $this->mailbox;
            $message->subject = 'Backlog fixture ' . $i;
            $message->fromAddress = 'somebody@elsewhere.test';
            $message->receivedAt = new DateTimeImmutable(sprintf('-%d days', 400 - $i));
            $message->hasAttachments = false;
            $message->messageId = sprintf('<backlog-%d-%s@example.test>', $i, uniqid('', true));

            $this->em->persist($message);
            $this->em->flush();

            $ids[] = (int) $message->id;
        }

        return $ids;
    }

    private function mailReceived(?string $received, ?string $sent = null): int
    {
        $message = new Message();
        $message->account = $this->mailbox->account;
        $message->mailbox = $this->mailbox;
        $message->subject = 'Recent fixture';
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->receivedAt = null === $received ? null : new DateTimeImmutable($received);
        $message->sentAt = null === $sent ? null : new DateTimeImmutable($sent);
        $message->hasAttachments = false;
        $message->messageId = sprintf('<recent-%s@example.test>', uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();

        return (int) $message->id;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'backlog-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Back';
        $this->user->nameLast = 'Log';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $account = new Account();
        $account->usr = $this->user;
        $account->email = 'backlog-fixture@example.test';
        $account->username = 'backlog-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();
    }
}
