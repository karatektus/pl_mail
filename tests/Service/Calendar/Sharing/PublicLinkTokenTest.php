<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sharing;

use App\Service\Calendar\Sharing\PublicLinkToken;
use PHPUnit\Framework\TestCase;

/**
 * The secret in a public calendar URL is stored as a digest, never as itself.
 *
 * The same claim DevicePairingServiceTest makes with
 * testTheCacheKeyIsADigestNotTheCodeItself, and it matters more here rather than
 * less: a pairing code is dead in two minutes, and a share link is alive until
 * somebody revokes it. Anything that can read one row of calendar_share_link —
 * a laptop backup, a dump on somebody else's storage, a read gained through an
 * unrelated hole — would otherwise walk away with a working URL into a diary and
 * keep it indefinitely.
 *
 * A plain TestCase rather than a KernelTestCase: this class takes no
 * collaborators, touches no database and has no configuration. There is nothing
 * for a container to contribute, and booting one to test two pure functions is
 * a second of every suite run spent on nothing.
 *
 * The entropy claim is asserted on the encoded length rather than by counting
 * distinct outputs, which is the only honest thing a test can do about a CSPRNG:
 * a statistical check would be a slow test that fails at random, and a check
 * that two mints differ proves nothing a broken generator could not also pass.
 * What can be pinned is that the token is as long as 32 bytes of base64url, and
 * that it fits the pattern the routes will accept — which is the failure that
 * would otherwise show up as a freshly minted link 404ing.
 */
final class PublicLinkTokenTest extends TestCase
{
    private PublicLinkToken $tokens;

    protected function setUp(): void
    {
        $this->tokens = new PublicLinkToken();
    }

    public function testTheStoredValueIsADigestNotTheTokenItself(): void
    {
        $token = $this->tokens->mint();

        $digest = $this->tokens->digest($token);

        self::assertNotSame($token, $digest, 'the stored value is the token itself');
        self::assertSame(hash('sha256', $token), $digest);
        self::assertStringNotContainsString($token, $digest, 'the token survives inside what is stored');
    }

    /**
     * A digest is what reaches the query, whatever arrived in the path.
     *
     * The public routes hand this whatever is in the URL, so it has to answer
     * 64 hex characters for hostile input as reliably as for a real token —
     * that is what makes "the token never reaches a repository" true rather
     * than aspirational.
     */
    public function testAnythingAtAllHashesToSixtyFourHexCharacters(): void
    {
        foreach (['', "'; DROP TABLE calendar_share_link; --", str_repeat('x', 10000), "\x00\xff"] as $hostile) {
            $digest = $this->tokens->digest($hostile);

            self::assertSame(64, strlen($digest));
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        }
    }

    /**
     * The mint and the route requirement have to agree, or every link is dead
     * on arrival — a 404 on a URL that was created a second ago, with nothing
     * in any log to say why.
     */
    public function testAMintedTokenMatchesTheRoutePatternThatWillCarryIt(): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $token = $this->tokens->mint();

            self::assertSame(43, strlen($token), '32 bytes of base64url is 43 characters with the padding stripped');
            self::assertMatchesRegularExpression(
                '/^' . PublicLinkToken::ROUTE_PATTERN . '$/',
                $token,
                'a minted token would not survive its own route requirement',
            );
        }
    }

    /** The same token always resolves to the same row; a digest that drifted would 404 every link. */
    public function testTheDigestIsStableForOneToken(): void
    {
        $token = $this->tokens->mint();

        self::assertSame($this->tokens->digest($token), $this->tokens->digest($token));
    }
}
