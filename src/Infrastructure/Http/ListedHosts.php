<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/**
 * A comma-separated list of trusted hosts from the environment.
 *
 * Used for push endpoints (PUSH_ALLOWED_HOSTS). The bundled ntfy container is
 * reached at `http://<SERVER_NAME>:8090`, which on a home install is a LAN or
 * Tailscale address — so a blanket private-network block would break the push
 * setup this repository ships. Naming that one host keeps it working while
 * every other private address, and every redirect, stays refused.
 */
final readonly class ListedHosts implements TrustedHosts
{
    /** @var list<string> */
    private array $hosts;

    public function __construct(?string $hosts = '')
    {
        $this->hosts = array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host, " \t\n\r\0\x0B[]")),
            explode(',', (string) $hosts),
        )));
    }

    public function isTrusted(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), $this->hosts, true);
    }
}
