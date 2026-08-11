<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\RecipientsBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Tests\Command\MailFixtures;
use App\Twig\MessageRecipientsExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Getting recipients back onto rows that were synced without them.
 *
 * Every IMAP message stored before MessageSyncer::addressesOf() was fixed has
 * empty to/cc columns. The headers themselves were never lost — they reach the
 * `headers` jsonb bag by a different code path — so this needs no mail server,
 * no raw MIME and no resync: the row already contains the answer.
 *
 * Pinned here: it fills what is empty, it does NOT touch what was captured
 * correctly, a row with genuinely no To: header stays empty, and the header
 * fallback in the message header agrees with the backfill about all three, so
 * that a mailbox reads the same before the command is run as after it.
 */
final class RecipientsBackfillTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private RecipientsBackfillTask $task;
    private MessageRecipientsExtension $extension;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container       = self::getContainer();
        $this->em        = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task      = $container->get(RecipientsBackfillTask::class);
        $this->extension = $container->get(MessageRecipientsExtension::class);

        $this->connection->beginTransaction();

        $user          = MailFixtures::user($this->em, 'recipients');
        $this->account = MailFixtures::account($this->em, $user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnEmptyColumnIsFilledFromTheStoredHeader(): void
    {
        $message = $this->seed(
            toAddresses: [],
            headers: [
                'to' => 'Alice <alice@example.com>, bob@example.com',
                'cc' => 'Carol <carol@example.com>',
            ],
        );

        $message = $this->afterBackfill($message);

        self::assertSame(
            [
                ['name' => 'Alice', 'address' => 'alice@example.com'],
                ['name' => '', 'address' => 'bob@example.com'],
            ],
            $message->toAddresses,
        );
        self::assertSame(
            [['name' => 'Carol', 'address' => 'carol@example.com']],
            $message->ccAddresses,
        );
    }

    public function testACapturedListIsLeftExactlyAsItIs(): void
    {
        $captured = [['name' => 'Captured', 'address' => 'captured@example.com']];
        $message  = $this->seed(
            toAddresses: $captured,
            // Deliberately disagreeing: a re-parse must not win over a good
            // capture, because it is the poorer of the two readings.
            headers: ['to' => 'Someone Else <else@example.com>'],
        );

        $message = $this->afterBackfill($message);

        self::assertSame($captured, $message->toAddresses);
    }

    public function testAMessageWithNoRecipientHeaderStaysEmpty(): void
    {
        $message = $this->seed(toAddresses: [], headers: ['Return-Path' => '<x@example.com>']);

        $message = $this->afterBackfill($message);

        self::assertSame([], $message->toAddresses);
        self::assertTrue($this->extension->summary($message)['empty']);
    }

    /**
     * The header row must not wait for the command: a mailbox that has never
     * been backfilled reads the same as one that has.
     */
    public function testTheHeaderReadsTheBagBeforeTheBackfillHasRun(): void
    {
        $message = $this->seed(
            toAddresses: [],
            headers: ['to' => 'Alice <alice@example.com>, Bob <bob@example.com>'],
        );

        self::assertSame(
            [
                ['name' => 'Alice', 'address' => 'alice@example.com'],
                ['name' => 'Bob', 'address' => 'bob@example.com'],
            ],
            $this->extension->addresses($message, 'to'),
        );

        $summary = $this->extension->summary($message);

        self::assertSame(['Alice', 'Bob'], $summary['names']);
        self::assertSame(0, $summary['extra']);
        self::assertFalse($summary['empty']);
    }

    /** Cc counts towards "+N" without ever being billed as a headline recipient. */
    public function testTheSummaryNamesThreeAndCountsTheRest(): void
    {
        $message = $this->seed(
            toAddresses: [
                ['name' => 'One', 'address' => 'one@example.com'],
                ['name' => 'Two', 'address' => 'two@example.com'],
                ['name' => 'Three', 'address' => 'three@example.com'],
                ['name' => 'Four', 'address' => 'four@example.com'],
            ],
            headers: [],
            ccAddresses: [['name' => 'Five', 'address' => 'five@example.com']],
        );

        $summary = $this->extension->summary($message);

        self::assertSame(['One', 'Two', 'Three'], $summary['names']);
        self::assertSame(2, $summary['extra'], 'the fourth recipient and the one Cc');
    }

    /**
     * A message with no To: at all is still addressed to its Cc recipients.
     * Naming nobody while counting them rendered as "to  +1".
     */
    public function testACcOnlyMessageNamesTheCcRecipients(): void
    {
        $message = $this->seed(
            toAddresses: [],
            headers: [],
            ccAddresses: [['name' => 'Only Copy', 'address' => 'copy@example.com']],
        );

        $summary = $this->extension->summary($message);

        self::assertSame(['Only Copy'], $summary['names']);
        self::assertSame(0, $summary['extra']);
        self::assertFalse($summary['empty']);
    }

    /** An address of this account's own reads as "me", aliases included. */
    public function testMyOwnAddressReadsAsMe(): void
    {
        $message = $this->seed(
            toAddresses: [['name' => 'Whoever', 'address' => (string) $this->account->username]],
            headers: [],
        );

        self::assertSame(['me'], $this->extension->summary($message)['names']);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * @param array<mixed>                                  $toAddresses
     * @param array<string, string|array<int, string>|null> $headers
     * @param array<mixed>                                  $ccAddresses
     */
    private function seed(array $toAddresses, array $headers, array $ccAddresses = []): Message
    {
        $message = MailFixtures::message($this->em, $this->account, 'Recipients fixture');

        $message->toAddresses = $toAddresses;
        $message->ccAddresses = $ccAddresses;
        $message->headers     = $headers;

        $this->em->flush();

        return $message;
    }

    /**
     * Runs the task and hands back the same row, re-read.
     *
     * Re-read rather than refreshed: the task clears the entity manager
     * between batches, as a backfill over a whole mailbox must, so the fixture
     * object is detached by the time it returns.
     */
    private function afterBackfill(Message $message): Message
    {
        $id     = (int) $message->id;
        $output = new BufferedOutput();

        self::assertSame(0, $this->task->run(new SymfonyStyle(new ArrayInput([]), $output)));

        $fresh = $this->em->find(Message::class, $id);

        self::assertNotNull($fresh);

        return $fresh;
    }
}
