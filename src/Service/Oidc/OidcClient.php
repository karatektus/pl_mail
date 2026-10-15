<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Service\OAuth\OAuthStateStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use UnexpectedValueException;

/**
 * The authorisation code flow against the configured provider, both legs.
 *
 * begin() sends the browser to the provider; complete() takes it back, swaps
 * the code for tokens and returns who signed in. Everything that binds the two
 * legs together — state, nonce and the PKCE verifier — is minted in the first
 * and consumed in the second, from the session, single-use.
 *
 * Three values where one might look like enough, because each closes a
 * different door. State stops a forged callback from completing somebody
 * else's sign-in in this browser (login CSRF). The nonce stops an ID token
 * from one sign-in being replayed into another. PKCE stops an intercepted code
 * being redeemed by anyone who did not start the flow — the client secret does
 * that too, but a secret pasted into a compose file is not a secret everyone
 * keeps, and S256 costs nothing.
 *
 * The league client the mail-account flows use, rather than a second OAuth
 * library: it already does PKCE and the code exchange, and what OIDC adds on
 * top — the ID token — is IdTokenValidator's.
 */
final readonly class OidcClient
{
    /**
     * The route the provider sends the browser back to. Named once, here: it
     * is the redirect URI this class hands the provider, and the request
     * App\Security\OidcAuthenticator claims.
     */
    public const string CALLBACK_ROUTE = 'app_login_oidc_callback';

    private const int TIMEOUT_SECONDS = 10;

    private const string SESSION_NAMESPACE = 'oidc_login';
    private const string SESSION_NONCE = 'oidc_login_nonce';
    private const string SESSION_PKCE = 'oidc_login_pkce';

    public function __construct(
        private OidcSettings $settings,
        private OidcDiscovery $discovery,
        private IdTokenValidator $idTokenValidator,
        private OAuthStateStore $stateStore,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The provider's authorisation URL, with this sign-in's secrets stored.
     *
     * @throws OidcLoginFailed
     */
    public function begin(SessionInterface $session): string
    {
        $configuration = $this->settings->current();
        $endpoints     = $this->discovery->endpoints($configuration->issuer);
        $provider      = $this->provider($configuration, $endpoints);
        $nonce         = bin2hex(random_bytes(16));

        $url = $provider->getAuthorizationUrl([
            'scope' => $configuration->scopes,
            'nonce' => $nonce,
        ]);

        // getState() and getPkceCode() are only filled in by the call above,
        // which is why they are read after it.
        $this->stateStore->remember($session, self::SESSION_NAMESPACE, $provider->getState());
        $session->set(self::SESSION_NONCE, $nonce);
        $session->set(self::SESSION_PKCE, $provider->getPkceCode());

        return $url;
    }

    /**
     * @throws OidcLoginFailed
     */
    public function complete(Request $request): OidcIdentity
    {
        $session = $request->getSession();

        // Read and forgotten before anything can fail, so that no outcome —
        // refusal, exception, success — leaves a nonce or verifier behind for
        // the next callback to match against.
        $expectedState = $this->stateStore->consume($session, self::SESSION_NAMESPACE)['state'];
        $nonce         = $session->get(self::SESSION_NONCE);
        $pkce          = $session->get(self::SESSION_PKCE);
        $session->remove(self::SESSION_NONCE);
        $session->remove(self::SESSION_PKCE);

        // The state first, before anything else in the query is believed. A
        // callback is a GET anybody can link to, and until the state matches
        // there is nothing to say this one answers a sign-in this browser
        // started: read `error` first, and a forged link both writes its
        // author's text into the log and tells the person the provider turned
        // them away.
        $state = $request->query->get('state');

        if (false === is_string($expectedState) || false === is_string($state) || false === hash_equals($expectedState, $state)) {
            throw new OidcLoginFailed('oidc.failed', 'The callback state does not match the one this session sent');
        }

        // The person declined at the provider, or the provider refused them —
        // Authentik answers `access_denied` when the application's policy
        // does not let this user in. An error response carries the state like
        // any other (RFC 6749 § 4.1.2.1), which is why it can wait for it.
        $error = $request->query->get('error');

        if (null !== $error) {
            throw new OidcLoginFailed('oidc.denied', sprintf(
                'The provider returned "%s": %s',
                $error,
                (string) $request->query->get('error_description', ''),
            ));
        }

        if (false === is_string($nonce) || false === is_string($pkce)) {
            throw new OidcLoginFailed('oidc.failed', 'The session holds no nonce or PKCE verifier for this sign-in');
        }

        $code = $request->query->get('code');

        if (null === $code || '' === $code) {
            throw new OidcLoginFailed('oidc.failed', 'The callback carries no authorisation code');
        }

        $configuration = $this->settings->current();
        $endpoints     = $this->discovery->endpoints($configuration->issuer);
        $provider      = $this->provider($configuration, $endpoints);
        $provider->setPkceCode($pkce);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException | GuzzleException | UnexpectedValueException $e) {
            throw new OidcLoginFailed('oidc.failed', 'The token exchange failed: ' . $e->getMessage(), $e);
        }

        $idToken = $token instanceof AccessToken ? ($token->getValues()['id_token'] ?? null) : null;

        if (false === is_string($idToken)) {
            throw new OidcLoginFailed('oidc.failed', 'The token response carries no ID token — is "openid" among the scopes the provider allows?');
        }

        $claims = $this->idTokenValidator->validate(
            $idToken,
            $endpoints['issuer'],
            $configuration->clientId,
            $nonce,
            $this->clock->now()->getTimestamp(),
        );

        return OidcIdentity::fromClaims($endpoints['issuer'], $this->withUserinfo($provider, $endpoints, $token, $claims));
    }

    /**
     * The ID token's claims, completed from the userinfo endpoint.
     *
     * Asked because providers differ in what they put in the ID token: many
     * leave the address and the name out of it unless configured otherwise,
     * and serve them only from userinfo. The ID token's own `sub` is kept and
     * userinfo's must agree with it (OpenID Connect Core § 5.3.2) — a
     * response about somebody else is not a detail to merge.
     *
     * Best effort: a provider with no userinfo endpoint, or one that fails,
     * leaves the ID token's claims as they were rather than refusing a
     * sign-in that may need nothing more.
     *
     * @param array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: ?string} $endpoints
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private function withUserinfo(AbstractProvider $provider, array $endpoints, AccessToken $token, array $claims): array
    {
        if (null === $endpoints['userinfo_endpoint']) {
            return $claims;
        }

        try {
            $userinfo = $provider->getResourceOwner($token)->toArray();
        } catch (IdentityProviderException | GuzzleException | UnexpectedValueException $e) {
            // Said, because what follows from it is usually a refusal for want
            // of an address — and that one blames the scopes.
            $this->logger->notice('Single sign-on: the userinfo endpoint did not answer, continuing on the ID token alone', ['exception' => $e]);

            return $claims;
        }

        if (($userinfo['sub'] ?? null) !== $claims['sub']) {
            throw new OidcLoginFailed('oidc.failed', 'The userinfo endpoint answered for a different subject than the ID token');
        }

        // Userinfo wins for the profile — it is the provider's current view,
        // where an ID token's copy is whatever was mapped into it. `sub` is
        // the same in both by the check above.
        return [...$claims, ...$userinfo];
    }

    /**
     * @param array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: ?string} $endpoints
     */
    private function provider(OidcConfiguration $configuration, array $endpoints): GenericProvider
    {
        return new GenericProvider([
            'clientId'                => $configuration->clientId,
            'clientSecret'            => $configuration->clientSecret,
            'redirectUri'             => $this->urlGenerator->generate(
                self::CALLBACK_ROUTE,
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'urlAuthorize'            => $endpoints['authorization_endpoint'],
            'urlAccessToken'          => $endpoints['token_endpoint'],
            'urlResourceOwnerDetails' => $endpoints['userinfo_endpoint'] ?? '',
            'responseResourceOwnerId' => 'sub',
            // The league default is a comma, which OAuth 2.0 § 3.3 does not
            // allow; providers that read it strictly see one unknown scope.
            'scopeSeparator'          => ' ',
            'pkceMethod'              => AbstractProvider::PKCE_METHOD_S256,
        ], [
            // A client of our own, for the one option the league provider does
            // not pass through: no redirects. The ID token is believed because
            // of the TLS connection to the endpoint the discovery document
            // named (see IdTokenValidator), and a redirect is an answer from
            // somewhere else — over plain http, if that is where it points.
            'httpClient' => new Client(['timeout' => self::TIMEOUT_SECONDS, 'allow_redirects' => false]),
        ]);
    }
}
