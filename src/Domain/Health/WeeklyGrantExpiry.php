<?php

declare(strict_types=1);

namespace App\Domain\Health;

use DateTimeImmutable;

/**
 * Google's seven-day refresh token, recognised from how long a sign-in lasted.
 *
 * WHAT THIS IS ABOUT. An OAuth app whose consent screen is still in "Testing"
 * publishing status gets refresh tokens that expire after about a week —
 * Google's policy, not something an application can opt out of. Nearly every
 * self-hosted install is in Testing, because leaving it there is the documented
 * way to avoid Google's verification review for scopes of Gmail's reach. So the
 * account works, stops seven days later, gets reconnected, and stops again.
 *
 * The provider says none of this. What arrives is `invalid_grant`, which is the
 * same thing it says for a revoked consent, a changed password and a token that
 * aged out — four different causes, one error code, and only one of them is
 * fixable by changing a setting rather than by signing in again every week.
 *
 * WHAT SEPARATES THEM IS THE INTERVAL, and nothing else. A password change is a
 * one-off; the Testing limit is a metronome. So this class does not detect a
 * publishing status — nothing can, from here — it recognises a *duration*, and
 * says so in language that admits it is an inference.
 *
 * THE WINDOW IS WIDE ON PURPOSE. The limit is documented as seven days, but the
 * moment a dead grant is NOTICED is whenever the account next syncs, which is
 * not the moment it died. A band from four to nine and a half days accepts that
 * slack. It is still narrow enough to exclude the thing it must exclude: an
 * account that ran for months and then had its consent revoked, which is not
 * this and must not be told it is.
 */
final readonly class WeeklyGrantExpiry
{
    /** Below this, something else went wrong — a bad clock, a botched connect. */
    private const int SHORTEST_HOURS = 96;

    /** Above this it is not the weekly limit, whatever else it is. */
    private const int LONGEST_HOURS = 228;

    /**
     * How old the current grant must be before it is worth warning about.
     *
     * Five days leaves roughly two before the limit — enough notice to act on,
     * late enough that the warning is not sitting there for most of the week
     * being ignored. A warning that is visible five days out of seven is
     * furniture.
     */
    private const int WARN_AFTER_HOURS = 120;

    /** Whether a sign-in that lasted this long lasted about a week. */
    public static function looksWeekly(?int $hours): bool
    {
        return null !== $hours
            && self::SHORTEST_HOURS <= $hours
            && self::LONGEST_HOURS >= $hours;
    }

    /**
     * How long a grant issued at $grantedAt has been alive, in whole hours.
     *
     * Null when nothing was recorded, which is every account connected before
     * the column existed. Callers treat that as "no opinion" rather than as
     * zero — an account that predates this must not be told anything about a
     * pattern nobody observed.
     */
    public static function ageInHours(?DateTimeImmutable $grantedAt, DateTimeImmutable $now): ?int
    {
        if (null === $grantedAt) {
            return null;
        }

        return intdiv(max(0, $now->getTimestamp() - $grantedAt->getTimestamp()), 3600);
    }

    /**
     * Whether this sign-in is expected to die shortly, going by the last one.
     *
     * Both halves are required. A previous grant that lasted about a week is
     * the evidence; the current one being five days old is what makes it
     * imminent rather than academic. Neither alone would be worth a card:
     * without the history this would fire on every healthy Google account every
     * week, and without the age it would fire the moment somebody reconnected.
     */
    public static function isDueToExpire(
        ?DateTimeImmutable $grantedAt,
        ?int $priorGrantHours,
        DateTimeImmutable $now,
    ): bool {
        if (false === self::looksWeekly($priorGrantHours)) {
            return false;
        }

        $age = self::ageInHours($grantedAt, $now);

        return null !== $age && self::WARN_AFTER_HOURS <= $age;
    }
}
