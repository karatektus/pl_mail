<?php

namespace App\Infrastructure\Messaging\Message;

use DateTimeImmutable;
use ReflectionProperty;

/**
 * One queued send of one message, for one particular release time.
 *
 * `sendAt` is the row's submission_send_at as it stood when this envelope was
 * dispatched, and the claim only succeeds while the row still says the same.
 * That is what makes an envelope belong to ONE schedule: cancel a hold and set
 * a new one and the old DelayStamp envelope is still sitting in the queue —
 * it used to come due at the old time, find `cancelled` lowered again by the
 * reschedule, and send the mail hours early. Now it finds a different
 * submission_send_at and does nothing. Null is a real value here (a web
 * composer send has no submission time), not "unknown".
 */
readonly class SendMessageMessage
{
    public function __construct(
        public int                $messageId,
        public ?DateTimeImmutable $sendAt = null,
    ) {
    }

    /**
     * Whether this envelope names the schedule it belongs to.
     *
     * False only for envelopes serialised before `sendAt` existed: unserialize
     * does not run the constructor, so the property is left uninitialised
     * rather than null. Those are handled as they always were, without the
     * check, instead of failing on the first read of the property.
     */
    public function pinsSendAt(): bool
    {
        return new ReflectionProperty(self::class, 'sendAt')->isInitialized($this);
    }
}
