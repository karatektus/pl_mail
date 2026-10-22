<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\AddressBookDriverInterface;
use App\Entity\AddressBook\AddressBook;

/**
 * Finds the driver that speaks for an address book's source — CardDAV for a
 * connection, the People API for a Google account, Graph for a Microsoft one.
 *
 * CalendarSyncDriverRegistry's counterpart, and permanent on a miss for its
 * reason: this is reached from the sweep, and a source no driver handles
 * would otherwise be retried every fifteen minutes forever.
 */
final readonly class AddressBookDriverRegistry
{
    /**
     * @param iterable<AddressBookDriverInterface> $drivers
     */
    public function __construct(
        private iterable $drivers,
    ) {
    }

    /**
     * @throws CalendarSyncPermanentException
     */
    public function for(AddressBookSource $source): AddressBookDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->handles($source)) {
                return $driver;
            }
        }

        throw new CalendarSyncPermanentException('No address-book service is configured for this account or connection.');
    }

    /**
     * @throws CalendarSyncPermanentException
     */
    public function forBook(AddressBook $book): AddressBookDriverInterface
    {
        return $this->for($book->source());
    }

    public function has(AddressBookSource $source): bool
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->handles($source)) {
                return true;
            }
        }

        return false;
    }
}
