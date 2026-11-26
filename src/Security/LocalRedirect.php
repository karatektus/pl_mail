<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Where to send somebody after signing in, as a path on this site and never
 * as an address with a host in it (#669).
 *
 * Symfony saves "where you were going" as the full URL of the request it
 * turned away, host included, and scheb's code form redirected to its
 * fallback through HttpUtils, which makes that absolute from the request too.
 * Both take the host from the request. Behind a reverse proxy that forwards
 * the upstream address as Host, Authentik's for one, that host is the
 * container's internal IP: signing in with a password worked (its redirects
 * were already paths), and signing in with a second factor sent the browser
 * to http://10.x.x.x/. A path is resolved by the browser against the address
 * it is already on, which is the public one, whatever the proxy forwarded.
 *
 * It also keeps a saved target from leaving the site: whatever host it named
 * is dropped, and a path that would read as one (`//evil.example`) falls back.
 */
final class LocalRedirect
{
    public static function path(?string $target, string $fallback): string
    {
        if (null === $target || '' === $target) {
            return $fallback;
        }

        $parts = parse_url($target);

        if (false === $parts) {
            return $fallback;
        }

        $path = $parts['path'] ?? '/';

        if ('' === $path || '/' !== $path[0] || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $fallback;
        }

        if (true === isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        if (true === isset($parts['fragment'])) {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
    }
}
