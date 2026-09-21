<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mercure;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;

/**
 * The token plMail publishes with, inspected claim by claim.
 *
 * ── Why this is worth a test of its own ──────────────────────────────────────
 * The hub speaks Mercure 1.0 and refuses an access token missing any one of
 * iss, aud, sub, client_id or exp. The subscriber half of that is loud: nothing
 * arrives, the indicator says "Live updates unavailable", and the browser suite
 * fails. THE PUBLISHER HALF IS SILENT. A publish is fire-and-forget from a
 * worker — SyncNotifier, SendOutcomeNotifier, JobNotifier — so a refused one
 * looks exactly like a quiet mailbox, and the first report is somebody saying
 * new mail "sometimes" does not appear until they reload.
 *
 * `exp` is the claim this is really here for. The component's own docblock says
 * a null lifetime means a NON-EXPIRING token, and a non-expiring token is
 * precisely what a 1.0 hub rejects; the bundle passes 1.0 an automatic lifetime
 * to cover that, and this asserts it rather than trusting the comment. The hub
 * only says so in its log, where nothing looks for it.
 *
 * Read off the real container, not a fixture, because what is being tested is
 * the configuration in config/packages/mercure.yaml.
 */
final class PublisherTokenTest extends KernelTestCase
{
    /** @var list<string> */
    private const array REQUIRED_CLAIMS = ['iss', 'aud', 'sub', 'client_id', 'exp'];

    public function testThePublisherTokenCarriesEveryClaimTheHubDemands(): void
    {
        $claims = $this->publisherClaims();

        foreach (self::REQUIRED_CLAIMS as $claim) {
            self::assertArrayHasKey($claim, $claims, sprintf('a 1.0 hub refuses a token without "%s"', $claim));
            self::assertNotEmpty($claims[$claim], sprintf('"%s" is present but empty', $claim));
        }
    }

    /**
     * The grant itself, in the 1.0 shape. Under 0.x this was a bare `mercure`
     * claim; a hub on 1.0 ignores that entirely, so its absence here would mean
     * a token that authenticates and is allowed to publish nothing.
     */
    public function testThePublisherTokenGrantsPublishingInTheOneZeroShape(): void
    {
        $claims = $this->publisherClaims();

        self::assertArrayHasKey('authorization_details', $claims);

        $details = $claims['authorization_details'];

        self::assertIsArray($details);
        self::assertNotEmpty($details, 'a token granting nothing publishes nothing');
        self::assertContains('publish', $details[0]['actions'] ?? []);
    }

    /**
     * THE TRUENAS CASE, and the reason the identifier is a literal.
     *
     * truenas.compose.yaml leaves `mercure_public_url` blank on purpose: the
     * setup screen asks for the public URL on first boot and stores it in the
     * database, which is long after the hub container has to have been told
     * what to trust. While `iss` and `aud` were derived from that variable,
     * every such install minted nothing at all — the factory refuses an empty
     * `iss` outright — so there was no cookie, no subscription, and every
     * publish threw. It looked exactly like a hub that was simply down.
     *
     * Asserted by emptying the variable rather than by reading the config,
     * because what broke was the DERIVATION, and a test that reads the same
     * placeholder the code does would have passed throughout.
     */
    public function testItStillMintsWhenTheInstallHasNoPublicUrlConfigured(): void
    {
        $_SERVER['MERCURE_PUBLIC_URL'] = '';
        $_ENV['MERCURE_PUBLIC_URL']    = '';

        try {
            $claims = $this->publisherClaims();

            self::assertNotEmpty($claims['iss'] ?? null, 'an empty public URL must not empty the issuer');
            self::assertNotEmpty($claims['aud'] ?? null, 'an empty public URL must not empty the audience');
        } finally {
            unset($_SERVER['MERCURE_PUBLIC_URL'], $_ENV['MERCURE_PUBLIC_URL']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function publisherClaims(): array
    {
        self::bootKernel();

        $hub = self::getContainer()->get(HubInterface::class);
        $jwt = $hub->getProvider()->getJwt();

        $parts = explode('.', $jwt);

        self::assertCount(3, $parts, 'not a JWT');

        $payload = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
        );

        self::assertIsArray($payload, 'the JWT payload did not decode');

        return $payload;
    }
}
