<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * Who the provider says signed in, once every claim has been checked.
 */
final readonly class OidcIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $givenName,
        public ?string $familyName,
        public ?string $name,
    ) {
    }

    /**
     * @param array<string, mixed> $claims the ID token's, merged with userinfo's
     */
    public static function fromClaims(string $issuer, array $claims): self
    {
        $string = static fn (mixed $value): ?string => is_string($value) && '' !== trim($value) ? trim($value) : null;

        // A boolean by the specification, and a string "true" in the wild:
        // some providers serialise every claim from a template. Anything else
        // — absent, null, "false", 1 — is not a promise the address is theirs.
        $verified = $claims['email_verified'] ?? null;

        return new self(
            issuer: $issuer,
            subject: (string) $claims['sub'],
            email: $string($claims['email'] ?? null),
            emailVerified: true === $verified || 'true' === $verified,
            givenName: $string($claims['given_name'] ?? null),
            familyName: $string($claims['family_name'] ?? null),
            name: $string($claims['name'] ?? null),
        );
    }
}
