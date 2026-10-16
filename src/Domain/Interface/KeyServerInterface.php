<?php

declare(strict_types=1);

namespace App\Domain\Interface;

/**
 * One public OpenPGP key server plMail knows how to ask for an address's key,
 * through the server's own HTTPS API.
 *
 * Not HKP: every server worth asking today answers a plain GET on an API of
 * its own, and the old SKS pool that HKP was for hands out whatever anybody
 * uploaded under any name. Each implementation only knows its URL and the
 * shape of its answer; the request itself — and every rule it is held to —
 * is KeyFetcher's, and which keys count is KeyServerLookup's. Adding a
 * server is one class here and its host name in APP_KEY_SERVERS.
 */
interface KeyServerInterface
{
    /**
     * The host name an operator lists in APP_KEY_SERVERS, and the one the
     * person is shown beside a key that came from here.
     */
    public function host(): string;

    /** The one URL to ask for $email, which has been validated. */
    public function url(string $email): string;

    /** The Accept header for that request. */
    public function accept(): string;

    /**
     * The key material in a 200 answer — armored or binary, as PublicKeyReader
     * takes it — or null when the answer holds none.
     */
    public function keyIn(string $body): ?string;
}
