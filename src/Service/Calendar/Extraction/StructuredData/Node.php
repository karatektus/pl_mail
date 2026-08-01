<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One decoded JSON-LD object, read defensively.
 *
 * Every mapper would otherwise repeat the same twenty lines of "is it a string,
 * is it an object with a name, is it wrapped in an array, is it a URL where a
 * bare term was expected". JSON-LD permits all of those for the same field, and
 * senders use all of them — schema.org is a vocabulary, not a schema, so
 * nothing rejects a Place where a string was expected, or an array of one where
 * an object was expected.
 *
 * The other half of its job is that this is attacker-influenced input. A node
 * comes from whoever sent the mail, so every accessor returns null rather than
 * throwing, nothing is trusted to be the type it should be, and a timestamp
 * outside a plausible window is treated as absent — the alternative is a sender
 * pinning an event to the year 9999 and owning the top of "Happening Soon"
 * forever.
 */
final readonly class Node
{
    /**
     * Timestamps outside this window are a parse artefact or an attack, not a
     * booking anybody made.
     */
    private const int EARLIEST_YEAR = 1990;
    private const int LATEST_YEAR   = 2100;

    /** Long enough for any real value; short enough that a hostile one cannot be a payload. */
    private const int MAX_STRING = 512;

    /** @param array<mixed> $data */
    public function __construct(public array $data)
    {
    }

    /**
     * The bare schema.org term.
     *
     * `@type` may be a term, an absolute URL, or an array of either — JSON-LD
     * allows a node to have several types, and senders occasionally emit
     * ["FlightReservation"] for a single one. The last path segment of a URL is
     * the term, so https://schema.org/ParcelDelivery and ParcelDelivery are the
     * same answer.
     */
    public function type(): string
    {
        $value = $this->data['@type'] ?? null;

        if (true === is_array($value)) {
            $value = reset($value);
        }

        if (false === is_string($value)) {
            return '';
        }

        return $this->term($value);
    }

    /** The bare term of an enumeration value, which senders write either way. */
    public function term(string $value): string
    {
        $value = trim($value);
        $slash = strrpos($value, '/');

        return false === $slash ? $value : substr($value, $slash + 1);
    }

    /**
     * A scalar field, trimmed, or null when it is absent, empty, or an object
     * that is not a JSON-LD value wrapper.
     */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        // {"@value": "..."} is how JSON-LD writes a literal that also carries a
        // language or a datatype, and a few senders emit it for plain strings.
        if (true === is_array($value) && true === isset($value['@value'])) {
            $value = $value['@value'];
        }

        if (true === is_int($value) || true === is_float($value)) {
            $value = (string) $value;
        }

        if (false === is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        return mb_substr($value, 0, self::MAX_STRING);
    }

    /** A nested object, or the first object of a nested array. */
    public function child(string $key): ?self
    {
        return $this->children($key)[0] ?? null;
    }

    /**
     * Every nested object under a key.
     *
     * A single object and an array of one are the same thing to a sender —
     * orderDelivery in particular arrives both ways, and an order with three
     * parcels is the case this exists for.
     *
     * @return list<self>
     */
    public function children(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (false === is_array($value)) {
            return [];
        }

        // A map is one node; a list is several. isset('@type') is not the test,
        // because a nested object may legitimately omit its type.
        if (false === array_is_list($value)) {
            return [new self($value)];
        }

        $nodes = [];

        foreach ($value as $entry) {
            if (true === is_array($entry) && false === array_is_list($entry)) {
                $nodes[] = new self($entry);
            }
        }

        return $nodes;
    }

    /**
     * An ISO 8601 timestamp, normalised to UTC.
     *
     * Anchored to a leading date on purpose. DateTimeImmutable happily parses
     * "tomorrow" and "next tuesday", and a sender-supplied relative expression
     * would produce an event that moves every time the extractor is replayed.
     *
     * A timestamp with no offset is read as UTC rather than as the server's
     * default zone, so the same mail extracts identically wherever it runs.
     */
    public function moment(string $key): ?Moment
    {
        $raw = $this->string($key);

        if (null === $raw) {
            return null;
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $raw)) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        try {
            $at = new DateTimeImmutable($raw, $utc)->setTimezone($utc);
        } catch (\Throwable) {
            return null;
        }

        $year = (int) $at->format('Y');

        if ($year < self::EARLIEST_YEAR || $year > self::LATEST_YEAR) {
            return null;
        }

        return new Moment($at, 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw));
    }

    /** What to call this node in a title: its name, else its code, else where it is. */
    public function label(): ?string
    {
        return $this->string('name')
            ?? $this->string('alternateName')
            ?? $this->string('iataCode')
            ?? $this->addressText();
    }

    /**
     * A one-line postal address, whether this node is a Place carrying one or is
     * the PostalAddress itself.
     */
    public function addressText(): ?string
    {
        $plain = $this->string('address');

        if (null !== $plain) {
            return $plain;
        }

        $address = $this->child('address') ?? $this;

        $parts = [
            $address->string('streetAddress'),
            $address->string('postalCode'),
            $address->string('addressLocality'),
            $address->string('addressRegion'),
            $address->string('addressCountry') ?? $address->child('addressCountry')?->string('name'),
        ];

        return self::join($parts, ', ');
    }

    /** A Place as a location line: what it is called and where it is. */
    public function locationText(): ?string
    {
        $name    = $this->string('name');
        $address = $this->addressText();

        if (null !== $name && null !== $address && true === str_contains($address, $name)) {
            return $address;
        }

        return self::join([$name, $address], ', ');
    }

    /**
     * A link, but only one a browser may follow.
     *
     * Tracking URLs end up in an event description, and a description is
     * rendered. `javascript:` in a field nobody expected to be a link is the
     * kind of thing a future "make links clickable" change turns into stored
     * XSS, so the scheme is checked here rather than trusted downstream.
     */
    public function url(string $key): ?string
    {
        $value = $this->string($key);

        if (null === $value) {
            return null;
        }

        return 1 === preg_match('#^https?://#i', $value) ? $value : null;
    }

    /**
     * The non-empty parts, deduplicated, or null when there are none.
     *
     * Static because it is about the strings, not about a node — and every
     * mapper builds a title out of three fields any of which may be missing.
     * Deduplicating matters more than it looks: "Hilton, Hilton Berlin" is what
     * a naive join produces from half the hotel confirmations there are.
     *
     * @param list<string|null> $parts
     */
    public static function join(array $parts, string $glue = ' '): ?string
    {
        $kept = [];

        foreach ($parts as $part) {
            $part = null === $part ? '' : trim($part);

            if ('' !== $part && false === in_array($part, $kept, true)) {
                $kept[] = $part;
            }
        }

        return [] === $kept ? null : implode($glue, $kept);
    }
}
