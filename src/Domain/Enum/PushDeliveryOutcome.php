<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What became of one attempt to wake one device.
 *
 * Four answers, and they exist because `PushSenderInterface::send()` collapses
 * them all into a bool. That is right for the dispatcher — nothing it does
 * differs between them — and useless for the person asking "did my phone get
 * that?", which is the question the delivery log exists to answer. The bool
 * says no; this says whether the token is gone, the transport is having a bad
 * day, or the deployment never had the keys to try.
 *
 * **Skipped is not a failure and must not be shown as one.** An install with
 * Firebase switched off produces one Skipped per Android device per state
 * change, and colouring those red would make a deliberate configuration look
 * like an outage — which is precisely the confusion the admin page exists to
 * end.
 *
 * SubscriptionDestroyed is kept apart from Failed for the opposite reason: it
 * is the only outcome that is also an irreversible act. The row is gone, the
 * user's device will never hear from us again until it re-registers, and a
 * support conversation that cannot distinguish it from a transient 500 starts
 * by re-deriving it from the logs.
 */
enum PushDeliveryOutcome: string
{
    /** The transport took it. Not proof it was displayed, only that it was accepted. */
    case Accepted = 'accepted';

    /** Refused, unreachable, or malformed — and the subscription was kept. */
    case Failed = 'failed';

    /** The address proved permanently dead (404/410, UNREGISTERED) and the row was removed. */
    case SubscriptionDestroyed = 'subscription-destroyed';

    /** Nothing was sent: the transport is unconfigured, or the row cannot address it. */
    case Skipped = 'skipped';

    /**
     * Whether this outcome means the device was reached.
     *
     * Stays a predicate rather than a `self::Accepted === $x` at each call
     * site, because two of the three failures are not the caller's business
     * to enumerate — a reader wants "did it arrive", and adding a fifth case
     * would otherwise have to be found in every comparison.
     */
    public function reachedTheDevice(): bool
    {
        return self::Accepted === $this;
    }

    /**
     * The semantic colour this outcome is drawn in, named rather than spelled
     * as classes: the admin table and the settings pane show the same four
     * words and must not disagree about which of them are alarming.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Accepted              => 'success',
            self::Failed                => 'danger',
            self::SubscriptionDestroyed => 'warning',
            self::Skipped               => 'neutral',
        };
    }
}
