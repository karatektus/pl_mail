<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event;

use App\Command\Backfill\MessageArrivalBackfillTask;
use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Mail\SyncOrigin;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What is written on a message as it is first stored: when the provider had
 * it, what fetched it, where it lay. Admin → Performance reads nothing else,
 * so these three columns being right is that panel being right.
 *
 * Through the real EntityManager, because the claim is that it happens to
 * every message whichever builder made it — which is a claim about persist.
 */
final class ArrivalStamperTest extends KernelTestCase
{
    private const string RECEIVED = 'by mx.example.test with ESMTPS id abc; Wed, 7 Oct 2026 05:15:46 -0700 (PDT)';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SyncOrigin $origin;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->origin     = $container->get(SyncOrigin::class);

        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'arrival'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAMessageStoredDuringASyncSaysWhenTheProviderHadItAndWhatFetchedIt(): void
    {
        $message = $this->message(['received' => self::RECEIVED], gmailLabels: ['CATEGORY_UPDATES', 'INBOX']);

        $this->origin->during(SyncTrigger::Push, fn () => $this->em->persist($message));

        self::assertNotNull($message->providerAcceptedAt);
        self::assertSame('2026-10-07 12:15:46', gmdate('Y-m-d H:i:s', $message->providerAcceptedAt->getTimestamp()));
        self::assertSame(SyncTrigger::Push, $message->arrivedBy);
        self::assertSame(ArrivalPlace::Inbox, $message->arrivedIn);
    }

    /** Gmail keeps INBOX off spam, but SPAM is the label that decides it either way. */
    public function testSpamIsSpamWhateverElseItIsLabelled(): void
    {
        $message = $this->message(['received' => self::RECEIVED], gmailLabels: ['INBOX', 'UNREAD', 'SPAM']);

        $this->origin->during(SyncTrigger::Poll, fn () => $this->em->persist($message));

        self::assertSame(ArrivalPlace::Spam, $message->arrivedIn);
        self::assertSame(SyncTrigger::Poll, $message->arrivedBy);
    }

    public function testMailFiledPastTheInboxArrivedElsewhere(): void
    {
        $message = $this->message(['received' => self::RECEIVED], gmailLabels: ['CATEGORY_PROMOTIONS', 'Label_27']);

        $this->em->persist($message);

        self::assertSame(ArrivalPlace::Elsewhere, $message->arrivedIn);
    }

    /** A draft, a sent copy: written here, outside any sync, with no Received header. */
    public function testMailThatWasWrittenHereDidNotArrive(): void
    {
        $message = $this->message(['subject' => 'A draft']);

        $this->em->persist($message);

        self::assertNull($message->providerAcceptedAt);
        self::assertNull($message->arrivedBy);
        self::assertNull($message->arrivedIn);
    }

    /** One sync's trigger must not be left behind for whatever is stored next. */
    public function testTheTriggerEndsWithTheSyncEvenWhenTheSyncThrows(): void
    {
        try {
            $this->origin->during(SyncTrigger::Idle, static function (): void {
                throw new \RuntimeException('the server hung up');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($this->origin->current());
    }

    /**
     * Mail stored before any of this existed gets its time from the headers it
     * was stored with. What fetched it is gone and stays empty.
     */
    public function testTheBackfillGivesLastWeeksMailItsAcceptTime(): void
    {
        $arrived = $this->message(['received' => self::RECEIVED], gmailLabels: ['INBOX']);
        $draft   = $this->message(['subject' => 'A draft']);

        $this->em->persist($arrived);
        $this->em->persist($draft);
        $this->em->flush();

        // As the migration leaves every existing row.
        $this->connection->executeStatement(
            'UPDATE message SET provider_accepted_at = NULL, arrived_in = NULL WHERE id IN (?, ?)',
            [$arrived->id, $draft->id],
        );
        $this->em->clear();

        $task = self::getContainer()->get(MessageArrivalBackfillTask::class);

        self::assertSame(0, $task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput())));
        // Twice, because the runner may.
        self::assertSame(0, $task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput())));

        $row = $this->connection->fetchAssociative(
            'SELECT provider_accepted_at, arrived_in, arrived_by FROM message WHERE id = ?',
            [$arrived->id],
        );

        self::assertIsArray($row);
        self::assertNotNull($row['provider_accepted_at']);
        self::assertSame('inbox', $row['arrived_in']);
        self::assertNull($row['arrived_by']);

        self::assertNull($this->connection->fetchOne('SELECT provider_accepted_at FROM message WHERE id = ?', [$draft->id]));
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param list<string>|null                  $gmailLabels
     */
    private function message(array $headers, ?array $gmailLabels = null): Message
    {
        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Arrival';
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->hasAttachments = false;
        $message->messageId = sprintf('<arrival-%s@example.test>', uniqid('', true));
        $message->headers = $headers;
        $message->gmailLabelIds = $gmailLabels;

        return $message;
    }
}
