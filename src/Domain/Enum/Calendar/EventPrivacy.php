<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * JSCalendar "privacy" (RFC 8984 §4.4.3).
 *
 * Unused by the calendar itself — the owner sees everything they own. It exists
 * from the start because the shared-calendar feature turns it into an access
 * decision, and retrofitting a privacy default onto rows that predate it means
 * guessing what the user meant. Secret must never leave the account; Private
 * shows as busy with no detail.
 */
enum EventPrivacy: string
{
    case Public  = 'public';
    case Private = 'private';
    case Secret  = 'secret';
}
