<?php

declare(strict_types=1);

namespace App\Tests\Service\Oidc;

use App\Service\Oidc\IdTokenValidator;
use App\Service\Oidc\OidcLoginFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An ID token is accepted only when it was minted by the configured provider,
 * for this client, for this sign-in, and recently.
 *
 * Every refusal below is a way somebody else's token could otherwise sign a
 * person in here: one the provider issued to another application (audience),
 * one issued by another provider (issuer), one replayed from an earlier
 * sign-in (nonce), one kept past its life (expiry). The signature is not among
 * them, by design — IdTokenValidator says why — so these claims are the whole
 * of the check, and each has to be able to fail on its own.
 */
final class IdTokenValidatorTest extends TestCase
{
    private const string ISSUER = 'https://auth.example.com/application/o/plmail/';
    private const string CLIENT = 'plmail-client';
    private const string NONCE = 'a-nonce-for-this-sign-in';
    private const int NOW = 1_800_000_000;

    public function testAValidTokenYieldsItsClaims(): void
    {
        $claims = (new IdTokenValidator())->validate($this->token([]), self::ISSUER, self::CLIENT, self::NONCE, self::NOW);

        self::assertSame('subject-1', $claims['sub']);
        self::assertSame('jo@example.com', $claims['email']);
    }

    public function testAnAudienceListIncludingThisClientIsAcceptedWhenItIsTheAuthorisedParty(): void
    {
        $token = $this->token(['aud' => [self::CLIENT, 'another'], 'azp' => self::CLIENT]);

        $claims = (new IdTokenValidator())->validate($token, self::ISSUER, self::CLIENT, self::NONCE, self::NOW);

        self::assertSame('subject-1', $claims['sub']);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusals')]
    public function testARefusal(array $override): void
    {
        $this->expectException(OidcLoginFailed::class);

        (new IdTokenValidator())->validate($this->token($override), self::ISSUER, self::CLIENT, self::NONCE, self::NOW);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusals(): iterable
    {
        yield 'another issuer'                           => [['iss' => 'https://evil.example.com/']];
        yield 'the issuer without its trailing slash'    => [['iss' => rtrim(self::ISSUER, '/')]];
        yield 'another audience'                         => [['aud' => 'another-client']];
        yield 'several audiences, another party'         => [['aud' => [self::CLIENT, 'another'], 'azp' => 'another']];
        yield 'several audiences, no authorised party'   => [['aud' => [self::CLIENT, 'another']]];
        yield 'expired beyond the leeway'                => [['exp' => self::NOW - 61]];
        yield 'no expiry'                                => [['exp' => null]];
        yield 'another nonce'                            => [['nonce' => 'replayed-from-elsewhere']];
        yield 'no nonce'                                 => [['nonce' => null]];
        yield 'no subject'                               => [['sub' => null]];
        yield 'an empty subject'                         => [['sub' => '']];
    }

    public function testExpiryWithinTheLeewayIsForgiven(): void
    {
        $claims = (new IdTokenValidator())->validate($this->token(['exp' => self::NOW - 30]), self::ISSUER, self::CLIENT, self::NONCE, self::NOW);

        self::assertSame('subject-1', $claims['sub']);
    }

    public function testSomethingThatIsNotAJwtIsRefused(): void
    {
        $this->expectException(OidcLoginFailed::class);

        (new IdTokenValidator())->validate('not-a-token', self::ISSUER, self::CLIENT, self::NONCE, self::NOW);
    }

    /**
     * An unsigned-looking JWT with the given claims replacing the defaults; a
     * null removes the claim.
     *
     * @param array<string, mixed> $override
     */
    private function token(array $override): string
    {
        $claims = array_filter([
            'iss'   => self::ISSUER,
            'aud'   => self::CLIENT,
            'sub'   => 'subject-1',
            'exp'   => self::NOW + 300,
            'iat'   => self::NOW,
            'nonce' => self::NONCE,
            'email' => 'jo@example.com',
            ...$override,
        ], static fn (mixed $value): bool => null !== $value);

        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($claims) . '.c2lnbmF0dXJl';
    }
}
