<?php

declare(strict_types=1);

namespace App\Tests\Support\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\CalendarPushSubscriptionManagerInterface;
use App\Entity\Calendar\Calendar;
use DateTimeImmutable;

/**
 * Records which calendars had their push registration handed back.
 *
 * The calendar counterpart of ScriptedPushManager, and it exists for the gap
 * that class's docblock does not cover: no caller anywhere reached
 * GoogleCalendarPushManager::unsubscribe() or GraphCalendarPushManager::
 * unsubscribe() on a calendar-REMOVAL path. Deleting a calendar, unticking one
 * on the subscribe screen and disconnecting a whole connection all dropped the
 * row and left the provider's channel live.
 *
 * Claims only calendars whose remoteId carries MARKER — see ScriptedPush-
 * Manager on why the marker is not optional.
 */
final class ScriptedCalendarPushManager implements CalendarPushSubscriptionManagerInterface
{
    public const string MARKER = 'scripted-push-calendar';

    /** @var list<string> remoteIds, in the order they were revoked */
    public array $revoked = [];

    public function supports(Calendar $calendar): bool
    {
        return str_contains((string) $calendar->remoteId, self::MARKER);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function subscribe(Calendar $calendar): bool
    {
        return true;
    }

    public function renew(Calendar $calendar): bool
    {
        return true;
    }

    public function needsRenewal(Calendar $calendar): bool
    {
        return false;
    }

    public function expiresAt(Calendar $calendar): ?DateTimeImmutable
    {
        return null;
    }

    public function health(Calendar $calendar): PushHealth
    {
        return PushHealth::Active;
    }

    public function unsubscribe(Calendar $calendar): void
    {
        $this->revoked[] = (string) $calendar->remoteId;
    }
}
