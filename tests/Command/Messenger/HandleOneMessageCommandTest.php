<?php

declare(strict_types=1);

namespace App\Tests\Command\Messenger;

use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The child half of #610: one message from stdin, handled as a worker would
 * have handled it, with the failure dealt with before it exits.
 */
final class HandleOneMessageCommandTest extends KernelTestCase
{
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class, WorkerStoppedEvent::class] as $event) {
            $dispatcher->addListener($event, function (object $fired): void {
                $this->events[] = $fired::class;
            });
        }

        $this->ingest()->reset();
    }

    public function testAHandledMessageDispatchesTheHandledEvent(): void
    {
        $tester = $this->handleOne(new SyncAccountMessage(2_000_000_000));

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([WorkerMessageHandledEvent::class], $this->events);
        self::assertSame([], $this->ingest()->getSent(), 'nothing retried');
    }

    /**
     * A failure is put on the retry ladder by the child itself, through
     * Symfony's own listener, and the child still exits 0: it has finished
     * with the message, and the parent must not retry it a second time.
     */
    public function testAFailureIsPutOnTheRetryLadderBeforeTheChildExits(): void
    {
        $tester = $this->handleOne(new \stdClass());

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([WorkerMessageFailedEvent::class], $this->events);

        $sent = $this->ingest()->getSent();

        self::assertCount(1, $sent, 'the retry copy, on the transport the message came from');
        self::assertSame(1, RedeliveryStamp::getRetryCountFromEnvelope($sent[0]));
    }

    /**
     * Never the worker's lifecycle: a child's "stopped" would delete the
     * parent's heartbeat, which is keyed by the same process name.
     */
    public function testTheWorkersOwnLifecycleIsNotDispatched(): void
    {
        $this->handleOne(new SyncAccountMessage(2_000_000_000));

        self::assertNotContains(WorkerStoppedEvent::class, $this->events);
    }

    private function handleOne(object $message): CommandTester
    {
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.default_serializer');

        $tester = new CommandTester(new Application(self::$kernel)->find('app:messenger:handle-one'));
        $tester->setInputs([json_encode($serializer->encode(new Envelope($message)), JSON_THROW_ON_ERROR)]);
        $tester->execute(['transport' => 'ingest'], ['interactive' => false]);

        return $tester;
    }

    private function ingest(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest');

        return $transport;
    }
}
