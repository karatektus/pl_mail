<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\Helper\TimezoneHelper;
use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single answer to "which wall clock is this user reading?".
 *
 * Every instant in this database is stored in UTC, so a zone has to be supplied
 * at each point one turns back into digits — a rendered date, a new calendar's
 * default. Scattering that decision is how it goes wrong in one place and not
 * the others, so it lives here and nowhere else.
 *
 * Explicitly NOT date_default_timezone_get(): frankenphp/conf.d/10-app.ini pins
 * PHP's default to UTC, which is correct for arithmetic and catastrophic as a
 * display default. Twig's |date filter fell back to it and every timestamp in
 * the app came out two hours early for anyone in Berlin, while looking for all
 * the world like it had been configured. A wrong-looking clock is at least
 * noticed; a plausible one that is off by the local UTC offset is not.
 */
final readonly class UserTimezoneResolver
{
    /**
     * Used only when the configured default is absent or not an IANA
     * identifier. Not UTC, for the reason above: the fallback has to be
     * somebody's real clock so a misconfiguration is visible.
     */
    public const string FALLBACK = 'Europe/Berlin';

    public function __construct(
        #[Autowire('%app.default_timezone%')]
        private string $defaultTimezone,
    ) {
    }

    /**
     * A null preference means "never chose one", not "chose UTC" — that is the
     * whole reason the column is nullable — so it resolves to the install's
     * default rather than to anything derived from the process.
     */
    public function resolve(?User $user): \DateTimeZone
    {
        return self::zone($user?->getTimezone()) ?? $this->defaultZone();
    }

    public function nameFor(?User $user): string
    {
        return $this->resolve($user)->getName();
    }

    public function defaultZone(): \DateTimeZone
    {
        return self::zone($this->defaultTimezone) ?? new \DateTimeZone(self::FALLBACK);
    }

    private static function zone(?string $identifier): ?\DateTimeZone
    {
        return true === TimezoneHelper::isKnown($identifier)
            ? new \DateTimeZone((string) $identifier)
            : null;
    }
}
