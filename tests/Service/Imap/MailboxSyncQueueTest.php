<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\Messaging\Handler\SyncImapMailboxMessageHandler;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Service\Imap\MailboxSyncQueue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One sync per folder on the queue, however often the folder asks (#418).
 *
 * A Gmail folder whose syncs ran into the deadline queued a new one every few
 * seconds, and a QQ account's sync waited all night behind 589 of them.
 */
final class MailboxSyncQueueTest extends KernelTestCase
{
    private MailboxSyncQueue $queue;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->queue = static::getContainer()->get(MailboxSyncQueue::class);
    }

    public function testASecondSyncIsNotQueuedWhileTheFirstWaits(): void
    {
        $mailboxId = random_int(1_000_000, 9_999_999);

        self::assertTrue($this->queue->claim($mailboxId));
        self::assertFalse($this->queue->claim($mailboxId), 'the first has not started, so it reads this change too');
        self::assertTrue($this->queue->claim($mailboxId + 1), 'another folder is not held up');

        $this->queue->taken($mailboxId);

        self::assertTrue($this->queue->claim($mailboxId), 'once it has started, a new change queues one more');
    }

    /**
     * The handler says the sync was taken before anything else — here a
     * mailbox that is gone, which returns at once. A marker left behind would
     * hold that folder's IDLE syncs back for the marker's whole lifetime.
     */
    public function testTheHandlerClearsTheMarkerWhateverHappensNext(): void
    {
        $mailboxId = random_int(1_000_000, 9_999_999);
        $handler   = static::getContainer()->get(SyncImapMailboxMessageHandler::class);

        self::assertTrue($this->queue->claim($mailboxId));

        $handler(new SyncImapMailboxMessage($mailboxId));

        self::assertTrue($this->queue->claim($mailboxId));
    }
}
