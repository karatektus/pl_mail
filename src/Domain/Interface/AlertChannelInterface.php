<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;

/**
 * One way an alert reaches a person.
 *
 * The axis that varies here is RFC 8984's `action`, which is why supports()
 * takes the enum and not the whole alert: a channel is chosen by what the alert
 * asked for, never by what happens to be configured. A display alert on an
 * install with no VAPID keys is an undelivered display alert, not a silent
 * email — the user asked for a notification and turning it into mail would be
 * this layer inventing a preference.
 *
 * Implementations live under Service/Calendar/Alert/ and are tagged by
 * _instanceof, so adding a channel is a class rather than an edit to a match.
 */
interface AlertChannelInterface
{
    public function supports(AlertAction $action): bool;

    /**
     * @return bool whether it actually went somewhere — false means the channel
     *              had nowhere to send it (no subscribed device, no mail
     *              account, nothing configured), which is a fact to log rather
     *              than an error to raise
     */
    public function deliver(DueAlert $due): bool;
}
