<?php

declare(strict_types=1);

namespace App\Command\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Execution\SyncMessageExecutionStrategy;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles one message, read from stdin, the way a Messenger worker would have
 * handled it (#610).
 *
 * The child half of ChildProcessExecutionStrategy: the worker that took the
 * message off the queue starts one of these per message and does not wait for
 * it. Not meant to be run by hand.
 *
 * "The way a worker would have" is meant literally. The message goes through
 * the same bus with the same stamps a worker adds (ReceivedStamp, which every
 * middleware here checks before it acts, and ConsumedByWorkerStamp), and the
 * three events a worker dispatches around it are dispatched here: received
 * before, then handled or failed. The failed event is what puts a message on
 * the retry ladder or the failed queue — Symfony's own listeners send the
 * retry copy to the transport by name — so a failure here is dealt with
 * completely before this exits, exactly as in a worker. The handled and failed
 * events are also where JmapPushSubscriber announces the changes a sync made.
 *
 * What is deliberately NOT dispatched is the worker's own lifecycle — started,
 * running, stopped. Those are the parent's: WorkerHeartbeatListener keys its
 * row by the process name, which this child shares, and a child's "stopped"
 * would delete the parent's heartbeat every time a job finished.
 *
 * Exit code 0 means "finished with the message", whether it was handled or
 * failed and was put on the ladder. Anything else — an exception that escaped
 * even that, a fatal error, being killed — means the message is still the
 * parent's to deal with.
 */
#[AsCommand(
    name: 'app:messenger:handle-one',
    description: 'Handle one queued message read from stdin, as a worker would (started by app:messenger:consume-isolated).',
    hidden: true,
)]
final class HandleOneMessageCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'messenger.routable_message_bus')]
        private readonly MessageBusInterface      $bus,
        #[Autowire(service: 'messenger.default_serializer')]
        private readonly SerializerInterface      $serializer,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'monolog.logger.messenger')]
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('transport', InputArgument::REQUIRED, 'The transport the message was taken from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport = (string) $input->getArgument('transport');
        // The input's own stream where it has one, which is how a test hands
        // it the message; the process's stdin otherwise.
        $stream    = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $raw       = stream_get_contents($stream ?? STDIN);

        /** @var array{body: string, headers?: array<string, mixed>} $encoded */
        $encoded  = json_decode(false === $raw ? '' : $raw, true, flags: JSON_THROW_ON_ERROR);
        $envelope = $this->serializer->decode($encoded);

        $received = new WorkerMessageReceivedEvent($envelope, $transport);
        $this->dispatcher->dispatch($received);

        if (false === $received->shouldHandle()) {
            return Command::SUCCESS;
        }

        $outcome = null;

        // Symfony's own strategy, so the dispatch and its acknowledgement work
        // exactly as in the worker; only what follows is this command's.
        $strategy = new SyncMessageExecutionStrategy(
            $this->bus,
            static function (string $transportName, Envelope $handled, ?\Throwable $error) use (&$outcome): void {
                $outcome = [$handled, $error];
            },
        );

        $strategy->execute(
            $received->getEnvelope()->with(new ReceivedStamp($transport), new ConsumedByWorkerStamp()),
            $transport,
            static function (Envelope $handled, string $transportName, bool $acked, ?\Throwable $error) use (&$outcome): void {
                if (false === $acked) {
                    $outcome = [$handled, $error];
                }
            },
        );

        [$handled, $error] = $outcome ?? [$envelope, null];

        if (null !== $error) {
            if ($error instanceof EnvelopeAwareExceptionInterface && null !== $error->getEnvelope()) {
                $handled = $error->getEnvelope();
            }

            $this->dispatcher->dispatch(new WorkerMessageFailedEvent($handled, $transport, $error));

            return Command::SUCCESS;
        }

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($handled, $transport));

        $this->logger->info('{class} was handled successfully (acknowledging to transport).', [
            'class' => $handled->getMessage()::class,
        ]);

        return Command::SUCCESS;
    }
}
