<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;

/**
 * Finds the driver that speaks for a source.
 *
 * Same shape as MailSenderRegistry: drivers arrive as a tagged iterator and the
 * first that supports the source wins. Priority is therefore the tag's, not
 * this class's — a driver that claims too broadly steals another's calendars,
 * and the place to fix that is the driver's supports(), not an ordering here.
 *
 * A missing driver is a CalendarSyncPermanentException rather than the
 * RuntimeException MailSenderRegistry throws, and the difference is deliberate.
 * This is reached from a Messenger handler on a sweep over every calendar in
 * the install; an account whose provider has no driver would otherwise be
 * retried five times every fifteen minutes forever, and the permanent
 * classification is what turns that into one dead-lettered envelope and one
 * line in Calendar::$lastSyncError that a user can act on.
 */
final readonly class CalendarSyncDriverRegistry
{
    /**
     * @param iterable<CalendarSyncDriverInterface> $drivers
     */
    public function __construct(
        private iterable $drivers,
    ) {
    }

    /**
     * @throws CalendarSyncPermanentException
     */
    public function for(CalendarSource $source): CalendarSyncDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($source)) {
                return $driver;
            }
        }

        throw new CalendarSyncPermanentException(
            'No calendar service is configured for this account or connection.',
        );
    }

    /**
     * The driver behind a calendar already bound to a remote.
     *
     * Separate from for() because the failure is a different one and deserves
     * its own sentence: a calendar with neither an account nor an integration
     * behind it is not an unsupported provider, it is a local calendar that
     * something asked to sync.
     *
     * @throws CalendarSyncPermanentException
     */
    public function forCalendar(Calendar $calendar): CalendarSyncDriverInterface
    {
        $source = CalendarSource::ofCalendar($calendar);

        if (null === $source) {
            throw new CalendarSyncPermanentException(
                'This calendar is not connected to anything, so there is nothing to sync it with.',
            );
        }

        return $this->for($source);
    }

    public function has(CalendarSource $source): bool
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($source)) {
                return true;
            }
        }

        return false;
    }
}
