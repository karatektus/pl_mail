<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Reads the key an `Autocrypt:` header carries (Autocrypt Level 1, §2.1).
 *
 *     Autocrypt: addr=anna@example.org; prefer-encrypt=mutual; keydata=mQIN…
 *
 * Read only when `addr=` is the message's From address. The header is a claim
 * by whoever wrote the message about their own key; a header naming somebody
 * else is either a mistake or an attempt to plant a key for another person,
 * and the specification says to ignore it either way. Likewise a header with
 * an attribute the specification does not know (one not starting with `_`),
 * and a message with more than one valid header.
 *
 * Only reading. Whether the key is then believed is ContactKeys' business.
 */
final class Autocrypt
{
    private const array KNOWN = ['addr', 'prefer-encrypt', 'keydata'];

    /**
     * The binary key of the one valid Autocrypt header for $from, or null.
     *
     * @param array<string, mixed> $headers a message's normalised header bag
     */
    public static function keyFor(array $headers, string $from): ?string
    {
        $from  = strtolower(trim($from));
        $found = [];

        foreach ((array) ($headers['autocrypt'] ?? []) as $value) {
            $parsed = self::parse((string) $value);

            if (null !== $parsed && '' !== $from && $from === $parsed['addr']) {
                $found[] = $parsed['keydata'];
            }
        }

        return 1 === count($found) ? $found[0] : null;
    }

    /**
     * @return array{addr: string, keydata: string}|null
     */
    public static function parse(string $value): ?array
    {
        $attributes = [];

        foreach (explode(';', $value) as $pair) {
            $pair = trim($pair);

            if ('' === $pair) {
                continue;
            }

            [$name, $content] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(trim($name));

            if (false === in_array($name, self::KNOWN, true) && false === str_starts_with($name, '_')) {
                return null;
            }

            $attributes[$name] = trim($content);
        }

        $addr = strtolower(trim($attributes['addr'] ?? ''));
        $key  = base64_decode((string) preg_replace('/\s+/', '', $attributes['keydata'] ?? ''), true);

        if ('' === $addr || false === $key || '' === $key) {
            return null;
        }

        return ['addr' => $addr, 'keydata' => $key];
    }
}
