<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\ApiToken;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stops the JWT authenticator from claiming app passwords.
 *
 * Both credential types share the JMAP firewall and both arrive as
 * "Authorization: Bearer …". Symfony runs *every* authenticator that supports a
 * request, not just the first that succeeds — so without this, an app password
 * would authenticate correctly via ApiTokenAuthenticator and then be
 * overwritten by the JWT authenticator's "Invalid JWT Token" failure response.
 *
 * Decorating the extractor is the narrow fix: Lexik simply never sees a
 * prefixed token, so it declines the request instead of failing it.
 */
final class JwtBearerTokenExtractor implements TokenExtractorInterface
{
    public function __construct(
        private readonly TokenExtractorInterface $inner,
    ) {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        if (false === $token) {
            return false;
        }

        if (true === str_starts_with($token, ApiToken::PREFIX)) {
            return false;
        }

        return $token;
    }
}
