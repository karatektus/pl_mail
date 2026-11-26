<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\SenderPicture\Lookup;

use App\Service\Mail\SenderPicture\Lookup\BimiPictureLookup;
use App\Service\Mail\SenderPicture\SenderPictureHttp;
use App\Tests\Support\Mail\FakeDns;
use App\Tests\Support\Mail\SenderPictureFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A BIMI logo is fetched only for a domain that has earned one (#639).
 *
 * The rule BIMI itself sets, and the one a careless version would skip: no
 * logo without a DMARC policy that tells receivers to refuse forgeries. A
 * domain at p=none would have its logo worn by every forged mail a receiver
 * let through. The display half — only on a message that passed — is
 * SenderPicturesTest's.
 */
final class BimiPictureLookupTest extends TestCase
{
    private const string LOGO = 'https://brand.example.com/logo.svg';

    public function testAnEnforcingDomainsLogoIsFetched(): void
    {
        $requests = [];
        $lookup   = $this->lookup(
            ['_dmarc.example.com' => ['v=DMARC1; p=reject'], 'default._bimi.example.com' => ['v=BIMI1; l=' . self::LOGO . '; a=']],
            $requests,
        );

        $picture = $lookup->lookup('example.com');

        self::assertNotNull($picture);
        self::assertSame('image/svg+xml', $picture->contentType);
        self::assertSame(self::LOGO, $picture->origin);
        self::assertSame([self::LOGO], $requests);
    }

    public function testADomainThatOnlyMonitorsGetsNoLogoAndIsNotFetched(): void
    {
        $requests = [];
        $lookup   = $this->lookup(
            ['_dmarc.example.com' => ['v=DMARC1; p=none'], 'default._bimi.example.com' => ['v=BIMI1; l=' . self::LOGO]],
            $requests,
        );

        self::assertNull($lookup->lookup('example.com'));
        self::assertSame([], $requests, 'nothing may be fetched for a domain without an enforced policy');
    }

    public function testAPolicyAppliedToPartOfTheMailIsNotEnforcement(): void
    {
        $requests = [];
        $lookup   = $this->lookup(
            ['_dmarc.example.com' => ['v=DMARC1; p=quarantine; pct=20'], 'default._bimi.example.com' => ['v=BIMI1; l=' . self::LOGO]],
            $requests,
        );

        self::assertNull($lookup->lookup('example.com'));
    }

    public function testASubdomainFallsBackToTheOrganisationsRecordsAndItsSubdomainPolicy(): void
    {
        $requests = [];
        $lookup   = $this->lookup(
            ['_dmarc.example.com' => ['v=DMARC1; p=none; sp=reject'], 'default._bimi.example.com' => ['v=BIMI1; l=' . self::LOGO]],
            $requests,
        );

        self::assertNotNull($lookup->lookup('news.example.com'));
    }

    public function testAnEmptyLocationIsADomainDecliningALogo(): void
    {
        $requests = [];
        $lookup   = $this->lookup(
            ['_dmarc.example.com' => ['v=DMARC1; p=reject'], 'default._bimi.example.com' => ['v=BIMI1; l=;']],
            $requests,
        );

        self::assertNull($lookup->lookup('example.com'));
        self::assertSame([], $requests);
    }

    /**
     * @param array<string, list<string>> $txt
     * @param list<string>                $requests filled with every URL asked for
     */
    private function lookup(array $txt, array &$requests): BimiPictureLookup
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse(SenderPictureFixtures::svg(), ['response_headers' => ['content-type' => 'image/svg+xml']]);
        });

        return new BimiPictureLookup(new SenderPictureHttp($client, new NullLogger()), new FakeDns($txt));
    }
}
