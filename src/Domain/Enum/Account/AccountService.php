<?php

declare(strict_types=1);

namespace App\Domain\Enum\Account;

/**
 * One of the three things an account can be used for, each switched on and
 * off on its own (#575).
 *
 * An account used to be all or nothing: the one switch in Settings paused the
 * mail, the contacts went with it, and the calendars — which never read that
 * switch — carried on regardless. A person who wanted Gmail only for its
 * calendar had no way to say so. Three switches say it, and each one means the
 * same thing for its service: off is "plMail stops talking to the server about
 * this", never "plMail forgets what it already has". Mail already fetched stays
 * readable, calendars keep their events, address books keep their cards; only
 * the traffic in both directions stops. Changes made locally while a service is
 * off are not sent anywhere. Calendar and contact edits wait and go out when
 * the service is switched back on, because both engines push what is pending
 * before they pull; a contact removed outright has no row left to wait on, and
 * comes back with the first pull. Mail changes stay local for good, because a
 * mail action is sent the moment it is made and there is no pending queue to
 * hold it — the next sync after switching mail back on reads the server's
 * state over them.
 *
 * Three booleans on Account rather than a set column, so every query that has
 * to ask (the sync sweeps, the sidebar, the badge counts) asks a plain column
 * the way it always asked `is_active`, and a database reader sees the state
 * without decoding anything.
 */
enum AccountService: string
{
    case Mail     = 'mail';
    case Calendar = 'calendar';
    case Contacts = 'contacts';

    /** The translation key for the switch's label in Settings. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Mail     => 'settings.accounts.services.mail',
            self::Calendar => 'settings.accounts.services.calendar',
            self::Contacts => 'settings.accounts.services.contacts',
        };
    }

    /** FontAwesome glyph beside the switch, the one each area uses in the sidebar. */
    public function icon(): string
    {
        return match ($this) {
            self::Mail     => 'fa-solid fa-envelope',
            self::Calendar => 'fa-solid fa-calendar-days',
            self::Contacts => 'fa-solid fa-address-book',
        };
    }

    /**
     * Read a list of service names from a request, keeping only the ones that
     * exist. An empty or absent list means all three, which is what an account
     * connected without being asked has always been.
     *
     * @param list<string>|string|null $values
     *
     * @return list<self>
     */
    public static function fromList(array|string|null $values): array
    {
        if (true === is_string($values)) {
            $values = explode(',', $values);
        }

        $services = [];

        foreach ($values ?? [] as $value) {
            $service = self::tryFrom(trim((string) $value));

            if (null !== $service && false === in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return [] === $services ? self::cases() : $services;
    }
}
