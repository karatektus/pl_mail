<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

/**
 * One address book as the server describes it, before plMail has decided
 * whether to mirror it — RemoteCalendar's counterpart.
 *
 * @see \App\Domain\DTO\Calendar\RemoteCalendar
 */
final readonly class RemoteAddressBook
{
    /**
     * @param string $remoteId   absolute URL of the collection, stored verbatim
     *                           in AddressBook::$remoteId
     * @param bool   $isReadOnly true when the privilege set grants no write —
     *                           a shared book, a server's global address list.
     *                           Taken from the server, never from a form.
     */
    public function __construct(
        public string $remoteId,
        public string $name,
        public bool   $isReadOnly,
    ) {
    }
}
