<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Middleware;

use App\Service\Imap\ImapDeadline;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Runs the IMAP deadline around every message a worker handles.
 *
 * A middleware rather than a line in each handler, for the reason the
 * deadline exists at all: the handler that stalled was not doing anything
 * unusual, and the next one to stall will not be on anybody's list. See
 * {@see ImapDeadline} for what expiring does and why.
 *
 * Only for a message that was RECEIVED — taken off a transport by a worker.
 * A dispatch from a web request passes through here on its way to the queue
 * and is not being handled; arming there would time a request, and would
 * install a signal handler in a process that is not a worker.
 */
final readonly class AbortStalledImap implements MiddlewareInterface
{
    public function __construct(
        private ImapDeadline $deadline,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->deadline->arm();

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->deadline->disarm();
        }
    }
}
