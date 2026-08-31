<?php

declare(strict_types=1);

namespace App\Service\Calendar\Dav;

use DateTimeImmutable;
use DateTimeZone;
use SimpleXMLElement;

/**
 * Turning a REPORT body into something the controller can act on.
 *
 * Read with `local-name()` XPath and no namespace registration, which is the
 * lesson MultiStatusParser records from the other side of this protocol:
 * prefixes do not survive contact with real clients. Thunderbird, Akonadi,
 * DAVx5 and iOS each pick their own, and a reader bound to `d:` or `D:` works
 * against whichever it was tested with and no others.
 *
 * Entity loading is off for the same reason MultiStatusParser turns it off: the
 * body arrives from the network, authenticated but not trusted.
 */
final readonly class DavReportReader
{
    /** iCalendar's UTC form, which is how a time-range names an instant. */
    private const string ICAL_UTC = 'Ymd\THis\Z';

    public function read(string $xml): ?DavReportRequest
    {
        $document = $this->load($xml);

        if (null === $document) {
            return null;
        }

        $type = $document->getName();

        // getName() drops the prefix already, but a document parsed from a
        // namespace-less body can arrive with it attached.
        if (true === str_contains($type, ':')) {
            $type = substr($type, (int) strrpos($type, ':') + 1);
        }

        return match ($type) {
            DavReportRequest::SYNC_COLLECTION => $this->syncCollection($document),
            DavReportRequest::MULTIGET        => $this->multiget($document),
            DavReportRequest::QUERY           => $this->query($document),
            default                           => null,
        };
    }

    private function syncCollection(SimpleXMLElement $document): DavReportRequest
    {
        $token = $this->first($document, 'sync-token');

        // An empty element and an absent one both mean "I hold nothing".
        if (null !== $token && '' === trim($token)) {
            $token = null;
        }

        $limit = $this->first($document, 'nresults');

        return new DavReportRequest(
            type: DavReportRequest::SYNC_COLLECTION,
            syncToken: null === $token ? null : trim($token),
            limit: null === $limit ? null : (int) $limit,
            wantsCalendarData: $this->wantsCalendarData($document),
        );
    }

    private function multiget(SimpleXMLElement $document): DavReportRequest
    {
        $hrefs = [];

        foreach ($document->xpath('//*[local-name()="href"]') ?? [] as $href) {
            $value = trim((string) $href);

            if ('' !== $value) {
                $hrefs[] = $value;
            }
        }

        return new DavReportRequest(
            type: DavReportRequest::MULTIGET,
            hrefs: $hrefs,
            wantsCalendarData: $this->wantsCalendarData($document),
        );
    }

    private function query(SimpleXMLElement $document): DavReportRequest
    {
        $start = null;
        $end   = null;

        $ranges = $document->xpath('//*[local-name()="time-range"]') ?? [];

        if ([] !== $ranges) {
            $start = $this->instant((string) ($ranges[0]['start'] ?? ''));
            $end   = $this->instant((string) ($ranges[0]['end'] ?? ''));
        }

        return new DavReportRequest(
            type: DavReportRequest::QUERY,
            wantsCalendarData: $this->wantsCalendarData($document),
            rangeStart: $start,
            rangeEnd: $end,
        );
    }

    /**
     * Whether the client asked for the iCalendar itself, rather than only the
     * ETags. Serialising every event in a collection is the most expensive
     * thing this server does, so it is done when asked and not by default.
     */
    private function wantsCalendarData(SimpleXMLElement $document): bool
    {
        return [] !== ($document->xpath('//*[local-name()="calendar-data"]') ?? []);
    }

    private function first(SimpleXMLElement $document, string $name): ?string
    {
        $found = $document->xpath(sprintf('//*[local-name()="%s"]', $name)) ?? [];

        if ([] === $found) {
            return null;
        }

        return (string) $found[0];
    }

    private function instant(string $value): ?DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::ICAL_UTC, $value, new DateTimeZone('UTC'));

        return false === $parsed ? null : $parsed;
    }

    private function load(string $xml): ?SimpleXMLElement
    {
        if ('' === trim($xml)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return false === $document ? null : $document;
    }
}
