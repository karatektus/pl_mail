<?php

declare(strict_types=1);

namespace App\Security\Csp;

use Symfony\Contracts\Service\ResetInterface;

/**
 * One nonce per request, shared by everything that has to agree on it.
 *
 * A nonce-based CSP only works if the value in the header and the value on
 * every inline `<script>` are the same string, and those are produced in two
 * different places — a response listener and a Twig template — at two different
 * moments. Generating on demand in each would produce two nonces and a policy
 * that blocks the application's own scripts, which is the classic way this is
 * got wrong.
 *
 * So: generated once, on first read, and handed to both. Lazily rather than in
 * the constructor because most requests in a mail client are not documents —
 * they are Turbo Streams, JSON and attachments — and none of those needs one.
 *
 * Reset between requests, which matters here more than in most applications:
 * FrankenPHP's worker mode keeps the container alive across requests, so a
 * service holding state holds it for the next visitor too. Without the reset
 * every page served by that worker would share one nonce, which is the same as
 * having none.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    /** Base64 of 16 random bytes — the length the CSP specification asks for. */
    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    /** True when something actually asked for one and the header must carry it. */
    public function isUsed(): bool
    {
        return null !== $this->value;
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
