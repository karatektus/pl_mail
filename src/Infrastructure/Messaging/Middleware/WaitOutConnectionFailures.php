<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Middleware;

use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Stamp\ConnectionWaitStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Puts an account sync back to wait when the mail provider could not be
 * reached at all, instead of failing it.
 *
 * WHAT USED TO HAPPEN: the worker could not open a connection to
 * gmail.googleapis.com for twelve seconds, the HttpClient's TransportException
 * came out of the handler, and Messenger's retry ladder took it — correctly,
 * and with a WARNING carrying three nested stack traces per attempt, and a
 * CRITICAL and a parked job at the end of it if the outage lasted the eight
 * minutes the ladder spans (#332). That is the loudest Messenger gets, spent
 * on a network outage that is not a fault in this application and that the
 * next sync recovers from by itself.
 *
 * ONLY SyncAccountMessage, for the reason GiveUpQuietlyOnThrottling gives:
 * "see what is new" resumes from a cursor that only moves when it succeeds,
 * and is asked for again every quarter of an hour. Given up on, it has lost
 * nothing. The message batches are the opposite — queued after the cursor
 * moved past them, listed again by nothing — so a batch that cannot connect
 * keeps the ladder and its noise, because there the noise is about mail.
 *
 * ONLY a connection failure, and only when every wrapped failure is one. A
 * TransportExceptionInterface is HttpClient's word for "no HTTP answer at
 * all": refused, timed out, DNS, TLS. Any status the provider did answer with
 * is a different exception and a different decision (see GmailApiClient). A
 * sync that could not connect AND failed some other way has a second problem
 * that must not be waved through on the strength of the first.
 *
 * The retry is this class's own, on a NEW envelope, so Messenger never logs
 * one: the same ladder as the ingest transport (5 s, ×3, at most 5 min), each
 * step a single `info` line. After MAX_WAITS of them the sync is dropped with
 * one warning, which is the line an administrator should see when the network
 * has been gone for a while. Its $requestedAt goes along unchanged, so a sync
 * that got through in the meantime makes the waiting one redundant like any
 * other.
 *
 * The handler has already written the failure onto the account by the time
 * this sees it (SyncAccountMessageHandler::recordFailure), so the account
 * still shows that its last sync did not go through. That is true and stays.
 */
final readonly class WaitOutConnectionFailures implements MiddlewareInterface
{
    /** How many times a sync waits before it is dropped: the ingest ladder's length. */
    public const int MAX_WAITS = 5;

    /** The ingest transport's retry_strategy in messenger.yaml, repeated. */
    private const int FIRST_DELAY_MS = 5000;
    private const int MULTIPLIER = 3;
    private const int MAX_DELAY_MS = 300000;

    /**
     * @param ContainerInterface $transports every transport by name, to put a
     *                                       job back where it came from
     */
    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $transports,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $failure) {
            $message  = $envelope->getMessage();
            $received = $envelope->last(ReceivedStamp::class);

            // One handled inline has no queue to go back to and fails to its
            // caller, as before.
            if (false === $message instanceof SyncAccountMessage || null === $received || false === $this->couldNotConnect($failure)) {
                throw $failure;
            }

            $stamp = $envelope->last(ConnectionWaitStamp::class);
            $waits = null === $stamp ? 0 : $stamp->count;

            if ($waits >= self::MAX_WAITS) {
                $this->logger->warning('Sync skipped: the mail provider could not be reached. The next sync resumes from the same point.', [
                    'accountId' => $message->accountId,
                    'error'     => $failure->getMessage(),
                ]);

                // Returned, which the worker reads as handled: acknowledged,
                // not retried, not parked.
                return $envelope;
            }

            if (false === $this->transports->has($received->getTransportName())) {
                throw $failure;
            }

            $transport = $this->transports->get($received->getTransportName());

            if (false === $transport instanceof SenderInterface) {
                throw $failure;
            }

            // A few seconds more, and not the same few for every account: an
            // outage fails all of them in the same minute, and they would
            // otherwise all come back in the same second.
            $delay = min(self::FIRST_DELAY_MS * self::MULTIPLIER ** $waits, self::MAX_DELAY_MS)
                + 1000 * random_int(1, 10);

            $transport->send(new Envelope($message, [
                new DelayStamp($delay),
                new ConnectionWaitStamp($waits + 1),
            ]));

            $this->logger->info('Sync put back: the mail provider could not be reached', [
                'accountId'    => $message->accountId,
                'delaySeconds' => intdiv($delay, 1000),
                'wait'         => $waits + 1,
                'of'           => self::MAX_WAITS,
                'error'        => $failure->getMessage(),
            ]);

            return $envelope;
        }
    }

    private function couldNotConnect(HandlerFailedException $failure): bool
    {
        $wrapped = $failure->getWrappedExceptions();

        if ([] === $wrapped) {
            return false;
        }

        foreach ($wrapped as $exception) {
            if (false === $exception instanceof TransportExceptionInterface) {
                return false;
            }
        }

        return true;
    }
}
