<?php

declare(strict_types=1);

namespace App\Service\Integration\BrowserLogin;

use App\Domain\DTO\Integration\BrowserLoginStart;
use App\Domain\DTO\Integration\ObtainedCredential;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\BrowserLoginFlowInterface;
use App\Service\Integration\Driver\HttpLimits;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nextcloud's Login Flow v2 (#235): the person logs in and approves in their
 * own browser, 2FA and SSO included, and Nextcloud hands plMail an app password.
 *
 *   POST {base}/index.php/login/v2           → {poll: {token, endpoint}, login}
 *   POST {endpoint} with token=…             → 404 until approved, then once
 *                                              {server, loginName, appPassword}
 *
 * The result is the same app password a person would create under Settings →
 * Security and paste, so the stored connection is indistinguishable from a
 * manual one, and revoking it in Nextcloud works the same way.
 *
 * `server` from the answer is not used. The stored address stays the one
 * plMail started from, which the admin pinned or IntegrationUrlValidator
 * allowed — a server naming some other address must not be able to move the
 * connection there. For the same reason the login and poll addresses must be
 * on the start address's origin; a reverse proxy that rewrites them to another
 * host gets a message saying so, and the app password form still works.
 *
 * Available since Nextcloud 16. An older server answers the start with a 404,
 * which is reported as such rather than as a wrong address.
 */
final readonly class NextcloudLoginFlow implements BrowserLoginFlowInterface
{
    private const string START_PATH = '/index.php/login/v2';

    /**
     * What Nextcloud names the app password after, in the approval screen and
     * under Devices & sessions — where the person later finds it to revoke.
     */
    private const string USER_AGENT = 'plMail';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::Nextcloud === $provider;
    }

    public function start(string $baseUrl): BrowserLoginStart
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').self::START_PATH, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'application/json',
                ],
            ] + HttpLimits::CALL);

            $status = $response->getStatusCode();

            if (404 === $status) {
                throw new IntegrationException('This Nextcloud does not offer browser approval (it needs Nextcloud 16 or later). Use an app password instead.');
            }

            if (200 !== $status) {
                throw new IntegrationException(sprintf('Nextcloud did not start the browser approval (%d).', $status));
            }

            $payload = $response->toArray(false);
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw new IntegrationException('Could not reach the Nextcloud server. Check the address.', 0, $e);
        }

        $login = $payload['login'] ?? null;
        $token = $payload['poll']['token'] ?? null;
        $endpoint = $payload['poll']['endpoint'] ?? null;

        if (false === is_string($login) || false === is_string($token) || false === is_string($endpoint)
            || '' === $token) {
            throw new IntegrationException('Nextcloud answered the browser approval with something we could not read.');
        }

        if (false === $this->sameOrigin($baseUrl, $login) || false === $this->sameOrigin($baseUrl, $endpoint)) {
            throw new IntegrationException('Nextcloud sent the approval to a different address than the one you connect to. Check its overwrite settings, or use an app password instead.');
        }

        return new BrowserLoginStart($login, ['token' => $token, 'endpoint' => $endpoint]);
    }

    public function poll(string $baseUrl, array $state): ?ObtainedCredential
    {
        $token = $state['token'] ?? '';
        $endpoint = $state['endpoint'] ?? '';

        // Checked again although start() checked it: the state came back out
        // of a cache, and the cost of being wrong is posting a live token to
        // another host.
        if ('' === $token || false === $this->sameOrigin($baseUrl, $endpoint)) {
            throw new IntegrationException('This approval can no longer be completed. Start again.');
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'application/json',
                ],
                'body' => ['token' => $token],
            ] + HttpLimits::CALL);

            $status = $response->getStatusCode();

            // Not approved yet, and also what an expired or already-collected
            // token answers. Nextcloud does not tell those apart; the 20 minute
            // limit on the state does it for us.
            if (404 === $status) {
                return null;
            }

            if (200 !== $status) {
                throw new IntegrationException(sprintf('Nextcloud refused the browser approval (%d).', $status));
            }

            $payload = $response->toArray(false);
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw new IntegrationException('Could not reach the Nextcloud server. Check the address.', 0, $e);
        }

        $loginName = $payload['loginName'] ?? null;
        $appPassword = $payload['appPassword'] ?? null;

        if (false === is_string($loginName) || '' === $loginName
            || false === is_string($appPassword) || '' === $appPassword) {
            throw new IntegrationException('Nextcloud answered the browser approval with something we could not read.');
        }

        return new ObtainedCredential($loginName, $appPassword);
    }

    /**
     * Same scheme, host and port. A path may differ — Nextcloud in a
     * subdirectory answers with addresses under it — but nothing else may.
     */
    private function sameOrigin(string $a, string $b): bool
    {
        $left = parse_url($a);
        $right = parse_url($b);

        if (false === $left || false === $right || false === isset($left['host'], $right['host'])) {
            return false;
        }

        $scheme = strtolower($left['scheme'] ?? '');
        $defaultPort = 'https' === $scheme ? 443 : 80;

        return $scheme === strtolower($right['scheme'] ?? '')
            && strtolower($left['host']) === strtolower($right['host'])
            && ($left['port'] ?? $defaultPort) === ($right['port'] ?? $defaultPort);
    }
}
