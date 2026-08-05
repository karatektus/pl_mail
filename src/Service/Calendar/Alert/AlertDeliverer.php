<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Interface\AlertChannelInterface;
use App\Repository\Calendar\CalendarAlertDeliveryRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sending one alert, exactly once.
 *
 * ── Claim first, then send ────────────────────────────────────────────────
 *
 * The order is the design. The row that says "this alert has gone off" is
 * written BEFORE anything is sent, in a single `INSERT … ON CONFLICT DO NOTHING`
 * that either succeeds or reveals that somebody else already owns this alert.
 * Every alternative loses:
 *
 *   Send, then record. The process dies between the two — a deploy, an OOM kill,
 *   a push service that takes forty seconds — and the next sweep, a minute
 *   later, sends it again. This is the failure people actually hit, because a
 *   sweep that runs every minute has sixty chances an hour to be interrupted.
 *
 *   Read, decide, insert. Two sweeps overlapping both read nothing and both
 *   send; the constraint then rejects one of them, after it has already
 *   notified. A check is not a lock.
 *
 * So a delivery that fails is a delivery that is lost, and that is chosen
 * rather than tolerated: an alert is a statement about the near future
 * ("in ten minutes"), and one redelivered fifteen minutes later is worse than
 * one that never came. The failure is logged; nothing retries it.
 *
 * ── Degrading ─────────────────────────────────────────────────────────────
 *
 * An install with no VAPID keys, a user who never granted notification
 * permission, a user who deleted their last mail account — all three are
 * ordinary states, not errors, and all three end here as a logged warning and a
 * claim that stays claimed. Leaving the claim off would mean re-attempting the
 * same impossible delivery every minute for an hour and writing a warning each
 * time, which turns a missing feature into a log nobody can read.
 *
 * There is deliberately no fallback between channels. A display alert on an
 * install with no push is not turned into mail: the user asked for a
 * notification, and a service that quietly mails you instead is a service you
 * stop trusting about what it will do with your address.
 */
final readonly class AlertDeliverer
{
    /**
     * @param iterable<AlertChannelInterface> $channels
     */
    public function __construct(
        #[AutowireIterator('app.alert_channel')]
        private iterable                        $channels,
        private CalendarAlertDeliveryRepository $deliveries,
        private LoggerInterface                 $logger,
    ) {
    }

    /**
     * @return bool whether this call is the one that owned the alert — true even
     *              when no channel could deliver it, because the claim is what
     *              "handled" means here and the caller counts sweeps, not
     *              notifications
     */
    public function deliver(DueAlert $due, ?DateTimeImmutable $now = null): bool
    {
        if (false === $this->deliveries->claim($due, $now)) {
            return false;
        }

        $channel = $this->channelFor($due);

        if (null === $channel) {
            // Only reachable if an AlertAction case gains no channel, which is a
            // wiring mistake rather than a state — hence error, not warning.
            $this->logger->error('CalendarAlert: no channel for action', [
                'action'  => $due->alert->action->value,
                'eventId' => $due->eventId,
            ]);

            return true;
        }

        // Belt to the braces each channel already wears. AlertChannelInterface
        // says deliver() answers false rather than throwing, and both channels
        // honour it — but the sweep runs every minute over every user, and a
        // channel that throws anyway ends the whole batch rather than one
        // alert. EmailAlertChannel did exactly that until its message
        // construction moved inside its own try: an account whose username is
        // not an address threw RfcComplianceException from `new Address()`, and
        // one such account silently stopped reminders for everybody.
        //
        // Returning true, not false: the claim is already written, so the alert
        // is spent whatever happened here, and answering false would only make
        // the caller count it as undelivered.
        try {
            $delivered = $channel->deliver($due);
        } catch (\Throwable $e) {
            $this->logger->error('CalendarAlert: the channel threw instead of refusing', [
                'userId'  => $due->userId,
                'eventId' => $due->eventId,
                'action'  => $due->alert->action->value,
                'channel' => $channel::class,
                'error'   => $e->getMessage(),
            ]);

            return true;
        }

        if (false === $delivered) {
            $this->logger->warning('CalendarAlert: nowhere to deliver it', [
                'userId'  => $due->userId,
                'eventId' => $due->eventId,
                'action'  => $due->alert->action->value,
                'alert'   => $due->alert->key,
            ]);
        }

        return true;
    }

    private function channelFor(DueAlert $due): ?AlertChannelInterface
    {
        foreach ($this->channels as $channel) {
            if (true === $channel->supports($due->alert->action)) {
                return $channel;
            }
        }

        return null;
    }
}
