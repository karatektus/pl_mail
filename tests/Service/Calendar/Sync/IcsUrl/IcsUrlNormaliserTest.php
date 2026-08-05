<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\IcsUrl;

use App\Domain\Exception\IntegrationException;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a pasted calendar address becomes, and what it is never allowed to
 * become.
 *
 * Two claims, and they are the same class because doing either without the
 * other is a bug.
 *
 * **webcal:// is fetched, not refused.** It is what every "Subscribe" button
 * copies to the clipboard — Google, Outlook, Fastmail, every fixture-list site
 * — and no client has ever spoken a protocol by that name. A form that rejected
 * it would reject the address the user was handed.
 *
 * **The address is refused when it points inside the deployment.** The feed is
 * fetched server-side, on a schedule, from a container that can reach Postgres,
 * Mercure and the workers, so a user-supplied address is an SSRF surface. The
 * check is IntegrationUrlValidator's rather than a second one written here, and
 * the test uses the real validator for exactly that reason: "an internal address
 * is refused" is a claim about the code as it ships, and a doubled validator
 * would assert it into existence.
 */
final class IcsUrlNormaliserTest extends TestCase
{
    private IcsUrlNormaliser $normaliser;

    protected function setUp(): void
    {
        $this->normaliser = new IcsUrlNormaliser(new IntegrationUrlValidator());
    }

    /**
     * https rather than http, although the original webcal note implied http:
     * mapping to http would hand the address to the validator's plaintext
     * refusal, so a user pasting the link their calendar gave them would be told
     * to ask an administrator for permission to use plain http.
     */
    #[DataProvider('webcalAddresses')]
    public function testWebcalBecomesHttpsRatherThanBeingRefused(string $pasted, string $expected): void
    {
        self::assertSame($expected, $this->normaliser->normalise($pasted));
    }

    /** @return iterable<string, array{string, string}> */
    public static function webcalAddresses(): iterable
    {
        yield 'lower case' => [
            'webcal://example.com/cal.ics',
            'https://example.com/cal.ics',
        ];

        yield 'upper case, which is what some clients copy' => [
            'WEBCAL://example.com/cal.ics',
            'https://example.com/cal.ics',
        ];

        yield 'the secure spelling, which means the same thing' => [
            'webcals://example.com/cal.ics',
            'https://example.com/cal.ics',
        ];

        yield 'https is left exactly as it arrived' => [
            'https://example.com/cal.ics',
            'https://example.com/cal.ics',
        ];

        yield 'surrounding whitespace, which a paste routinely carries' => [
            "  webcal://example.com/cal.ics\n",
            'https://example.com/cal.ics',
        ];
    }

    /**
     * The scheme is replaced by prefix and not by a pattern over the whole URL.
     *
     * A feed address routinely carries a redirect or callback parameter with its
     * own `://` inside it, and a rule that matched anywhere would rewrite that
     * instead of the scheme — producing an address that is subtly not the one
     * the publisher gave out.
     */
    public function testOnlyTheSchemeIsRewrittenAndNotAnAddressInsideTheQuery(): void
    {
        self::assertSame(
            'https://example.com/cal.ics?next=webcal://other.example/x.ics',
            $this->normaliser->normalise('webcal://example.com/cal.ics?next=webcal://other.example/x.ics'),
        );
    }

    /**
     * The SSRF refusal, over the addresses that actually get tried. 169.254.169.254
     * is the cloud metadata endpoint and is the reason this check is not
     * optional; localhost is the reason it is not enough to check the scheme.
     */
    #[DataProvider('refusedAddresses')]
    public function testAnInternalAddressIsRefusedRatherThanFetched(string $address): void
    {
        $this->expectException(IntegrationException::class);

        $this->normaliser->normalise($address);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedAddresses(): iterable
    {
        yield 'the machine itself'          => ['https://localhost/cal.ics'];
        yield 'loopback by address'         => ['https://127.0.0.1/cal.ics'];
        yield 'IPv6 loopback'               => ['https://[::1]/cal.ics'];
        yield 'a private range'             => ['https://10.1.2.3/cal.ics'];
        yield 'the cloud metadata endpoint' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'a scheme that is not http'   => ['file:///etc/passwd'];
        yield 'no scheme at all'            => ['example.com/cal.ics'];
        yield 'credentials in the address'  => ['https://user:pass@example.com/cal.ics'];
        yield 'the same, wearing webcal'    => ['webcal://127.0.0.1/cal.ics'];
    }

    /**
     * isFetchable() answers the same question without the sentence, for the
     * caller that only needs to know whether to draw a field error.
     */
    public function testIsFetchableAgreesWithNormalise(): void
    {
        self::assertTrue($this->normaliser->isFetchable('webcal://example.com/cal.ics'));
        self::assertFalse($this->normaliser->isFetchable('https://127.0.0.1/cal.ics'));
    }

    /**
     * The connection's fallback name, needed before anything has been fetched:
     * Integration is unique on (usr, provider, name), so every unnamed feed
     * being called the same thing makes the second one a constraint violation —
     * which arrives at the user as a 500 on a form they filled in correctly.
     */
    #[DataProvider('namedAddresses')]
    public function testSuggestedNameIsSomethingAPersonRecognises(string $address, string $expected): void
    {
        self::assertSame($expected, $this->normaliser->suggestedName($address));
    }

    /** @return iterable<string, array{string, string}> */
    public static function namedAddresses(): iterable
    {
        yield 'the file, without its extension' => [
            'https://example.com/feeds/feiertage-deutschland.ics',
            'feiertage-deutschland',
        ];

        yield 'percent-encoding is decoded, so the name reads' => [
            'https://example.com/Team%20calendar.ics',
            'Team calendar',
        ];

        yield 'no path at all falls back to the host' => [
            'https://calendar.example.com',
            'calendar.example.com',
        ];
    }
}
