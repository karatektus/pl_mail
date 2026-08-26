<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Jmap\Push\PushDispatcher;
use App\Jmap\State\StateManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Drains the pending state changes and pushes them, once per unit of work.
 *
 * Two entry points, because changes arrive from both directions:
 *   - kernel.terminate — a JMAP /set or a web-UI action; runs after the
 *     response is sent, so push latency never lands on the user.
 *   - worker.message.handled — a sync batch; there is no kernel.terminate in
 *     a messenger worker, so without this every synced message would be
 *     recorded and never announced.
 *
 * Draining on FAILED messages too is deliberate: a handler that threw may
 * still have committed rows before it died, and those changes are real.
 * Leaving them in the buffer would leak them into whatever message the worker
 * picks up next, attributing one account's changes to another's push.
 */
final readonly class JmapPushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StateManager $stateManager,
        private PushDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TerminateEvent::class => 'onTerminate',
            WorkerMessageHandledEvent::class => 'onWorkerMessage',
            WorkerMessageFailedEvent::class => 'onWorkerMessage',
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->flush();
    }

    public function onWorkerMessage(): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        $changed = $this->stateManager->drainDirty();

        if (0 === count($changed)) {
            return;
        }

        try {
            $this->dispatcher->dispatch($changed);
        } catch (\Throwable $exception) {
            // Never let a push failure surface as a request or handler error:
            // the data change already succeeded and is what matters.
            $this->logger->error('JMAP push dispatch failed', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
