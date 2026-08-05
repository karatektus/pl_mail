<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Interface\AlertChannelInterface;

/**
 * A channel that writes down what it was asked to send instead of sending it.
 *
 * An implementation of the interface rather than a double, for the reason every
 * test in this suite avoids doubles: the real channels are final and cannot be
 * mocked, and the question worth asking — "how many times did this alert
 * actually go out?" — is answered by counting, not by asserting an expectation
 * into existence.
 *
 * $succeeds is what makes the failure cases expressible. A channel that answers
 * false is an install with no subscribed device and no mail account, which is
 * the ordinary state of a fresh install and must not turn into a delivery
 * retried every minute for an hour.
 */
final class RecordingAlertChannel implements AlertChannelInterface
{
    /** @var list<DueAlert> */
    public array $delivered = [];

    public function __construct(
        private readonly AlertAction $action = AlertAction::Display,
        private readonly bool        $succeeds = true,
    ) {
    }

    public function supports(AlertAction $action): bool
    {
        return $this->action === $action;
    }

    public function deliver(DueAlert $due): bool
    {
        $this->delivered[] = $due;

        return $this->succeeds;
    }
}
