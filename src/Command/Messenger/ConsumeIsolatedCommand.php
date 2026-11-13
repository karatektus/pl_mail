<?php

declare(strict_types=1);

namespace App\Command\Messenger;

use App\Infrastructure\Messaging\Execution\ChildProcessExecutionStrategy;
use App\Infrastructure\Messaging\Execution\IsolationKeys;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Repository\System\WorkerSettings\WorkerSettingsRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Worker;

/**
 * messenger:consume for one queue, with every message handled in a process of
 * its own, several at once (#610).
 *
 * What runs worker-ingest. See ChildProcessExecutionStrategy for why and how;
 * this is the wiring: Symfony's own Worker, given that strategy in place of
 * the default one that handles messages inline.
 *
 * Not messenger:consume with an option, because that command builds its
 * Worker itself and has no way to be handed a strategy. What it does around
 * the Worker that matters here is repeated: the memory limit as a listener,
 * the time limit as an option, and SIGTERM/SIGINT as a stop. The restart
 * signal (messenger:stop-workers) needs nothing, it is a listener the
 * container already registers for every worker.
 *
 * The Worker gets no logger. Its only lines are "handled successfully
 * (acknowledging to transport)" and the like, and here they would be said
 * about a message the child failed and already put on the retry ladder: the
 * child exits 0 for both, and the line would be wrong for one of them. The
 * child logs what happened to the message; this process logs only what
 * happened to the child.
 */
#[AsCommand(
    name: 'app:messenger:consume-isolated',
    description: 'Consume a queue with each message handled in a process of its own, so one that hangs cannot hold up the rest.',
)]
final class ConsumeIsolatedCommand extends Command implements SignalableCommandInterface
{
    /**
     * Jobs that are asked for again anyway: a child that dies on one of these
     * drops it instead of retrying it. The same two ImapDeadline lets go of.
     */
    private const array ASKED_AGAIN = [
        SyncAccountMessage::class,
        SyncImapMailboxMessage::class,
    ];

    /** How often the "jobs at once" setting is read again while running. */
    private const int SETTING_TTL_SECONDS = 30;

    private ?Worker $worker = null;

    private ?ChildProcessExecutionStrategy $strategy = null;

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface       $receivers,
        #[Autowire(service: 'messenger.routable_message_bus')]
        private readonly MessageBusInterface      $bus,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'messenger.default_serializer')]
        private readonly SerializerInterface      $serializer,
        #[Autowire(service: 'monolog.logger.messenger')]
        private readonly LoggerInterface          $logger,
        private readonly IsolationKeys            $keys,
        private readonly WorkerSettingsRepository $settings,
        private readonly ProcessHeartbeatService  $heartbeats,
        #[Autowire('%kernel.project_dir%')]
        private readonly string                   $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::REQUIRED, 'The transport to consume, e.g. ingest')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop taking new messages after this many seconds')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this process uses this much memory, e.g. 256M');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport = (string) $input->getArgument('transport');

        if (false === $this->receivers->has($transport)) {
            $output->writeln(sprintf('<error>No such transport: %s</error>', $transport));

            return Command::INVALID;
        }

        $this->strategy = new ChildProcessExecutionStrategy(
            command: fn (string $name): array => [PHP_BINARY, $this->projectDir . '/bin/console', 'app:messenger:handle-one', $name, '--no-interaction'],
            serializer: $this->serializer,
            keyOf: $this->keys->of(...),
            limit: $this->limitReader(),
            logger: $this->logger,
            whileBusy: $this->heartbeats->beatWhileBusy(...),
            askedAgain: self::ASKED_AGAIN,
        );

        $this->worker = new Worker(
            [$transport => $this->receivers->get($transport)],
            $this->bus,
            $this->dispatcher,
            null,
            messageExecutionStrategy: $this->strategy,
        );

        $subscribers = [];
        $memoryLimit = $input->getOption('memory-limit');

        if (null !== $memoryLimit) {
            $subscribers[] = new StopWorkerOnMemoryLimitListener($this->bytes((string) $memoryLimit), $this->logger);
        }

        foreach ($subscribers as $subscriber) {
            $this->dispatcher->addSubscriber($subscriber);
        }

        $timeLimit = $input->getOption('time-limit');

        $output->writeln(sprintf('Consuming %s, each message in a process of its own.', $transport));

        try {
            $this->worker->run([
                'time_limit' => null !== $timeLimit ? (int) $timeLimit : null,
            ]);
        } finally {
            foreach ($subscribers as $subscriber) {
                $this->dispatcher->removeSubscriber($subscriber);
            }

            $this->worker   = null;
            $this->strategy = null;
        }

        return Command::SUCCESS;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    /**
     * Stop taking messages, and give the children only the grace period: a
     * signal means the container is going away. The time limit and the
     * restart signal stop the Worker without coming through here, and then
     * the children may finish.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->strategy?->stop();
        $this->worker?->stop();

        return false;
    }

    /**
     * The admin's setting, read again every half a minute, so a change on
     * Admin → Performance takes effect without restarting anything.
     *
     * @return \Closure(): int
     */
    private function limitReader(): \Closure
    {
        $value  = WorkerSettingsRepository::DEFAULT_CONCURRENCY;
        $readAt = 0;

        return function () use (&$value, &$readAt): int {
            if (time() - $readAt >= self::SETTING_TTL_SECONDS) {
                $readAt = time();

                try {
                    $value = $this->settings->concurrency();
                } catch (\Throwable) {
                    // The database blinking must not stop the worker; the
                    // last value it read is as good as any.
                }
            }

            return $value;
        };
    }

    private function bytes(string $limit): int
    {
        $limit  = strtolower(trim($limit));
        $number = (float) $limit;

        return (int) match (substr($limit, -1)) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
