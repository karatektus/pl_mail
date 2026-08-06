<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

/**
 * JMAP capability URNs (RFC 8620 / RFC 8621) advertised by this server.
 */
final class Capability
{
    public const string CORE = 'urn:ietf:params:jmap:core';
    public const string MAIL = 'urn:ietf:params:jmap:mail';
    public const string SUBMISSION = 'urn:ietf:params:jmap:submission';

    /**
     * Vendor extension: carries the VAPID public key a client needs before it
     * can create a PushSubscription. RFC 8620 defines no standard place for
     * it, and a client cannot call pushManager.subscribe() without it.
     */
    public const string PUSH = 'urn:plmail:params:jmap:push';

    /**
     * Vendor extension: Calendar and CalendarEvent.
     *
     * Deliberately NOT "urn:ietf:params:jmap:calendars". JMAP for Calendars is
     * an unratified draft whose object shape is still moving — properties have
     * been renamed and re-scoped between revisions — so advertising its URN
     * would promise a contract no client could rely on, and a client that
     * believed it would break on the revision after the one this was written
     * against. A vendor URN says what is true: this is plMail's calendar
     * surface, and only something written for plMail should use it.
     *
     * The same call PUSH above already made, for the same reason: RFC 8620
     * defines nowhere to put a VAPID key, so the key went behind a URN nobody
     * can mistake for a standard. Switching to the IETF URN when the draft is
     * ratified is then an addition rather than a breaking change — both can be
     * advertised while clients move across.
     */
    public const string CALENDARS = 'urn:plmail:params:jmap:calendars';

    /**
     * Vendor extension: the harvested address book, read-only, for recipient
     * autocomplete.
     *
     * Deliberately NOT "urn:ietf:params:jmap:contacts". RFC 8621 has no
     * Contacts section at all and the JMAP Contacts draft describes an
     * AddressBook of full ContactCards a client may create, update and destroy.
     * plMail has none of that: a Contact here is a row a sync wrote by reading
     * message headers, with a name, an address and a count. Claiming the IETF
     * URN would advertise a writable address book behind a table nobody can
     * write to, and the first client to try would fail on a method that does
     * not exist rather than on a capability it could have checked for.
     *
     * The same call PUSH and CALENDARS already made. Should plMail ever grow a
     * real address book, the standard URN can be advertised alongside this one
     * rather than in place of it.
     */
    public const string CONTACTS = 'urn:plmail:params:jmap:contacts';

    /**
     * Capabilities a client is currently allowed to declare in "using".
     * Grow this list as new object types come online.
     *
     * @var list<string>
     */
    public const array SUPPORTED = [
        self::CORE,
        self::MAIL,
        self::SUBMISSION,
        self::CALENDARS,
        self::CONTACTS,
    ];
}
