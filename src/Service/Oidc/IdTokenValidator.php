<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * Reads an ID token's claims and checks the ones that bind it to this sign-in.
 *
 * ── Why the signature is not verified ───────────────────────────────────
 * OpenID Connect Core § 3.1.3.7, step 6: when the ID token comes straight back
 * from the token endpoint over TLS, in exchange for a code and the client
 * secret, "the TLS server validation MAY be used to validate the issuer in
 * place of checking the token signature". That is exactly how this token
 * arrives — OidcClient never accepts one from the browser — and it is why
 * OidcDiscovery refuses an issuer that is not https. Verifying the signature
 * as well would mean fetching and caching the provider's key set and following
 * its rotations, for no protection the TLS connection does not already give.
 *
 * Anyone who changes OidcClient to take an ID token from anywhere else — the
 * implicit or hybrid flows, a token posted by the browser — has to add
 * signature verification here first. Without it, such a token is a string the
 * caller wrote.
 *
 * ── What is checked ─────────────────────────────────────────────────────
 * Issuer, audience, authorised party, expiry and nonce: the claims that say
 * "this token was minted by that provider, for this client, for this
 * sign-in, recently". The nonce is the one that matters most and is the
 * easiest to forget — without it a token issued for one browser's sign-in can
 * be replayed into another's.
 */
final class IdTokenValidator
{
    /**
     * Clock skew forgiven between this server and the provider. A minute is
     * what most libraries allow; a NAS whose clock has drifted further than
     * that has other problems, TOTP among them.
     */
    private const int LEEWAY_SECONDS = 60;

    /**
     * @return array<string, mixed> the token's claims
     *
     * @throws OidcLoginFailed
     */
    public function validate(string $idToken, string $issuer, string $clientId, string $nonce, int $now): array
    {
        $claims = $this->decode($idToken);

        if (($claims['iss'] ?? null) !== $issuer) {
            throw $this->invalid(sprintf('issuer is "%s", expected "%s"', (string) json_encode($claims['iss'] ?? null), $issuer));
        }

        $audience = $claims['aud'] ?? null;
        $audience = is_string($audience) ? [$audience] : $audience;

        if (false === is_array($audience) || false === in_array($clientId, $audience, true)) {
            throw $this->invalid('this client is not in the audience');
        }

        // § 3.1.3.7 steps 4 and 5: a token meant for several clients names
        // the one it was issued to, and that has to be us.
        if (count($audience) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw $this->invalid('several audiences and the authorised party is not this client');
        }

        if (false === is_int($claims['exp'] ?? null) || $claims['exp'] + self::LEEWAY_SECONDS < $now) {
            throw $this->invalid('expired, or carries no expiry');
        }

        if (false === is_string($claims['nonce'] ?? null) || false === hash_equals($nonce, $claims['nonce'])) {
            throw $this->invalid('nonce does not match the one this sign-in sent');
        }

        if (false === is_string($claims['sub'] ?? null) || '' === $claims['sub']) {
            throw $this->invalid('no subject');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (3 !== count($parts)) {
            throw $this->invalid('not a signed JWT');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if (false === $json) {
            throw $this->invalid('payload is not base64url');
        }

        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $this->invalid('payload is not JSON', $e);
        }

        if (false === is_array($claims)) {
            throw $this->invalid('payload is not a JSON object');
        }

        return $claims;
    }

    private function invalid(string $why, ?\Throwable $previous = null): OidcLoginFailed
    {
        return new OidcLoginFailed('oidc.failed', 'ID token refused: ' . $why, $previous);
    }
}
