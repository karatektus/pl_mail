<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/**
 * Hosts an administrator has said may be reached on a private network.
 *
 * The exemption {@see UserUrlHttpClient} consults before it blocks a private
 * address. It is a decision about a host by name, made by whoever runs the
 * install — never by the user who typed the URL.
 */
interface TrustedHosts
{
    public function isTrusted(string $host): bool;
}
