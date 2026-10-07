<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Stop waiting for the assistant and show this mail where the rules put it.
 *
 * Dispatched WITH A DELAY at the moment a message is held, for exactly as long
 * as the hold is allowed to last — see Message::$categoryHeldAt and
 * AiSettings::$holdMaxSeconds. It is the promise that holding is bounded: the
 * classification job normally releases the message long before this is
 * delivered, and then this finds nothing held and does nothing.
 *
 * A message of its own rather than a timeout inside the classification job,
 * because the job is the thing that might be slow or might not happen. A model
 * that is still loading, a host that is off, a job sitting out a retry delay —
 * in every one of those the mail still has to appear, and this is what makes
 * it.
 *
 * IT DOES NOT STOP THE QUESTION. The classification job carries on, and when
 * the answer arrives it moves the mail if it differs from the rules'. Ending
 * the wait and giving up on the answer are different decisions, and only the
 * first is this message's.
 *
 * On a queue of its own, `release`, with a worker that does nothing else; see
 * messenger.yaml for why it must not share one with the call it is the limit
 * on, nor with anything else.
 */
final readonly class ReleaseHeldMailMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {}
}
