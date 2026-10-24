<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The provider's endpoints, read from its discovery document.
 *
 * Discovery rather than four more variables for the authorisation, token,
 * userinfo and logout URLs: the operator copies one URL out of the provider's
 * console — Authentik calls it the "OpenID Configuration Issuer" — and the
 * provider says the rest. Endpoints typed by hand are endpoints that drift.
 *
 * Cached for an hour. A sign-in needs the document twice, once on the way out
 * and once on the way back, and fetching it each time would put the provider
 * on the critical path of every login for a document that changes when its
 * operator upgrades it.
 */
final readonly class OidcDiscovery
{
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: ?string}
     *
     * @throws OidcLoginFailed
     */
    public function endpoints(string $issuer): array
    {
        // https only, and checked here because this is the request every
        // other one depends on. The ID token is trusted on the strength of the
        // TLS connection it arrived over — see IdTokenValidator — so a
        // provider reached over plain http is one anybody on the path could
        // impersonate, and sign in as anyone.
        if (false === str_starts_with($issuer, 'https://')) {
            throw new OidcLoginFailed('oidc.unavailable', sprintf('The issuer must be an https URL, got "%s"', $issuer));
        }

        try {
            return $this->cache->get(
                'oidc_discovery_' . hash('sha256', $issuer),
                function (ItemInterface $item) use ($issuer): array {
                    $item->expiresAfter(self::TTL_SECONDS);

                    return $this->fetch($issuer);
                },
            );
        } catch (ExceptionInterface $e) {
            throw new OidcLoginFailed('oidc.unavailable', 'Could not read the discovery document: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: ?string}
     */
    private function fetch(string $issuer): array
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';

        // No redirects, for the reason the issuer has to be https: the document
        // names the endpoints every token is then believed from, so it is read
        // from the address the operator configured or not at all. A redirect
        // throws, and reads as the provider being unavailable.
        $document = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_redirects' => 0])->toArray();

        // OpenID Connect Discovery § 4.3: the document's issuer must be the
        // one it was asked for, exactly. Compared without a trailing slash,
        // because which way round an operator pastes it is not a security
        // question — but it is the DOCUMENT's spelling that is returned and
        // that the ID token's `iss` is held to, since that one is the
        // provider's own.
        $declared = $document['issuer'] ?? null;

        if (false === is_string($declared) || rtrim($declared, '/') !== rtrim($issuer, '/')) {
            throw new OidcLoginFailed('oidc.unavailable', sprintf(
                'The discovery document at %s declares issuer "%s", not the configured "%s"',
                $url,
                (string) json_encode($declared),
                $issuer,
            ));
        }

        foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
            if (false === is_string($document[$required] ?? null) || false === str_starts_with($document[$required], 'https://')) {
                throw new OidcLoginFailed('oidc.unavailable', sprintf('The discovery document at %s has no https %s', $url, $required));
            }
        }

        $userinfo = $document['userinfo_endpoint'] ?? null;

        return [
            'issuer'                 => $declared,
            'authorization_endpoint' => $document['authorization_endpoint'],
            'token_endpoint'         => $document['token_endpoint'],
            'userinfo_endpoint'      => is_string($userinfo) && str_starts_with($userinfo, 'https://') ? $userinfo : null,
        ];
    }
}
