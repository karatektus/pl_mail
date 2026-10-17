<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Middleware;

use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Hands ClassifyMailHandler the name of the queue its message came off.
 *
 * The same ClassifyMailMessage is live mail or bulk work depending on the mail
 * it carries, and only the code that dispatched it knew which — so it chose a
 * queue, and the queue is all of that decision that survives the trip
 * (EnrichmentRouter). The handler needs it back to apply the bulk-only age
 * limit (#208): reading it off the mail instead would mean reading the mail's
 * DATE, and a message delivered today with an old date is still live mail
 * somebody may be waiting on.
 *
 * A FLAG ON THE MESSAGE WAS THE OBVIOUS ALTERNATIVE, AND IT MISSES THE QUEUE
 * THAT EXISTS ON THE DAY OF THE UPGRADE. Envelopes serialised by the previous
 * build carry no such flag and would deserialise as "not bulk" — so the
 * hundreds of old messages already waiting on enrich_backlog would each still
 * cost their model call. The transport name is on every received envelope,
 * old or new.
 *
 * As a handler argument rather than state somewhere a handler can read:
 * Messenger passes HandlerArgumentsStamp's contents to the handler as extra
 * parameters, so the value is scoped to the one call and cannot leak into the
 * next message a long-running worker handles. The stamp is not sendable, so it
 * is never stored with a retry.
 *
 * Only for a message that was RECEIVED, like AbortStalledImap. A dispatch
 * passes through here on its way to a queue and is not being handled, and a
 * handler invoked directly — ReclassifyRecentHandler does that — gets no queue
 * and therefore no limit, which is right for a button somebody pressed. A
 * retry from the failure transport arrives as `failed` and is likewise not
 * limited: somebody chose to run it again.
 */
final readonly class TellClassificationItsQueue implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);

        if ($received instanceof ReceivedStamp && $envelope->getMessage() instanceof ClassifyMailMessage) {
            $envelope = $envelope->with(new HandlerArgumentsStamp([$received->getTransportName()]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
