<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

/**
 * Which of the two WebDAV services a discovery is looking for.
 *
 * CalDAV (RFC 4791) and CardDAV (RFC 6352) are the same bootstrap with
 * different nouns: RFC 6764 gives each its own `.well-known` address, each
 * principal advertises its own home-set property, and each collection says
 * what it is through its own resourcetype element. Everything else —
 * redirects, the principal hop, the SSRF checks, the order addresses are tried
 * in — is identical, and CalDavDiscovery is written once against this enum
 * rather than twice against two sets of constants.
 *
 * It lives beside the CalDAV classes rather than in a namespace of its own
 * because that is where the WebDAV client grew up. Moving the transport out
 * would rename half a dozen classes for the sake of a directory name.
 */
enum DavService
{
    case Calendar;
    case AddressBook;

    /** RFC 6764 §6: where the service is advertised on a domain. */
    public function wellKnown(): string
    {
        return match ($this) {
            self::Calendar    => '/.well-known/caldav',
            self::AddressBook => '/.well-known/carddav',
        };
    }

    /** The principal property naming the collection whose children are ours. */
    public function homeProperty(): string
    {
        return match ($this) {
            self::Calendar    => 'calendar-home-set',
            self::AddressBook => 'addressbook-home-set',
        };
    }

    /**
     * Whether a resource is itself one of the collections being looked for —
     * a pasted calendar or address book URL rather than a principal or a home.
     */
    public function isCollection(DavResource $resource): bool
    {
        return match ($this) {
            self::Calendar    => $resource->isCalendarCollection(),
            self::AddressBook => $resource->isAddressBookCollection(),
        };
    }

    /**
     * The PROPFIND every bootstrap step sends: what the resource is, and the
     * two rungs of the ladder — asked together because a server that can
     * answer both saves a round trip, and most can.
     */
    public function probeBody(): string
    {
        return match ($this) {
            self::Calendar => <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
                  <d:prop>
                    <d:resourcetype/>
                    <d:displayname/>
                    <d:current-user-principal/>
                    <cal:calendar-home-set/>
                  </d:prop>
                </d:propfind>
                XML,
            self::AddressBook => <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
                  <d:prop>
                    <d:resourcetype/>
                    <d:displayname/>
                    <d:current-user-principal/>
                    <card:addressbook-home-set/>
                  </d:prop>
                </d:propfind>
                XML,
        };
    }

    /**
     * The sentence for an address with nothing behind it, naming the address
     * and the shape of the one that would work.
     */
    public function notFoundMessage(string $base, string $origin): string
    {
        return match ($this) {
            self::Calendar => sprintf(
                'No calendar service answered at %s. Enter the CalDAV address your server shows you — often something like %s/remote.php/dav — rather than the address of its web interface.',
                $base,
                $origin,
            ),
            self::AddressBook => sprintf(
                'No contacts service answered at %s. Enter the CardDAV address your server shows you — often something like %s/remote.php/dav — rather than the address of its web interface.',
                $base,
                $origin,
            ),
        };
    }
}
