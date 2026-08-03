<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\User\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells an open page that its calendar moved.
 *
 * Carries no state beyond "look again", like RuleRunNotifier: extraction can
 * create, update or cancel several events across several calendars in one job,
 * and describing that in a payload would be a second, subtly different
 * implementation of what the page already knows how to render.
 *
 * Every publish is guarded. A hub that is down must never fail the extraction
 * that has already succeeded — the events are in the database either way, and
 * the page will catch up on its next navigation.
 */
final readonly class CalendarNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishCalendarChanged(User $user): void
    {
        $id = $user->id;

        if (null === $id) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $id)],
                data: json_encode(['type' => 'calendar.changed'], JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('CalendarNotifier: publish failed', [
                'userId' => $id,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
