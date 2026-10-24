<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Middleware;

use App\Domain\Exception\GmailThrottledException;
use App\Domain\Exception\GraphThrottledException;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Infrastructure\Messaging\Stamp\ThrottleDeferralStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Decides what happens to a sync job when a mail provider is still
 * rate-limiting after every retry.
 *
 * WHAT USED TO HAPPEN, to every one of them alike: Messenger logged the
 * exhausted job at CRITICAL with two nested stack traces and parked it on the
 * failed queue. That is the top log level, spent on a condition that is not a
 * fault in this application — a shared quota was busy for five minutes — and
 * it treated two very different jobs the same way, which was wrong for both.
 *
 *   SyncAccountMessage is "see what is new". It is asked again every fifteen
 *   minutes by the schedule and on every push, and it resumes from a cursor
 *   that only moves when it succeeds. Given up on, it has lost nothing; the
 *   parked copy is a job nobody should ever retry, and the CRITICAL is an
 *   alarm about a delay. So: one warning, and the job is dropped.
 *
 *   The message BATCHES are "fetch these particular messages", queued after
 *   the cursor has already moved past them. Nothing lists them again. Given up
 *   on, they are mail that is in the mailbox and not in plMail until somebody
 *   finds the job on the failed queue and retries it by hand — the one case
 *   here that costs mail, and it was reported in exactly the same words as the
 *   harmless one. So: put back to wait a quarter of an hour, with a fresh set
 *   of retries, and a warning.
 *
 * NOT FOR EVER. A batch is deferred a bounded number of times — about two and
 * a half hours in all — and after that it fails the way it always did, loudly
 * and onto the failed queue. A quota that has not cleared in that long is not
 * a busy minute, it is something an administrator needs to see.
 *
 * A MIDDLEWARE because this is the only place that knows both things the
 * decision needs: what the handler threw, and which attempt this was. The
 * handler sees the first and not the second. It acts only on a message a
 * worker received, on its last attempt, when every failure was throttling —
 * anything else passes through untouched, to Messenger's own retry ladder.
 *
 * The sender is the ingest transport itself rather than the bus, which is
 * where both batch messages are routed: this class is PART of the bus, and
 * asking for the bus here would be asking for itself.
 *
 * ## Gmail's quota is not a failure at all
 *
 * Everything above is about the LAST attempt. A Gmail quota refusal is dealt
 * with on the first, and for every kind of job alike: the same message goes
 * back onto the queue it came from, to be handled when the account's minute
 * is over, and one line is logged at `info`.
 *
 * Being refused is how plMail learns what its quota is (see GmailQuotaPacer),
 * so on a project with a low limit it is certain to happen during an import
 * — and to whatever else is using the same mailbox's quota at that moment. A
 * label change pushed to Gmail while an import was running was refused, and
 * Messenger's own retry did the right thing in the loudest way it has: a
 * WARNING carrying two stack traces, per attempt (#90).
 *
 * The retry that replaces it is not counted against the job's own ladder,
 * because nothing about the job went wrong. It is bounded all the same:
 * after QUOTA_WAITS of them in a row the refusal is handed to the ladder like
 * any other failure, and from there to everything above.
 */
final readonly class GiveUpQuietlyOnThrottling implements MiddlewareInterface
{
    /**
     * The `max_retries` of the ingest transport in messenger.yaml.
     *
     * Repeated here rather than read from the retry strategy, which is a
     * per-transport service behind a locator. If the two ever disagree the
     * failure is soft in both directions: too low and a job is deferred a
     * retry or two early, too high and this never fires and the old behaviour
     * returns.
     */
    public const int MAX_RETRIES = 5;

    /** How long a batch waits before it is tried again. */
    public const int DEFER_SECONDS = 900;

    /** How many times, before it is allowed to fail in earnest. */
    public const int MAX_DEFERRALS = 10;

    /**
     * How many times in a row a job waits out Gmail's quota before the
     * refusal counts as a failure. An hour of minutes: a quota that is still
     * exhausted then is being spent by something this application cannot see.
     */
    public const int QUOTA_WAITS = 60;

    /**
     * @param ContainerInterface $transports every transport by name, to put a
     *                                       job back where it came from
     */
    public function __construct(
        #[Autowire(service: 'messenger.transport.ingest')]
        private SenderInterface $ingest,
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
            if (true === $this->waitsOutGmailQuota($envelope, $failure)) {
                return $envelope;
            }

            if (false === $this->isLastAttempt($envelope) || false === $this->isThrottling($failure)) {
                throw $failure;
            }

            $message = $envelope->getMessage();

            if ($message instanceof SyncAccountMessage) {
                $this->logger->warning('Sync skipped: the mail provider is rate-limiting this account. The next sync resumes from the same point.', [
                    'accountId' => $message->accountId,
                    'error'     => $failure->getMessage(),
                ]);

                // Returned, which the worker reads as handled: acknowledged,
                // not retried, not parked.
                return $envelope;
            }

            if ($message instanceof SyncGmailMessageBatchMessage || $message instanceof SyncGraphMessageBatchMessage) {
                $stamp     = $envelope->last(ThrottleDeferralStamp::class);
                $deferrals = null === $stamp ? 0 : $stamp->count;

                if ($deferrals >= self::MAX_DEFERRALS) {
                    throw $failure;
                }

                // A NEW envelope around the same message, so the retry count
                // starts again: the point of waiting is to try properly
                // afterwards, not to arrive with one attempt left.
                $this->ingest->send(new Envelope($message, [
                    new DelayStamp(self::DEFER_SECONDS * 1000),
                    new ThrottleDeferralStamp($deferrals + 1),
                ]));

                $this->logger->warning('Mail fetch put back: the mail provider is rate-limiting this account. Trying again in a quarter of an hour.', [
                    'accountId' => $message->accountId,
                    'messages'  => count($message instanceof SyncGmailMessageBatchMessage ? $message->gmailIds : $message->graphIds),
                    'deferral'  => $deferrals + 1,
                    'of'        => self::MAX_DEFERRALS,
                ]);

                return $envelope;
            }

            throw $failure;
        }
    }

    /**
     * Put a job Gmail refused on quota back onto its own queue, to run when
     * the refusal has cleared.
     *
     * @return bool whether that was done; false leaves the failure to
     *              everything else this class and Messenger do with one
     */
    private function waitsOutGmailQuota(Envelope $envelope, HandlerFailedException $failure): bool
    {
        $received = $envelope->last(ReceivedStamp::class);
        $wrapped  = $failure->getWrappedExceptions();

        // One handled inline has no queue to go back to and fails to its
        // caller, as before.
        if (null === $received || [] === $wrapped) {
            return false;
        }

        $delay = 0;

        foreach ($wrapped as $exception) {
            if (false === $exception instanceof GmailThrottledException) {
                return false;
            }

            $delay = max($delay, $exception->getRetryDelay());
        }

        $stamp = $envelope->last(ThrottleDeferralStamp::class);
        $waits = null === $stamp ? 0 : $stamp->count;

        if ($waits >= self::QUOTA_WAITS || false === $this->transports->has($received->getTransportName())) {
            return false;
        }

        $transport = $this->transports->get($received->getTransportName());

        if (false === $transport instanceof SenderInterface) {
            return false;
        }

        $message = $envelope->getMessage();

        // A few seconds past the refusal's end, and not the same few for
        // every job: the ones refused together would otherwise all come due
        // in the same second and be refused together again.
        $delay += 1000 * random_int(1, 10);

        // A NEW envelope around the same message. The job's own retries are
        // for its own failures and this was not one.
        $transport->send(new Envelope($message, [
            new DelayStamp($delay),
            new ThrottleDeferralStamp($waits + 1),
        ]));

        $this->logger->info('Put back: waiting for Gmail quota', [
            'class'        => $message::class,
            'queue'        => $received->getTransportName(),
            'delaySeconds' => intdiv($delay, 1000),
            'wait'         => $waits + 1,
            'of'           => self::QUOTA_WAITS,
        ]);

        return true;
    }

    /**
     * Only a job a worker took off a transport has a retry ladder to be at the
     * end of. One handled inline, in the process that dispatched it, has no
     * redelivery at all and must fail to its caller.
     */
    private function isLastAttempt(Envelope $envelope): bool
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return false;
        }

        return RedeliveryStamp::getRetryCountFromEnvelope($envelope) >= self::MAX_RETRIES;
    }

    /**
     * Every wrapped failure, not any of them: a job that was throttled AND
     * broke some other way has a second problem that must not be waved through
     * on the strength of the first.
     */
    private function isThrottling(HandlerFailedException $failure): bool
    {
        $wrapped = $failure->getWrappedExceptions();

        if ([] === $wrapped) {
            return false;
        }

        foreach ($wrapped as $exception) {
            if (false === $exception instanceof GmailThrottledException
                && false === $exception instanceof GraphThrottledException) {
                return false;
            }
        }

        return true;
    }
}
