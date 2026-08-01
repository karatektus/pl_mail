<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\CharsetHelper;

/**
 * Puts raw header bags into one canonical shape, whatever provider they came
 * from.
 *
 * Without this the same header has a different key per provider: the Gmail and
 * Graph builders keep the wire casing ("List-Id"), while php-imap's
 * getAttributes() lowercases and turns dashes into underscores ("list_id").
 * Any header-based filter would then match on one account and silently not on
 * another — which is why mail rules had no header condition at all until now.
 *
 * Canonical form is **lowercase, dash-separated**: "list-id", "x-spam-status".
 * Lowercase because RFC 5322 field names are case-insensitive, so folding case
 * loses nothing; dashes because that is what the wire format actually uses.
 * A fixed form is what lets the SQL side look a header up directly
 * (headers->>'list-id') instead of scanning keys.
 *
 * The one genuinely lossy step is undoing php-imap's underscore mangling:
 * "x_foo" could have been "X-Foo" or, legally if unusually, "X_Foo". That is
 * accepted deliberately — the wire form is overwhelmingly more likely, and
 * IMAP is the one path that keeps a raw copy of the message if the original
 * is ever needed.
 */
final class HeaderNormalizer
{
    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string|list<string>>
     */
    public function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = $this->key((string) $name);

            if ('' === $key) {
                continue;
            }

            $value = $this->utf8($value);

            if (false === array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
                continue;
            }

            // Two source keys folded onto one — "List-Id" and "list_id" in the
            // same bag. Keep both values rather than letting one win.
            $normalized[$key] = array_merge(
                (array) $normalized[$key],
                (array) $value,
            );
        }

        return $normalized;
    }

    /**
     * Force a header value to UTF-8, whichever shape it arrived in.
     *
     * $headers is a `json` column, and Doctrine's converter serialises with
     * JSON_THROW_ON_ERROR — so a single raw 8-bit byte anywhere in the bag
     * does not corrupt one header, it throws and takes the whole message
     * insert with it, and the batch around it. A sender still emitting
     * latin-1 headers without RFC 2047 encoding therefore costs mail rather
     * than costing an umlaut.
     *
     * Here rather than in each provider's builder because all three converge
     * on this method, and a guard that only two of them reach is the kind
     * that looks present and is not.
     *
     * @param string|list<string> $value
     *
     * @return string|list<string>
     */
    private function utf8(string|array $value): string|array
    {
        if (true === is_array($value)) {
            return array_map(CharsetHelper::ensureUtf8(...), $value);
        }

        return CharsetHelper::ensureUtf8($value);
    }

    /**
     * Canonical key for one header name.
     */
    public function key(string $name): string
    {
        $name = strtolower(trim($name));

        // php-imap reports "list_id"; the wire name is "List-Id".
        $name = str_replace('_', '-', $name);

        return $name;
    }

    /**
     * First value of a header, or null. Header bags hold either a string or a
     * list of strings depending on whether the header repeated, so callers
     * should not have to care which.
     *
     * @param array<string, string|list<string>>|null $headers
     */
    public function first(?array $headers, string $name): ?string
    {
        if (null === $headers) {
            return null;
        }

        $value = $headers[$this->key($name)] ?? null;

        if (null === $value) {
            return null;
        }

        if (true === is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (false === is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
