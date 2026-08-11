<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Recipients read back out of a message's stored `headers` bag.
 *
 * Every ingest path keeps the headers it saw, so the To/Cc/Bcc lines survive
 * even on rows whose to/cc/bcc columns are empty — which, until
 * MessageSyncer::addressesOf() was fixed, was every message ever synced
 * over IMAP. That makes the columns re-derivable without re-fetching anything:
 * the backfill task reads them from here, and the message header falls back to
 * here so a row that has not been backfilled yet still says who it went to
 * instead of claiming there was nobody.
 *
 * Two key spellings have to be understood, because the paths disagree:
 * Gmail and Graph store the header name as it arrived (`To`, `Reply-To`),
 * while webklex normalises to lowercase with underscores (`to`, `reply_to`).
 * Lookup canonicalises both to the lowercase hyphen form.
 */
final class RecipientHeaderHelper
{
    /**
     * Header bag keyed the one way, whatever way it went in.
     *
     * Multi-value headers collapse to one newline-joined string, which is what
     * an address list wants anyway — the split below treats a newline like any
     * other separator between entries.
     *
     * @param array<string, string|array<int, string>|null> $headers
     *
     * @return array<string, string>
     */
    public static function canonicalise(array $headers): array
    {
        $canonical = [];

        foreach ($headers as $name => $value) {
            $key = str_replace('_', '-', mb_strtolower(trim((string) $name)));

            if ('' === $key) {
                continue;
            }

            $flat = true === is_array($value)
                ? implode("\n", array_map(static fn (mixed $v): string => (string) $v, $value))
                : (string) $value;

            // First spelling wins: a bag holding both `To` and `to` came from
            // two writers, and the original-case one is the arrived-as header.
            if (false === array_key_exists($key, $canonical)) {
                $canonical[$key] = $flat;
            }
        }

        return $canonical;
    }

    /**
     * The address list a single header field names.
     *
     * Entries with no addr-spec are dropped rather than stored nameless —
     * `undisclosed-recipients:;` is a group with no members, and the honest
     * rendering of that is an empty list, not a recipient called
     * "undisclosed-recipients".
     *
     * @param array<string, string|array<int, string>|null> $headers
     *
     * @return list<array{name: string, address: string}>
     */
    public static function addresses(array $headers, string $field): array
    {
        $canonical = self::canonicalise($headers);
        $raw       = $canonical[str_replace('_', '-', mb_strtolower($field))] ?? '';

        if ('' === trim($raw)) {
            return [];
        }

        $result = [];

        foreach (AddressHelper::splitList(str_replace("\n", ',', $raw)) as $entry) {
            $address = AddressHelper::email($entry);

            if (false === AddressHelper::isValidEmail($address)) {
                continue;
            }

            $result[] = [
                'name'    => AddressHelper::name(self::displayPart($entry)),
                'address' => $address,
            ];
        }

        return $result;
    }

    /**
     * The display-name half of `Name <local@domain>`, or '' when the entry is
     * a bare address.
     */
    private static function displayPart(string $entry): string
    {
        $entry = trim($entry);

        if (1 !== preg_match('/^(.*)<[^<>]*>\s*$/s', $entry, $match)) {
            return '';
        }

        return trim($match[1]);
    }
}
