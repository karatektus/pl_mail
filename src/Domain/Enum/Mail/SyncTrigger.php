<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What made plMail go and look at a mailbox.
 *
 * Recorded on each message as it is stored (Message::$arrivedBy), because it is
 * the one fact Admin → Performance could not reconstruct afterwards. A message
 * that turned up fourteen minutes late is one of two very different things:
 * mail the provider never announces — spam, mail filtered past the inbox —
 * collected on schedule exactly as designed, or inbox mail whose announcement
 * went missing. The delay is the same number in both cases; this is what tells
 * them apart.
 *
 * It names what started the sync that happened to store the message, which is
 * not always what the message was waiting for. A push for one message brings
 * in everything that changed since the last look, so spam that would otherwise
 * have waited for the schedule can arrive "by push". That is still the truth
 * about how it got here.
 */
enum SyncTrigger: string
{
    /** The provider said so: Gmail Pub/Sub, a Graph subscription. */
    case Push = 'push';

    /** An IMAP connection held open with IDLE saw the folder change. */
    case Idle = 'idle';

    /** The quarter-hourly `app:mail:sync`. */
    case Poll = 'poll';

    /** Somebody pressed the sync button. */
    case Manual = 'manual';
}
