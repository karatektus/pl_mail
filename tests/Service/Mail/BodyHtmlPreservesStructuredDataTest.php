<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MailBodySanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Extraction reads schema.org markup out of bodyHtml, and it only survives
 * there because the sanitizer writes to bodyHtmlSafe and never back.
 *
 * That was an implementation detail and is now load-bearing. Google created
 * email markup for exactly this — flights, parcels, bookings — and it is why
 * Gmail is good at "Happening Soon" without a model anywhere near it. The
 * markup lives in <script type="application/ld+json">, which the sanitizer
 * strips from the safe copy quite correctly, since a script tag is the last
 * thing that should reach a rendered message.
 *
 * So the two copies have to keep meaning different things. A perfectly
 * reasonable future tidy-up — "why do we store the unsanitised body twice?" —
 * would delete half the extraction pipeline and nothing would fail. This is
 * what fails instead.
 */
final class BodyHtmlPreservesStructuredDataTest extends KernelTestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    private const string JSON_LD = '<script type="application/ld+json">'
        . '{"@context":"https://schema.org","@type":"FlightReservation",'
        . '"reservationNumber":"AB1234"}'
        . '</script>';

    public function testTheRawBodyKeepsItsStructuredData(): void
    {
        $message = $this->sanitize('<p>Your flight</p>' . self::JSON_LD);

        self::assertStringContainsString(
            'FlightReservation',
            (string) $message->bodyHtml,
            'bodyHtml is the extractor\'s input and must not be rewritten',
        );
        self::assertStringContainsString('AB1234', (string) $message->bodyHtml);
    }

    public function testTheSafeBodyDropsIt(): void
    {
        $message = $this->sanitize('<p>Your flight</p>' . self::JSON_LD);

        self::assertStringNotContainsString('<script', (string) $message->bodyHtmlSafe);
        self::assertStringNotContainsString('FlightReservation', (string) $message->bodyHtmlSafe);
        self::assertStringContainsString('Your flight', (string) $message->bodyHtmlSafe);
    }

    /** Microdata is the other half of the same vocabulary, and inert to boot. */
    public function testMicrodataAttributesSurviveInTheRawBody(): void
    {
        $html = '<div itemscope itemtype="https://schema.org/ParcelDelivery">'
            . '<span itemprop="trackingNumber">JD0002</span></div>';

        $message = $this->sanitize($html);

        self::assertStringContainsString('ParcelDelivery', (string) $message->bodyHtml);
        self::assertStringContainsString('JD0002', (string) $message->bodyHtml);
    }

    /** Sanitising twice must not start eating the raw copy either. */
    public function testSanitisingIsIdempotentForTheRawBody(): void
    {
        $message = $this->sanitize('<p>Your flight</p>' . self::JSON_LD);
        $first   = (string) $message->bodyHtml;

        $this->sanitizer->sanitize($message);

        self::assertSame($first, (string) $message->bodyHtml);
    }

    private function sanitize(string $html): Message
    {
        $message = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return $message;
    }
}
