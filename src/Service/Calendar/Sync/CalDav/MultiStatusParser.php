<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarSyncException;
use SimpleXMLElement;

/**
 * WebDAV's multistatus body, turned into something a driver can read.
 *
 * Written against SimpleXML with `local-name()` XPath rather than sabre/dav's
 * client, which is not installed, and rather than registering namespace
 * prefixes, which does not survive contact with real servers — see DavResource
 * for why the namespace is dropped on purpose.
 *
 * Three things are read out of the same document and each has its own method,
 * because they answer to different callers: the resources (everybody), the
 * sync-token that closes a sync-collection REPORT (the pull), and the
 * precondition element inside a `<error>` body (the error classifier, which
 * needs to tell a dead sync token from a refused password when both arrive as
 * 403).
 *
 * Entity loading is off — LIBXML_NONET, and libxml has not substituted entities
 * by default since 2.9. The body comes from a server the user named, so it is
 * hostile input by the same reasoning that makes IntegrationUrlValidator exist.
 */
final readonly class MultiStatusParser
{
    /**
     * Statuses whose propstat is worth reading. A 404 propstat is the server
     * saying "I do not have that property", and its empty text is not a value.
     */
    private const int OK_MIN = 200;
    private const int OK_MAX = 299;

    /**
     * @return list<DavResource>
     *
     * @throws CalendarSyncException when the body is not XML at all, which in
     *                               practice means a proxy or a login page
     *                               answered instead of the CalDAV server
     */
    public function parse(string $xml): array
    {
        $document = $this->load($xml);

        $resources = [];

        foreach ($document->xpath('//*[local-name()="response"]') ?? [] as $response) {
            $resource = $this->toResource($response);

            if (null !== $resource) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * The token that closes a sync-collection REPORT (RFC 6578 §3.2), which
     * sits beside the responses rather than inside one.
     */
    public function syncToken(string $xml): ?string
    {
        $document = $this->load($xml);

        // Restricted to the document element's own children: a calendar-data
        // payload can legally contain anything, and an unanchored search would
        // happily return a sync-token found inside somebody's event.
        $token = $document->xpath('/*/*[local-name()="sync-token"]')[0] ?? null;

        if (null === $token) {
            return null;
        }

        $value = trim((string) $token);

        return '' === $value ? null : $value;
    }

    /**
     * The precondition element name inside a DAV `<error>` body — for us
     * `valid-sync-token`, which is how every server says the token has expired
     * whatever status it wraps it in.
     *
     * Read with a plain string search rather than XPath, deliberately: this is
     * called on error bodies, and an error body is exactly the kind that
     * arrives truncated, mis-declared or wrapped in a proxy's HTML. A parser
     * that throws while working out why the last request failed replaces one
     * diagnosis with another.
     */
    public function hasPrecondition(string $body, string $element): bool
    {
        return str_contains($body, ':' . $element) || str_contains($body, '<' . $element);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function load(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $document) {
            throw new CalendarSyncException(
                'The calendar server answered with something that is not a CalDAV response. Check the server address.',
            );
        }

        return $document;
    }

    private function toResource(SimpleXMLElement $response): ?DavResource
    {
        $href = $response->xpath('*[local-name()="href"]')[0] ?? null;

        if (null === $href) {
            // A response with no href addresses nothing and cannot be matched
            // to a calendar or an event, so there is nothing to do with it.
            return null;
        }

        $props    = [];
        $children = [];
        $hrefs    = [];
        $names    = [];
        $status   = $this->statusOf($response);

        foreach ($response->xpath('*[local-name()="propstat"]') ?? [] as $propstat) {
            $propstatStatus = $this->statusOf($propstat) ?? 200;

            if ($propstatStatus < self::OK_MIN || $propstatStatus > self::OK_MAX) {
                continue;
            }

            foreach ($propstat->xpath('*[local-name()="prop"]/*') ?? [] as $property) {
                $name = $property->getName();

                $props[$name]    = trim((string) $property);
                $children[$name] = $this->descendantNames($property);
                $hrefs[$name]    = $this->descendantHrefs($property);
                $names[$name]    = $this->descendantNameAttributes($property);
            }

            $status ??= $propstatStatus;
        }

        return new DavResource(
            href:     trim((string) $href),
            status:   $status ?? 200,
            props:    $props,
            children: $children,
            hrefs:    $hrefs,
            names:    $names,
        );
    }

    /** "HTTP/1.1 404 Not Found" — the only part anyone wants is the number. */
    private function statusOf(SimpleXMLElement $element): ?int
    {
        $status = $element->xpath('*[local-name()="status"]')[0] ?? null;

        if (null === $status) {
            return null;
        }

        if (1 !== preg_match('#\s(\d{3})\s#', ' ' . trim((string) $status) . ' ', $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Element names below a property, at any depth.
     *
     * Any depth rather than one, because the three properties this exists for
     * nest differently: resourcetype puts `<calendar/>` directly inside,
     * current-user-privilege-set wraps each one in `<privilege>`, and
     * supported-report-set buries `<sync-collection/>` two levels down inside
     * `<supported-report><report>`. A one-level read finds none of the last two.
     *
     * @return list<string>
     */
    private function descendantNames(SimpleXMLElement $property): array
    {
        $names = [];

        foreach ($property->xpath('.//*') ?? [] as $descendant) {
            $names[] = $descendant->getName();
        }

        return array_values(array_unique($names));
    }

    /**
     * The `name` attributes below a property — `<comp name="VEVENT"/>` and
     * nothing else in the vocabulary this driver asks for.
     *
     * @return list<string>
     */
    private function descendantNameAttributes(SimpleXMLElement $property): array
    {
        $names = [];

        foreach ($property->xpath('.//*[@name]') ?? [] as $descendant) {
            $names[] = (string) $descendant['name'];
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function descendantHrefs(SimpleXMLElement $property): array
    {
        $hrefs = [];

        foreach ($property->xpath('.//*[local-name()="href"]') ?? [] as $href) {
            $value = trim((string) $href);

            if ('' !== $value) {
                $hrefs[] = $value;
            }
        }

        return $hrefs;
    }
}
