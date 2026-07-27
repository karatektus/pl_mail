<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates app passwords on the JMAP firewall, in both shapes real
 * clients send:
 *
 *   Authorization: Bearer plmail_xxx…            (ltt.rs)
 *   Authorization: Basic base64(email:plmail_xxx…)  (Sterna, and most
 *                                                    IMAP-era clients)
 *
 * It shares the firewall with the JWT authenticator, so supports() has to be
 * exact about which requests are ours: Basic always is (JWT has no Basic
 * form), and Bearer only when the credential carries the ApiToken prefix. A
 * JWT is base64url and starts "ey", so the two can never be confused.
 *
 * The Basic username is verified against the token's owner rather than
 * ignored — a client that sends the wrong address gets told so, instead of
 * silently operating as whoever the token belongs to.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /**
     * How stale lastUsedAt may get before it is rewritten. Without this every
     * single JMAP call would issue a write, and clients poll constantly.
     */
    private const int LAST_USED_TTL_SECONDS = 300;

    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $header = $request->headers->get('Authorization');

        if (null === $header) {
            return false;
        }

        if (true === str_starts_with($header, 'Basic ')) {
            return true;
        }

        return str_starts_with($header, 'Bearer '.ApiToken::PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization');
        [$secret, $username] = $this->extract($header);

        if (null === $secret) {
            throw new CustomUserMessageAuthenticationException('Malformed Authorization header.');
        }

        $token = $this->tokenRepository->findActiveBySecret($secret);

        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked app password.');
        }

        $identifier = $token->usr->getUserIdentifier();

        if (null !== $username && false === hash_equals($identifier, $username)) {
            throw new CustomUserMessageAuthenticationException('The app password does not belong to that address.');
        }

        $this->touch($token);

        // SelfValidatingPassport: possession of the secret IS the proof, and
        // the user was just loaded by it — no second credential to check.
        return new SelfValidatingPassport(new UserBadge($identifier, static fn (): object => $token->usr));
    }

    /**
     * @return array{0:?string,1:?string} the secret, and the username when the
     *                                    client sent one (Basic only)
     */
    private function extract(string $header): array
    {
        if (true === str_starts_with($header, 'Bearer ')) {
            return [substr($header, 7), null];
        }

        if (false === str_starts_with($header, 'Basic ')) {
            return [null, null];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if (false === $decoded || false === str_contains($decoded, ':')) {
            return [null, null];
        }

        [$username, $secret] = explode(':', $decoded, 2);

        return [$secret, $username];
    }

    /**
     * Throttled so a polling client does not turn every read into a write.
     */
    private function touch(ApiToken $token): void
    {
        $now = new \DateTimeImmutable();
        $lastUsed = $token->lastUsedAt;

        if (null !== $lastUsed && $now->getTimestamp() - $lastUsed->getTimestamp() < self::LAST_USED_TTL_SECONDS) {
            return;
        }

        $token->lastUsedAt = $now;
        $this->entityManager->flush();
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->challenge($exception->getMessageKey());
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->challenge('Authentication required.');
    }

    /**
     * WWW-Authenticate matters here: Basic-only clients rely on the challenge
     * to know which realm to prompt for.
     */
    private function challenge(string $message): Response
    {
        $response = new JsonResponse(
            [
                'type' => 'urn:ietf:params:jmap:error:unauthorized',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => $message,
            ],
            Response::HTTP_UNAUTHORIZED,
        );

        $response->headers->set('WWW-Authenticate', 'Basic realm="plMail JMAP", charset="UTF-8"');
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
