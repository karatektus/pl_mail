<?php

declare(strict_types=1);

namespace App\Service\Calendar\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\CalendarPushSubscriptionManagerInterface;
use App\Entity\Calendar\Calendar;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the push manager for a calendar, or null when there is none.
 *
 * The house shape, third instance: managers arrive as a tagged iterator and the
 * first that claims the calendar wins, exactly as MailSenderRegistry,
 * IntegrationDriverRegistry and CalendarSyncDriverRegistry do. Adding a
 * provider is a class in this directory, not a branch here.
 *
 * Null rather than an exception, matching PushSubscriptionRegistry: a CalDAV
 * calendar has no push and neither does a hand-made local one, and every caller
 * — the sweep, a teardown — wants to skip quietly. A missing manager is the
 * normal case in an install that mirrors one CalDAV server, not an error in it.
 *
 * Placed under Service/Calendar/Push rather than beside each provider's sync
 * driver, which is where §5.5 would otherwise put an implementation. The
 * alternative was Service/Calendar/Sync/Google/GoogleCalendarPushManager, and
 * it was rejected because that directory means "the sync driver and the pieces
 * it is assembled from" — a push manager is a different lifecycle with a
 * different trigger, and a reader opening Sync/ to understand a pull should not
 * meet channel registration on the way.
 */
final readonly class CalendarPushRegistry
{
    /**
     * @param iterable<CalendarPushSubscriptionManagerInterface> $managers
     */
    public function __construct(
        #[AutowireIterator('app.calendar_push_manager')]
        private iterable $managers,
    ) {}

    public function resolve(Calendar $calendar): ?CalendarPushSubscriptionManagerInterface
    {
        foreach ($this->managers as $manager) {
            if (true === $manager->supports($calendar)) {
                return $manager;
            }
        }

        return null;
    }

    public function supportsPush(Calendar $calendar): bool
    {
        return null !== $this->resolve($calendar);
    }

    /**
     * Health for any calendar, including the ones no manager claims.
     */
    public function health(Calendar $calendar): PushHealth
    {
        return $this->resolve($calendar)?->health($calendar) ?? PushHealth::Inactive;
    }
}
