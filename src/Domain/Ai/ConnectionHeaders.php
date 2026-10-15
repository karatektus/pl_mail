<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The extra HTTP headers a model host is sent, beyond its token.
 *
 * WHO NEEDS THIS
 * ──────────────
 * Anybody with something in front of their model that wants to be shown more
 * than a bearer token. A reverse proxy with basic auth in front of Ollama. A
 * zero-trust gateway that wants a client id and a client secret as two headers.
 * An observability or routing gateway that takes its own key in its own header
 * beside the provider's. A provider that keys a request to a project or a
 * tenant by header. None of those is exotic for a self-hosted installation, and
 * without this every one of them is "plMail cannot be used with …".
 *
 * WHAT IT IS NOT
 * ──────────────
 * Fixed values only. There is no header whose value plMail invents per request
 * — a session id, a trace id — because nothing plMail does needs one and every
 * such header is a mechanism to carry through the queue for the benefit of one
 * endpoint.
 *
 * TYPED AS TEXT, ONE PER LINE
 * ───────────────────────────
 * `Name: value`, the way a header is written everywhere else and the way a
 * provider's documentation shows it, so it can be pasted. Stored as JSON in an
 * encrypted column: a value here is as much a credential as the token is.
 *
 * THE VALUES ARE NEVER SHOWN AGAIN. The page lists the NAMES of what is stored
 * and nothing else, the same rule the token field follows, and for the same
 * reason — a stored credential put back on screen is a credential in a
 * screenshot.
 */
final class ConnectionHeaders
{
    /** More than anything real needs, and few enough to read on one screen. */
    public const int MAX_HEADERS = 20;

    private const int MAX_NAME_LENGTH  = 128;
    private const int MAX_VALUE_LENGTH = 4096;

    /**
     * Headers the HTTP client writes itself, or that describe the connection
     * rather than the request.
     *
     * A second Content-Type or Content-Length beside the client's own makes a
     * request that different servers read differently, which is how request
     * smuggling works; Host decides where a proxy routes it. None of them is a
     * thing an administrator means to set here, and all of them break requests
     * in ways that look like the model host's fault.
     *
     * Authorization is deliberately NOT on this list. Basic auth in front of
     * Ollama is the commonest case there is for this field, and it is spelled
     * `Authorization: Basic …`. A header typed here wins over the token.
     */
    private const array RESERVED = [
        'host',
        'content-length',
        'content-type',
        'transfer-encoding',
        'connection',
        'accept-encoding',
        'expect',
        'te',
        'trailer',
        'upgrade',
    ];

    /**
     * What was typed, as headers — or the reason it cannot be.
     *
     * Strict, because the alternative fails at the far end. A header with a
     * line break in its value is two headers; one with a space in its name is
     * rejected by some servers and silently dropped by others. Refused here,
     * while the person who typed it is still looking at it.
     *
     * The reason is a key in the `validators` domain, never the offending text:
     * the text is a credential and an error message is the first thing that
     * gets pasted into a bug report.
     *
     * @return array<string, string> by name, in the order typed
     *
     * @throws \InvalidArgumentException with the reason key as its message
     */
    public static function parse(string $typed): array
    {
        // First, because everything below is a UTF-8 pattern, and a pattern
        // handed invalid UTF-8 does not fail to match — it fails to run. The
        // split would return nothing, and nothing reads as "no headers typed".
        if (false === mb_check_encoding($typed, 'UTF-8')) {
            throw new \InvalidArgumentException('admin.ai.headers.value');
        }

        $headers = [];
        $seen    = [];

        foreach (preg_split('/\R/u', $typed) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $colon = strpos($line, ':');

            if (false === $colon) {
                throw new \InvalidArgumentException('admin.ai.headers.malformed');
            }

            $name  = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            self::assertUsable($name, $value);

            $lower = strtolower($name);

            if (true === isset($seen[$lower])) {
                throw new \InvalidArgumentException('admin.ai.headers.duplicate');
            }

            $seen[$lower]   = true;
            $headers[$name] = $value;
        }

        if (count($headers) > self::MAX_HEADERS) {
            throw new \InvalidArgumentException('admin.ai.headers.too_many');
        }

        return $headers;
    }

    /**
     * Headers as the column holds them. Null for none, so "no extra headers"
     * is one state in the database and not two.
     *
     * @param array<string, string> $headers
     */
    public static function encode(array $headers): ?string
    {
        if ([] === $headers) {
            return null;
        }

        return json_encode($headers, JSON_THROW_ON_ERROR);
    }

    /**
     * What the column holds, as headers.
     *
     * NEVER THROWS, and that is the opposite of parse() on purpose. parse()
     * runs in front of somebody who can fix what they typed. This runs in a
     * worker, in front of every request to the model — and a column that
     * cannot be read must cost the extra headers, not every AI feature on the
     * installation. Each entry is checked again on the way out, because a
     * config restore writes this column without going through the form.
     *
     * @return array<string, string>
     */
    public static function decode(?string $stored): array
    {
        if (null === $stored || '' === $stored) {
            return [];
        }

        $decoded = json_decode($stored, true);

        if (false === is_array($decoded)) {
            return [];
        }

        $headers = [];

        foreach ($decoded as $name => $value) {
            if (false === is_string($name) || false === is_string($value)) {
                continue;
            }

            try {
                self::assertUsable($name, $value);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $headers[$name] = $value;
        }

        return array_slice($headers, 0, self::MAX_HEADERS, true);
    }

    /**
     * The names of what is stored, for a page that must not show the values.
     *
     * @return list<string>
     */
    public static function names(?string $stored): array
    {
        return array_keys(self::decode($stored));
    }

    /**
     * @throws \InvalidArgumentException with the reason key as its message
     */
    private static function assertUsable(string $name, string $value): void
    {
        // RFC 9110's `token`: the characters a header name may be made of.
        if ('' === $name || strlen($name) > self::MAX_NAME_LENGTH || 1 !== preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)) {
            throw new \InvalidArgumentException('admin.ai.headers.name');
        }

        if (true === in_array(strtolower($name), self::RESERVED, true)) {
            throw new \InvalidArgumentException('admin.ai.headers.reserved');
        }

        // Control characters, which is where a line break smuggled into a
        // value would be — and invalid UTF-8, which the pattern cannot match.
        if ('' === $value || strlen($value) > self::MAX_VALUE_LENGTH || 1 !== preg_match('/^[^\p{Cc}]+$/u', $value)) {
            throw new \InvalidArgumentException('admin.ai.headers.value');
        }
    }
}
