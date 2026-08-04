<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

/**
 * One `<response>` out of a WebDAV multistatus body.
 *
 * A multistatus is the answer to every question CalDAV asks — PROPFIND,
 * sync-collection, calendar-query, calendar-multiget all come back in the same
 * envelope — so it is worth one shape rather than four ad-hoc traversals of
 * SimpleXML inside the driver.
 *
 * Properties are keyed by their **local name only**, with the namespace thrown
 * away. That is a deliberate loss. The four namespaces in play (DAV:, CalDAV,
 * Apple's ical, calendarserver) never reuse a name between them, servers
 * disagree wildly about prefixes, and a handful of real implementations emit
 * properties in no namespace at all — matching on local names reads all of them
 * where matching on `{ns}name` reads the well-behaved ones only.
 *
 * $children carries the element names *inside* a property, because three of the
 * properties that matter are not text at all: resourcetype says what something
 * is by containing `<collection/>` and `<calendar/>`, current-user-privilege-set
 * says what may be done by containing `<write-content/>`, and
 * supported-report-set says whether sync-collection can be used at all. Text
 * content is useless for all three.
 */
final readonly class DavResource
{
    /**
     * @param string                    $href     as the server wrote it —
     *                                            usually root-relative, legally
     *                                            absolute; resolved against the
     *                                            request URL by the caller
     * @param int                       $status   the HTTP status this resource
     *                                            answered with: the response's
     *                                            own `<status>` where there is
     *                                            one (that is how a
     *                                            sync-collection reports a
     *                                            deletion), otherwise the status
     *                                            of the propstat the properties
     *                                            came from
     * @param array<string,string>      $props    text content by local name,
     *                                            from the 2xx propstats only —
     *                                            a 404 propstat means "this
     *                                            server does not have that
     *                                            property", and reading its
     *                                            empty text as a value is how a
     *                                            calendar ends up named ''
     * @param array<string,list<string>> $children element names nested inside
     *                                            each property, at any depth
     * @param array<string,list<string>> $hrefs    the `<href>` values inside
     *                                            each property. Kept apart from
     *                                            $props because SimpleXML's
     *                                            string cast reads an element's
     *                                            own text and not its
     *                                            descendants' — so
     *                                            current-user-principal and
     *                                            calendar-home-set, whose whole
     *                                            content is a nested href, both
     *                                            read as the empty string
     *                                            through $props and the
     *                                            bootstrap silently stops at
     *                                            step one.
     * @param array<string,list<string>> $names   the `name` attributes of
     *                                            elements nested inside each
     *                                            property. One property needs
     *                                            them and it is load-bearing:
     *                                            supported-calendar-component-set
     *                                            says a collection holds tasks
     *                                            rather than events entirely in
     *                                            `<comp name="VTODO"/>`, where
     *                                            the element name is `comp`
     *                                            either way.
     */
    public function __construct(
        public string $href,
        public int    $status,
        public array  $props = [],
        public array  $children = [],
        public array  $hrefs = [],
        public array  $names = [],
    ) {
    }

    /** The first href inside a property, which is all any of ours carry. */
    public function href(string $property): ?string
    {
        return $this->hrefs[$property][0] ?? null;
    }

    /** Null rather than '' for an absent property, so a caller can tell them apart. */
    public function prop(string $name): ?string
    {
        $value = $this->props[$name] ?? null;

        return null === $value || '' === $value ? null : $value;
    }

    public function contains(string $property, string $element): bool
    {
        return in_array($element, $this->children[$property] ?? [], true);
    }

    /** Whether a property nests an element carrying `name="$value"`. */
    public function names(string $property, string $value): bool
    {
        return in_array($value, $this->names[$property] ?? [], true);
    }

    public function hasProp(string $name): bool
    {
        return array_key_exists($name, $this->props) || array_key_exists($name, $this->children);
    }

    /**
     * A collection holding events, as opposed to the principal, the home, an
     * address book or a subscribed feed the server also lists under the home.
     */
    public function isCalendarCollection(): bool
    {
        return $this->contains('resourcetype', 'calendar');
    }
}
