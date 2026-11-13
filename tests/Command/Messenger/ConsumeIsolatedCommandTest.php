<?php

declare(strict_types=1);

namespace App\Tests\Command\Messenger;

use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The whole of #610 wired together: Symfony's Worker takes a message off the
 * queue, a real child process (bin/console app:messenger:handle-one) handles
 * it, and the Worker acknowledges it once the child has exited.
 *
 * The unit tests next door drive the strategy with `php -r` children and a
 * callback of their own; this is the one that runs it under the real Worker
 * and the real child command, which is where a mistake in how the two talk to
 * each other would show.
 */
final class ConsumeIsolatedCommandTest extends KernelTestCase
{
    public function testAMessageIsHandledInAChildAndAcknowledged(): void
    {
        self::bootKernel();

        /** @var InMemoryTransport $ingest */
        $ingest = self::getContainer()->get('messenger.transport.ingest');
        $ingest->reset();

        // A batch for an account that does not exist: the handler logs and
        // returns, a clean exit without needing a mail server. A batch rather
        // than a sync, because a sync whose child crashed is acknowledged too
        // (dropped, asked for again) and would pass this test either way; a
        // crashed batch is retried and rejected instead.
        $ingest->send(new Envelope(new SyncGraphMessageBatchMessage(2_000_000_000, ['x'])));

        $tester = new CommandTester(new Application(self::$kernel)->find('app:messenger:consume-isolated'));
        $tester->execute(['transport' => 'ingest', '--time-limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $ingest->getAcknowledged(), 'acknowledged once the child exited 0');
        self::assertSame([], $ingest->getRejected(), 'a child that failed would have been rejected and retried');
        self::assertSame([], array_values(array_filter(
            $ingest->getSent(),
            static fn (Envelope $sent): bool => null !== $sent->last(RedeliveryStamp::class),
        )), 'and no retry copy was queued');
    }

    public function testAnUnknownTransportIsRefused(): void
    {
        self::bootKernel();

        $tester = new CommandTester(new Application(self::$kernel)->find('app:messenger:consume-isolated'));

        self::assertSame(2, $tester->execute(['transport' => 'no-such-queue']));
    }
}
