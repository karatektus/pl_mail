<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * What counts as a timezone around here.
 *
 * Deliberately narrower than DateTimeZone's own constructor, which happily
 * accepts `+02:00` and `CEST`. A fixed offset is wrong for anything stored and
 * re-read across a DST boundary — it renders March correctly and August an
 * hour out — and an abbreviation is ambiguous between hemispheres. Only the
 * IANA region/city identifiers carry the rules that make a stored UTC instant
 * come back as the right wall clock all year, so those are the only ones a
 * user's preference or the configured default may hold.
 */
final class TimezoneHelper
{
    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public static function isKnown(?string $identifier): bool
    {
        if (null === $identifier || '' === $identifier) {
            return false;
        }

        return in_array($identifier, self::identifiers(), true);
    }

    /**
     * The same list keyed by region, for a <select> that would otherwise be
     * four hundred flat options.
     *
     * Identifiers without a region — `UTC` is the one that matters — are keyed
     * under themselves rather than dropped, so UTC stays pickable for someone
     * who genuinely wants it.
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::identifiers() as $identifier) {
            $region = str_contains($identifier, '/')
                ? substr($identifier, 0, (int) strpos($identifier, '/'))
                : $identifier;

            $grouped[$region][] = $identifier;
        }

        return $grouped;
    }
}
